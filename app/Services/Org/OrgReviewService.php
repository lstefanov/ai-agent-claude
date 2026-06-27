<?php

namespace App\Services\Org;

use App\Models\AssistantTask;
use App\Models\Company;
use App\Models\OrgMember;
use App\Models\OrgProposal;
use App\Services\GeneratorService;
use App\Services\Org\Billing\InsufficientCreditsException;
use App\Support\PromptData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Периодично ревю на Управителя (§7.1): KPI/отчети/празноти/qa_score тренд + памет per член
 * → конкретни ПРЕДЛОЖЕНИЯ (нов член/задача, пенсиониране на слаб асистент, нов отдел) през
 * персоната му. Задачите се обработват според политиката за одобрение на собственика.
 */
class OrgReviewService
{
    /** Таван на новите предложения в едно ревю; реалният брой идва от чертата Риск. */
    private const MAX_TASK_PROPOSALS = 3;

    public function __construct(
        private PersonaService $personas,
        private MemberMemoryService $memory,
        private GeneratorService $generator,
    ) {}

    /**
     * @return array{proposals: array<int>, summary: string}
     */
    public function review(Company $company): array
    {
        $manager = $company->manager;
        $version = $company->activeOrgVersion;
        if (! $manager || ! $version) {
            return ['proposals' => [], 'summary' => 'Няма активна организация за ревю.'];
        }
        $managerPolicy = $this->personas->runtimePolicy($manager);
        $maxTaskProposals = min(self::MAX_TASK_PROPOSALS, max(1, (int) ($managerPolicy['proposal_limit'] ?? 1)));

        // Състояние на отдела: асистенти + KPI + поуки.
        $assistants = $version->assistants()->with('orgMember.persona')->get();
        $state = [];
        foreach ($assistants as $a) {
            $member = $a->orgMember;
            if (! $member) {
                continue;
            }
            $stats = $this->memory->runStats($member);
            $state[] = [
                'member_id' => $member->id,
                'name' => $member->persona?->name ?? $member->display_name,
                'role' => $a->title,
                'runs' => $stats['runs'],
                'avg_qa' => $stats['avg_qa'],
            ];
        }

        $pains = (array) ($company->businessProfile?->pain_points ?? []);
        $design = $this->propose($manager, $state, $pains);

        $assistantMembers = $assistants->pluck('orgMember')->filter()->values();

        // Структурните предложения (hire/fire/mandate) → Кутията (OrgProposal). Задачите
        // → директно AssistantTask(proposed) + генерация → pending_approval с brief
        // (ревизиран §6.1: край на „generic proposal → задача после"). Лимит за разход.
        $ids = [];
        $taskCount = 0;
        foreach (array_slice((array) ($design['proposals'] ?? []), 0, 4) as $p) {
            if (empty($p['title'])) {
                continue;
            }

            $type = in_array($p['type'] ?? 'task', ['hire', 'fire', 'task', 'mandate'], true) ? $p['type'] : 'task';

            if ($type === 'task') {
                if ($taskCount >= $maxTaskProposals) {
                    continue;
                }
                if ($this->proposeTask($company, $p, $assistantMembers)) {
                    $taskCount++;
                }

                continue;
            }

            $ids[] = $this->createProposal($company, $manager, $p, $type)->id;
        }

        // Детерминистичен под: нищо предложено, но има болки → една задача по водещата болка.
        if ($ids === [] && $taskCount === 0 && ($pains[0] ?? null)) {
            if ($this->proposeTask($company, [
                'title' => 'Адресирай: '.$pains[0],
                'description' => 'Задача по водещата болка на бизнеса.',
            ], $assistantMembers)) {
                $taskCount++;   // за да не скрие no-op guard-ът реално създадената задача
            }
        }

        $summary = (string) ($design['summary'] ?? 'Ревю завършено.');

        // No-op guard + dedup (§11.3): записвай ревю-събитие само ако реално е предложено
        // нещо (структурно или задача) И текстът се различава от последния управителски отчет.
        $lastReview = $company->orgEvents()->where('type', 'review')
            ->where('org_member_id', $manager->id)->latest('id')->value('summary');
        if (($ids !== [] || $taskCount > 0) && trim((string) $lastReview) !== trim($summary)) {
            $company->orgEvents()->create([
                'type' => 'review',
                'org_version_id' => $version->id,
                'org_member_id' => $manager->id,
                'summary' => $summary,
                'actor' => 'manager',
            ]);
        }

        return ['proposals' => $ids, 'summary' => $summary];
    }

