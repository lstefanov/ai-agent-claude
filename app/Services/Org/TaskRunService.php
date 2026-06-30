<?php

namespace App\Services\Org;

use App\Models\AssistantTask;
use App\Models\Company;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowRun;
use App\Services\AgentGenerationLauncher;
use App\Services\AgentGeneratorService;
use App\Services\GraphFlowExecutor;
use App\Services\GraphNormalizer;
use App\Services\Org\Billing\CreditMeterService;
use App\Services\Org\Billing\InsufficientCreditsException;
use App\Support\BillableUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Жизненият цикъл на пускане на задача (§0.5.3/§0.5.6) — споделен от ръчния run
 * (Фаза 3), директорския tick (Фаза 4) и generation callback-а. Wallet-ът е гейтът:
 * нищо не стартира при недостиг. Без синхронно чакане — генерацията е асинхронна,
 * намерението (run_after_generate) е durable.
 */
class TaskRunService
{
    public function __construct(
        private CreditMeterService $meter,
        private AgentGenerationLauncher $launcher,
        private PersonaService $personas,
    ) {}

    /** ID-та на активните асистенти на фирмата — споделен филтър за дневника на задачите. */
    public function activeAssistantMemberIds(Company $company): Collection
    {
        return $company->members()
            ->where('kind', 'assistant')
            ->where('status', 'active')
            ->pluck('id');
    }

    /** Базова заявка за задачи на активни асистенти. */
    public function tasksForActiveAssistants(Company $company): Builder
    {
        return AssistantTask::whereIn('org_member_id', $this->activeAssistantMemberIds($company));
    }

    /** Брой за таб „За изпълнение“ (ready + generating) — nav badge и табло. */
    public function readyTabCount(Company $company): int
    {
        return $this->tasksForActiveAssistants($company)
            ->whereIn('status', ['ready', 'generating'])
            ->count();
    }

    /**
     * Канонична заявка за пускане. Готова задача (flow_id) → резервира task_run и пуска
     * веднага. Без flow_id → резервира generation, маркира generating + run_after_generate
     * и диспечира АСИНХРОННА генерация. Хвърля InsufficientCreditsException при недостиг.
     *
     * @return array{status: string, run_id?: int}
     */
    public function requestRun(AssistantTask $task, bool $runAfterGenerate = true, string $origin = 'manual'): array
    {
        $task = $this->applyPersonaDefaults($task);

        // Явна status-машина (ревизиран lifecycle): само одобрена (ready) задача се пуска;
        // само нова (proposed) се генерира. Решените/в процес статуси НЕ харчат и НЕ
        // регенерират при директен POST към run endpoint-а.
        if ($task->status === 'ready' && $task->flow_id) {
            $run = $this->launchReadyRun($task, $origin);

            return ['status' => 'running', 'run_id' => $run->id];
        }

        if ($task->status === 'proposed') {
            // Preflight ПРЕДИ генерация: очевидно частна зависимост при празна база → паркирай.
            if (app(KnowledgeRequirementService::class)->preflight($task)) {
                return ['status' => 'needs_knowledge'];
            }
            $this->dispatchGeneration($task, $runAfterGenerate, origin: $origin);

            return ['status' => 'generating'];
        }

        // pending_approval / rejected / disabled / failed / generating → отказ.
        throw new RuntimeException('Задачата не може да се пусне в състояние «'.$task->status.'».');
    }

    /**
     * Само генерация (бутон „Генерирай") — без авто-пускане. Готова задача → no-op.
     *
     * @return array{status: string, token?: ?string}
     */
    public function generate(AssistantTask $task, bool $runAfterGenerate = false, bool $minimalQa = false, string $origin = 'manual', bool $firstReviewDone = false): array
    {
        $task = $this->applyPersonaDefaults($task, $firstReviewDone);

        if ($task->status === 'ready' && $task->flow_id) {
            return ['status' => 'ready'];
        }

        // Само нова (proposed) или провалена (retry) задача се генерира. Иначе no-op —
        // без повторно резервиране/диспечиране и без клобване на вече решен статус
        // (pending_approval/rejected/disabled/generating).
        if (! in_array($task->status, ['proposed', 'failed'], true)) {
            return ['status' => $task->status];
        }

        // Preflight ПРЕДИ генерация — спестява planner разход за очевидно недоставими задачи.
        if (app(KnowledgeRequirementService::class)->preflight($task)) {
            return ['status' => 'needs_knowledge'];
        }

        $this->dispatchGeneration($task, $runAfterGenerate, $minimalQa, $origin, $firstReviewDone);

        return ['status' => 'generating', 'token' => $task->fresh()->gen_token];
    }

