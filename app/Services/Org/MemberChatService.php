<?php

namespace App\Services\Org;

use App\Models\AssistantTask;
use App\Models\MemberChat;
use App\Models\MemberMessage;
use App\Models\OrgMember;
use App\Models\OrgProposal;
use App\Services\GeneratorService;
use App\Services\KnowledgeService;
use App\Services\Org\Billing\InsufficientCreditsException;
use App\Support\LlmUsage;

/**
 * Чат с всеки член (§12) — по модела на BuilderAssistantService. Персона-консистентен
 * (system prompt = персоната на члена), scope-нат по роля, може да ПРЕДЛОЖИ действие →
 * влиза в Кутията (org_proposals). Billable контекст member_chat (reserve/settle в job-а).
 */
class MemberChatService
{
    public function __construct(
        private PersonaService $personas,
        private GeneratorService $generator,
        private KnowledgeService $knowledge,
        private MemberMemoryService $memberMemory,
    ) {}

    /**
     * @return array{reply: string, proposal: ?array, cost_usd: ?float}
     */
    public function turn(MemberChat $chat, MemberMessage $userMessage, ?callable $onStage = null): array
    {
        $onStage && $onStage('Мисля…');
        $member = $chat->orgMember;
        $company = $chat->company;

        $persona = $this->personas->compileSystemPrompt($member);
        $policy = $this->personas->runtimePolicy($member);
        $scope = $this->scopeFor($member);
        $knowledgeBlock = '';
        try {
            $knowledgeBlock = $this->knowledge->knowledgeBlock($company, (string) $userMessage->content, ['company_id' => $company->id]);
        } catch (\Throwable) {
        }

        // Памет per член (§7.2): поуки от миналите му runs (owner-scope, преживява реорганизациите).
        $reflection = $this->memberMemory->reflectionBlock($member);

        $system = trim($persona."\n\n".$scope."\n\n"
            .'Отговаряй в своя тон, по същество, на български. Може да ПРЕДЛОЖИШ действие '
            .'(нова задача/кампания/наемане) — системата ще го обработи според политиката на служителя '
            .'за одобрение и разрешените действия. '
            .'При висок риск и автономност предлагай по-смело конкретни действия; при ниски стойности '
            .'предлагай действие само когато ползата е ясна. '
            .'Връщай САМО валиден JSON по схемата.'
            .($reflection !== '' ? "\n\n".$reflection : '')
            .($knowledgeBlock !== '' ? "\n\n".$knowledgeBlock : ''));

        $history = $this->history($chat, $userMessage);

        $raw = $this->generator->chatJson($system, $history, 'member_chat', $this->schema(), [
            'temperature' => (float) ($policy['temperature'] ?? 0.6),
            'num_predict' => 1500,
        ]);

        $cost = LlmUsage::take()['cost_usd'] ?? null;
        $reply = trim((string) ($raw['reply'] ?? '')) ?: '…';
        $proposal = $this->normalizeProposal($raw['proposal'] ?? null, $member);

        // Предложение → Кутията: задачите директно като AssistantTask+draft
        // (ревизиран §6.1), структурните като durable org_proposal (§A7).
        if ($proposal) {
            $this->persistProposal($company, $proposal);
        }

        return ['reply' => $reply, 'proposal' => $proposal, 'cost_usd' => $cost];
    }

    /** Рутиране на предложението: task → AssistantTask+генерация; иначе → OrgProposal. */
    private function persistProposal($company, array $proposal): void
    {
        if (($proposal['type'] ?? null) === 'task') {
            $this->proposeTask($company, $proposal);

            return;
        }

        OrgProposal::create([
            'company_id' => $company->id,
            'type' => $proposal['type'],
            'payload' => $proposal,
            'base_org_version_id' => $company->active_org_version_id,
        ]);
    }

    /**
     * Предложена в чата задача → AssistantTask(proposed) + асинхронна генерация
     * (→ pending_approval с brief). Нужен е валиден асистент-собственик; недостиг на
     * кредити → задачата остава proposed (генерира се по-късно ръчно).
     */
    private function proposeTask($company, array $proposal): void
    {
        $ownerId = $proposal['org_member_id'] ?? null;
        $owner = $ownerId ? OrgMember::with('persona')
            ->where('company_id', $company->id)
            ->whereKey($ownerId)->where('kind', 'assistant')->first() : null;
        if (! $owner) {
            return;   // няма на кого да възложим задачата
        }
        $policy = $this->personas->runtimePolicy($owner);

        try {
            $actMode = $proposal['act_mode'] ?? 'draft';
            $task = AssistantTask::create([
                'org_member_id' => $owner->id,
                'title' => (string) $proposal['title'],
                'description' => (string) ($proposal['description'] ?? $proposal['title']),
                'act_mode' => in_array($actMode, ['draft', 'act', 'mixed'], true) ? $actMode : 'draft',
                'approval_policy' => (string) ($policy['approval_policy'] ?? 'approve_each'),
                'trigger' => 'manual',
                'status' => 'proposed',
            ]);

            app(TaskRunService::class)->generate($task, runAfterGenerate: false);
        } catch (InsufficientCreditsException) {
            // задачата остава proposed без draft — ще се генерира по-късно
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Scope според ролята + текущия плейсмънт. */
    private function scopeFor($member): string
    {
        return match ($member->kind) {
            'manager' => 'Ти си Управителят — мисли стратегически, за целия бизнес и екип.',
            'director' => 'Ти си Директор. Фокусирай се върху своя домейн, асистентите си и техните последни изпълнения.',
            default => 'Ти си Асистент. Фокусирай се върху своите задачи и последните им резултати.',
        };
    }

    /** Кратка история (последни съобщения) + текущия вход. */
    private function history(MemberChat $chat, MemberMessage $userMessage): string
    {
        $recent = $chat->messages()
            ->whereKeyNot($userMessage->id)
            ->latest()->take(6)->get()->reverse();

        $lines = [];
        foreach ($recent as $m) {
            $lines[] = ($m->role === 'user' ? 'Потребител: ' : 'Аз: ').mb_substr((string) $m->content, 0, 800);
        }
        $lines[] = 'Потребител: '.$userMessage->content;

        return implode("\n", $lines);
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reply' => ['type' => 'string'],
                'proposal' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['task', 'hire', 'mandate']],
                        'title' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'act_mode' => ['type' => 'string', 'enum' => ['draft', 'act', 'mixed']],
                        'org_member_id' => ['type' => ['integer', 'null']],
                    ],
                ],
            ],
            'required' => ['reply'],
        ];
    }

    private function normalizeProposal($p, $member): ?array
    {
        if (! is_array($p) || empty($p['title'])) {
            return null;
        }

        $actMode = $p['act_mode'] ?? 'draft';
        $pType = $p['type'] ?? 'task';

        return [
            'type' => in_array($pType, ['task', 'hire', 'mandate'], true) ? $pType : 'task',
            'title' => (string) $p['title'],
            'description' => (string) ($p['description'] ?? $p['title']),
            'act_mode' => in_array($actMode, ['draft', 'act', 'mixed'], true) ? $actMode : 'draft',
            // Чат с асистент → задачата е за него; чат с управител/директор → асистентът,
            // когото моделът посочи (org_member_id), иначе null (без валиден собственик).
            'org_member_id' => $member->kind === 'assistant' ? $member->id : ($p['org_member_id'] ?? null),
            'proposed_by' => $member->display_name,
            'proposed_by_member_id' => $member->id,
        ];
    }
}