    /** Създава durable org_proposal(pending) за Кутията — само структурни (hire/fire/mandate). */
    private function createProposal(Company $company, $manager, array $p, string $type): OrgProposal
    {
        return OrgProposal::create([
            'company_id' => $company->id,
            'type' => $type,
            'payload' => [
                'title' => (string) $p['title'],
                'description' => (string) ($p['description'] ?? $p['title']),
                'target_member_id' => $p['target_member_id'] ?? null,
                'proposed_by' => $manager->persona?->name ?? 'Управителя',
            ],
            'base_org_version_id' => $company->active_org_version_id,
        ]);
    }

    /**
     * Предложена от ревюто задача → AssistantTask(proposed) + асинхронна генерация
     * (→ pending_approval с brief). Собственик = посоченият асистент или първият в отдела.
     * Кредитната резервация е в dispatchGeneration — недостиг → пропуска без фейл.
     *
     * @param  Collection<int, OrgMember>  $assistantMembers
     */
    private function proposeTask(Company $company, array $p, Collection $assistantMembers): bool
    {
        $ownerId = $p['org_member_id'] ?? null;
        $owner = $ownerId ? $assistantMembers->firstWhere('id', (int) $ownerId) : null;
        $owner ??= $assistantMembers->first();
        if (! $owner) {
            return false;   // няма на кого да възложим задачата
        }
        $policy = $this->personas->runtimePolicy($owner);

        try {
            $task = AssistantTask::create([
                'org_member_id' => $owner->id,
                'title' => (string) $p['title'],
                'description' => (string) ($p['description'] ?? $p['title']),
                'trigger' => 'manual',
                'act_mode' => 'draft',
                'approval_policy' => (string) ($policy['approval_policy'] ?? 'approve_each'),
                'status' => 'proposed',
            ]);

            app(TaskRunService::class)->generate($task, runAfterGenerate: false);

            return true;
        } catch (InsufficientCreditsException) {
            Log::info('[OrgReview] task proposal skipped (no credits)');

            return false;
        } catch (\Throwable $e) {
            Log::warning('[OrgReview] task proposal failed: '.$e->getMessage());

            return false;
        }
    }

    /** Управителят предлага през персоната си (LLM); fallback при слаб модел. */
    private function propose($manager, array $state, array $pains): array
    {
        $persona = $this->personas->compileSystemPrompt($manager);
        $policy = $this->personas->runtimePolicy($manager);
        $system = trim($persona."\n\n".'Ти си Управителят. Прегледай състоянието на екипа и болките на '
            .'бизнеса и предложи 1–3 КОНКРЕТНИ промени (нова задача/наемане/пенсиониране на слаб '
            .'асистент/нов мандат), обосновани от данните. Слаб = малко runs или нисък qa. Върни САМО '
            .'валиден JSON по схемата, на български. '.PromptData::NO_TECH_TERMS);
        $user = 'Екип: '.json_encode(PromptData::humanize($state), JSON_UNESCAPED_UNICODE)."\nБолки: ".implode('; ', $pains);

        try {
            return $this->generator->chatJson($system, $user, 'org_review', $this->schema(), [
                'temperature' => (float) ($policy['planner_temperature'] ?? 0.5), 'num_predict' => 1500,
            ]);
        } catch (\Throwable $e) {
            Log::info('[OrgReview] LLM failed, deterministic fallback: '.$e->getMessage());
            // Fallback: предложи задача по първата болка.
            $first = $pains[0] ?? null;

            return [
                'summary' => 'Ревю (опростено): екипът работи; предлагам приоритет по болките.',
                'proposals' => $first ? [['type' => 'task', 'title' => 'Адресирай: '.$first, 'description' => 'Задача по болка от бизнеса.']] : [],
            ];
        }
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'proposals' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['hire', 'fire', 'task', 'mandate']],
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'target_member_id' => ['type' => ['integer', 'null']],
                            'org_member_id' => ['type' => ['integer', 'null']],
                        ],
                    ],
                ],
            ],
            'required' => ['summary', 'proposals'],
        ];
    }
}
