<?php

namespace App\Services\Org;

use App\Models\MemberChat;
use App\Models\MemberMessage;
use App\Models\OrgProposal;
use App\Services\GeneratorService;
use App\Services\KnowledgeService;
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
            .'(нова задача/кампания/наемане) — то влиза в Кутията за одобрение, не се изпълнява само. '
            .'Връщай САМО валиден JSON по схемата.'
            .($reflection !== '' ? "\n\n".$reflection : '')
            .($knowledgeBlock !== '' ? "\n\n".$knowledgeBlock : ''));

        $history = $this->history($chat, $userMessage);

        $raw = $this->generator->chatJson($system, $history, 'member_chat', $this->schema(), [
            'temperature' => (float) ($member->persona?->derived_knobs['temperature'] ?? 0.6),
            'num_predict' => 1500,
        ]);

        $cost = LlmUsage::take()['cost_usd'] ?? null;
        $reply = trim((string) ($raw['reply'] ?? '')) ?: '…';
        $proposal = $this->normalizeProposal($raw['proposal'] ?? null, $member);

        // Записва durable предложение за Кутията (§A7).
        if ($proposal) {
            OrgProposal::create([
                'company_id' => $company->id,
                'type' => $proposal['type'],
                'payload' => $proposal,
                'base_org_version_id' => $company->active_org_version_id,
            ]);
        }

        return ['reply' => $reply, 'proposal' => $proposal, 'cost_usd' => $cost];
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

        return [
            'type' => in_array($p['type'] ?? 'task', ['task', 'hire', 'mandate'], true) ? $p['type'] : 'task',
            'title' => (string) $p['title'],
            'description' => (string) ($p['description'] ?? $p['title']),
            'act_mode' => in_array($p['act_mode'] ?? 'draft', ['draft', 'act', 'mixed'], true) ? $p['act_mode'] : 'draft',
            // По подразбиране нова задача за асистент-члена (ако чатът е с асистент).
            'org_member_id' => $member->kind === 'assistant' ? $member->id : null,
            'proposed_by' => $member->display_name,
        ];
    }
}
