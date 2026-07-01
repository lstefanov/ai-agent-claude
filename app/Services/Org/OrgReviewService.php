<?php

namespace App\Services\Org;

use App\Models\Company;
use App\Models\OrgMember;
use App\Models\OrgProposal;
use App\Services\GeneratorService;
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
    /** Таван на новите задачи в едно ревю; реалният брой идва от чертата Риск. */
    private const MAX_TASK_PROPOSALS = 3;

    /** Общ таван на предложенията от LLM (задачи + структурни). */
    private const MAX_PROPOSALS_PER_REVIEW = 4;

    public function __construct(
        private PersonaService $personas,
        private MemberMemoryService $memory,
        private GeneratorService $generator,
        private MemberLifecycleService $lifecycle,
        private OrgProposalService $proposals,
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
            // Стаж/изпитателен/задачи + KPI — без тях нов член (0 runs, „няма данни" qa) изглежда
            // неразличим от хроничен слаб служител и бива предложен за съкращение по погрешка.
            $state[] = [
                'member_id' => $member->id,
                'name' => $member->persona?->name ?? $member->display_name,
                'role' => $a->title,
            ] + $this->lifecycle->context($member);
        }

        $pains = (array) ($company->businessProfile?->pain_points ?? []);
        $design = $this->propose($manager, $state, $pains);

        $assistantMembers = $assistants->pluck('orgMember')->filter()->values();

        // Структурните предложения (hire/fire/mandate) → Кутията (OrgProposal). Задачите
        // → директно AssistantTask(proposed) + генерация → pending_approval с brief
        // (ревизиран §6.1: край на „generic proposal → задача после"). Лимит за разход.
        $ids = [];
        $taskCount = 0;
        foreach (array_slice((array) ($design['proposals'] ?? []), 0, self::MAX_PROPOSALS_PER_REVIEW) as $p) {
            if (empty($p['title'])) {
                continue;
            }

            $pType = $p['type'] ?? 'task';
            $type = in_array($pType, ['hire', 'fire', 'task', 'mandate'], true) ? $pType : 'task';

            if ($type === 'task') {
                if ($taskCount >= $maxTaskProposals) {
                    continue;
                }
                if ($this->proposeTask($company, $p, $assistantMembers)) {
                    $taskCount++;
                }

                continue;
            }

            // Funnel-ът може да върне null (напр. блокирано съкращение на нов член) → пропусни.
            if ($rec = $this->createProposal($company, $manager, $p, $type, $assistantMembers)) {
                $ids[] = $rec->id;
            }
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

    /**
     * Създава durable org_proposal(pending) за Кутията — само структурни (hire/fire/mandate).
     * През funnel-а (OrgProposalService): може да върне null, ако guard-ът блокира (напр.
     * съкращение на член в изпитателен срок / без реален шанс / с активна работа).
     */
    private function createProposal(Company $company, $manager, array $p, string $type, Collection $assistantMembers): ?OrgProposal
    {
        return $this->proposals->create($company, $type, [
            'title' => (string) $p['title'],
            'description' => (string) ($p['description'] ?? $p['title']),
            'target_member_id' => $this->resolveMemberId($company, $p['target_member_id'] ?? null, $assistantMembers),
            'proposed_by' => $manager->persona?->name ?? 'Управителя',
            'proposed_by_member_id' => $manager->id,
        ]);
    }

    /**
     * LLM member_id → валиден OrgMember на компанията или null.
     *
     * @param  Collection<int, OrgMember>  $knownMembers
     */
    private function resolveMemberId(Company $company, mixed $memberId, Collection $knownMembers): ?int
    {
        if ($memberId === null || $memberId === '') {
            return null;
        }

        $id = (int) $memberId;
        if ($id <= 0) {
            return null;
        }

        if ($knownMembers->firstWhere('id', $id)) {
            return $id;
        }

        return $company->members()->where('id', $id)->exists() ? $id : null;
    }

    /**
     * Предложена от ревюто задача → евтино OrgProposal(task) (Q1 идея-бек лог) — БЕЗ
     * флоу-генерация тук. Флоуът се генерира чак при одобрение в Кутията
     * (DecisionController::materializeProposal('task')). Собственик = посоченият асистент
     * или първият в отдела.
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

        return $this->proposals->create($company, 'task', [
            'title' => (string) $p['title'],
            'description' => (string) ($p['description'] ?? $p['title']),
            'org_member_id' => $owner->id,
            'target_member_id' => $owner->id,
            'act_mode' => 'draft',
            'rationale' => (string) ($p['description'] ?? $p['title']),
            'proposed_by' => $company->manager?->persona?->name ?? 'Управителя',
            'proposed_by_member_id' => $company->manager?->id,
        ]) !== null;
    }

    /** Управителят предлага през персоната си (LLM); fallback при слаб модел. */
    private function propose($manager, array $state, array $pains): array
    {
        $persona = $this->personas->compileSystemPrompt($manager);
        $policy = $this->personas->runtimePolicy($manager);
        $graceDays = (int) config('organization.lifecycle.fire_grace_days', 14);
        $minRuns = (int) config('organization.lifecycle.fire_min_runs', 3);
        $lowQa = (float) config('organization.lifecycle.fire_low_qa_threshold', 40);
        $system = trim($persona."\n\n".'Ти си Управителят. Прегледай състоянието на екипа (за всеки член: '
            .'стаж в дни, дали е в изпитателен срок, брой активни задачи, брой изпълнения и средно качество) '
            .'и болките на бизнеса, и предложи 1–3 КОНКРЕТНИ промени (нова задача/наемане/нов мандат/'
            .'съкращение), обосновани от данните. '
            .'ПРАВИЛА ЗА СЪКРАЩЕНИЕ (строги): предлагай съкращение САМО ако членът е имал СПРАВЕДЛИВ шанс — '
            .'стаж поне '.$graceDays.' дни И поне '.$minRuns.' завършени изпълнения — И показва траен слаб '
            .'резултат (средно качество под '.$lowQa.') ИЛИ системно проваля/пренебрегва възложените задачи. '
            .'Липсата на данни за НОВ член (в изпитателен срок, малък стаж, 0 изпълнения, „няма данни" за '
            .'качеството) НЕ е слабост — това е нормално начало. Никога не предлагай съкращение на член в '
            .'изпитателен срок или на член без възложени задачи — вместо това му дай ЗАДАЧА по мандата му или '
            .'смени мандата. Съкращението е последна мярка след доказано слабо представяне, не първа реакция. '
            .'Върни САМО валиден JSON по схемата, на български. '.PromptData::NO_TECH_TERMS);
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