    /**
     * Пуска готова задача: създава FlowRun с org контекст (org_member_id за персоната +
     * билинг полета), резервира task_run (subject = този run → уникален per run, позволява
     * повторни пускания) и стартира executor-а.
     */
    public function launchReadyRun(AssistantTask $task, string $origin = 'manual'): FlowRun
    {
        $task = $this->applyPersonaDefaults($task);

        $flow = $task->flow;
        $member = $task->orgMember;
        if (! $flow || ! $member) {
            throw new RuntimeException('Задачата няма готов flow или член-собственик.');
        }

        // Run-safety (ранен изход преди резервация): само активен flow се пуска. Дублира
        // централния гейт в GraphFlowExecutor, но спестява осиротяла кредитна резервация.
        if ($flow->status !== 'active') {
            throw new RuntimeException('Flow-ът на задачата не е активен — изисква одобрение.');
        }

        // Гейт по знание (§2-етапни задачи): не създавай FlowRun/резервация, ако липсва нужно
        // знание. Хвърля KnowledgeRequiredException (контролерът → 422 + popup; auto/scheduled →
        // паркира). Преди резервацията → без осиротяла кредитна резервация.
        app(KnowledgeRequirementService::class)->gate($task);

        // Lazy re-pin (§6.1): stale задача → re-pin server-side към effectiveStarTier() ПРЕДИ старт.
        if ($task->tier_stale) {
            $this->repinToEffectiveTier($task);
        }

        $version = $flow->activeVersion;
        $run = FlowRun::create([
            'flow_id' => $flow->id,
            'flow_version_id' => $version?->id,
            'status' => 'pending',
            'triggered_by' => 'manual',
            'context' => [
                'assistant_task_id' => $task->id,
                'org_member_id' => $member->id,   // → персона инжекция (§0.5.5)
            ],
        ]);

        try {
            $reservation = $this->meter->reserve(
                $member->company_id, 'task_run', $run, BillableUnit::estimate($task), $origin,
            );
        } catch (InsufficientCreditsException $e) {
            $run->delete();   // без осиротял pending run

            throw $e;
        }

        // Стампваме резервацията в run контекста → node LLM повикванията се атрибутират.
        $ctx = $run->context;
        $ctx['credit_reservation_id'] = $reservation->id;
        $ctx['credit_context_type'] = 'task_run';
        $ctx['credit_subject_type'] = $run->getMorphClass();
        $ctx['credit_subject_id'] = $run->id;
        $run->update(['context' => $ctx]);

        app(GraphFlowExecutor::class)->run($flow, 'manual', $run->fresh());

        return $run->fresh();
    }

    /**
     * Server-side relevel на flow-а на stale задача към effectiveStarTier() (§6.1) —
     * пинва моделите в flow_nodes (записано), после tier_stale=false. Flow-ът е ексклузивен
     * за задачата, така че мутацията е безопасна. Грешка → чисти stale, за да не зацикля.
     */
    private function repinToEffectiveTier(AssistantTask $task): void
    {
        try {
            $version = $task->flow?->activeVersion;
            if ($version) {
                [$nodes, $edges] = app(GraphNormalizer::class)->parse((array) ($version->graph_layout ?? []));
                $assignments = app(AgentGeneratorService::class)
                    ->assignModelsForLevel($nodes, $edges, $task->effectiveStarTier());
                foreach ($assignments as $key => $assignment) {
                    FlowNode::where('flow_version_id', $version->id)
                        ->where('node_key', (string) $key)
                        ->update(['model' => (string) ($assignment['model'] ?? '')]);
                }
                $version->update(['model_level' => $task->effectiveStarTier()->value]);
            }
        } catch (\Throwable $e) {
            Log::warning('[TaskRun] re-pin failed for task '.$task->id.': '.$e->getMessage());
        } finally {
            $task->update(['tier_stale' => false]);
        }
    }

    /** Резервира generation, осигурява Flow, маркира generating и пуска асинхронна генерация. */
    private function dispatchGeneration(AssistantTask $task, bool $runAfterGenerate, bool $minimalQa = false, string $origin = 'manual', bool $firstReviewDone = false): void
    {
        $task = $this->applyPersonaDefaults($task, $firstReviewDone);

        $member = $task->orgMember;
        if (! $member) {
            throw new RuntimeException('Задачата няма член-собственик.');
        }
        $companyId = $member->company_id;

        // Генерацията е реален planner разход → резервирай ПРЕДИ диспечиране (block при недостиг).
        $this->meter->reserve($companyId, 'generation', $task, BillableUnit::estimateGeneration($task), $origin);

        $flow = $task->flow ?? Flow::create([
            'company_id' => $companyId,
            'name' => $task->title,
            'description' => $task->description,
            'status' => 'draft',
        ]);

        $task->update([
            'flow_id' => $flow->id,
            'status' => 'generating',
            'run_after_generate' => $runAfterGenerate,
        ]);

        $token = $this->launcher->launch(
            $companyId, $flow->id, $task->title, $task->description,
            $task->effectiveStarTier()->value,
            minimalQa: $minimalQa, persist: true, assistantTaskId: $task->id,
        );

        $task->update(['gen_token' => $token]);
    }

    /**
     * Черти → default настройки на задачата. Прилага се преди оценка, генерация и пускане,
     * за да не остане approval_policy само записана идея без runtime ефект.
     */
    private function applyPersonaDefaults(AssistantTask $task, bool $firstReviewDone = false): AssistantTask
    {
        // Идея-породена задача (Q1): човекът вече одобри идеята в Кутията → НЕ пипай
        // approval_policy (остава 'auto') → flow → ready, без втори гейт (single-gate).
        if ($firstReviewDone) {
            return $task;
        }

        if (! in_array($task->status, ['proposed', 'generating', 'failed'], true)) {
            return $task;
        }

        $task->loadMissing('orgMember.persona');
        $member = $task->orgMember;
        if (! $member || ! $member->persona) {
            return $task;
        }

        $policy = $this->personas->runtimePolicy($member);
        $approval = (string) ($policy['approval_policy'] ?? $task->approval_policy);
        // First-review (§Q3): AI-породена задача НИКОГА не се ражда суров `auto` — първата
        // версия винаги минава през Кутията. Downgrade-ът към `auto` става при одобрение
        // (DecisionBoxService::approveTask), след което задачата е `ready` и не минава пак насам.
        if ($approval === 'auto') {
            $approval = 'approve_first_then_auto';
        }
        if (! in_array($approval, ['approve_first_then_auto', 'approve_each'], true)) {
            return $task;
        }

        if ($task->approval_policy !== $approval) {
            $task->update(['approval_policy' => $approval]);

            return $task->fresh(['orgMember.persona']);
        }

        return $task;
    }
}
