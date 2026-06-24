<?php

namespace App\Services\Org;

use App\Models\AssistantTask;
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
    ) {}

    /**
     * Канонична заявка за пускане. Готова задача (flow_id) → резервира task_run и пуска
     * веднага. Без flow_id → резервира generation, маркира generating + run_after_generate
     * и диспечира АСИНХРОННА генерация. Хвърля InsufficientCreditsException при недостиг.
     *
     * @return array{status: string, run_id?: int}
     */
    public function requestRun(AssistantTask $task, bool $runAfterGenerate = true): array
    {
        if ($task->status === 'ready' && $task->flow_id) {
            $run = $this->launchReadyRun($task);

            return ['status' => 'running', 'run_id' => $run->id];
        }

        $this->dispatchGeneration($task, $runAfterGenerate);

        return ['status' => 'generating'];
    }

    /**
     * Само генерация (бутон „Генерирай") — без авто-пускане. Готова задача → no-op.
     *
     * @return array{status: string, token?: ?string}
     */
    public function generate(AssistantTask $task, bool $runAfterGenerate = false): array
    {
        if ($task->status === 'ready' && $task->flow_id) {
            return ['status' => 'ready'];
        }

        $this->dispatchGeneration($task, $runAfterGenerate);

        return ['status' => 'generating', 'token' => $task->fresh()->gen_token];
    }

    /**
     * Пуска готова задача: създава FlowRun с org контекст (org_member_id за персоната +
     * билинг полета), резервира task_run (subject = този run → уникален per run, позволява
     * повторни пускания) и стартира executor-а.
     */
    public function launchReadyRun(AssistantTask $task): FlowRun
    {
        $flow = $task->flow;
        $member = $task->orgMember;
        if (! $flow || ! $member) {
            throw new RuntimeException('Задачата няма готов flow или член-собственик.');
        }

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
                $member->company_id, 'task_run', $run, BillableUnit::estimate($task),
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
     * Callback от генерацията: при ready + run_after_generate + наличен баланс → авто-пуска;
     * иначе остава ready (нищо не се харчи), а намерението може да се изпълни по-късно ръчно.
     */
    public function autoRunAfterGenerate(AssistantTask $task): void
    {
        if (! $task->run_after_generate || $task->status !== 'ready' || ! $task->flow_id) {
            return;
        }

        try {
            $this->launchReadyRun($task);
            $task->update(['run_after_generate' => false]);   // намерението е изпълнено
        } catch (InsufficientCreditsException) {
            Log::info("[TaskRun] auto-run skipped (no credits) task {$task->id}");
            // остава ready + run_after_generate=true → човек пуска по-късно
        }
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
    private function dispatchGeneration(AssistantTask $task, bool $runAfterGenerate): void
    {
        $member = $task->orgMember;
        if (! $member) {
            throw new RuntimeException('Задачата няма член-собственик.');
        }
        $companyId = $member->company_id;

        // Генерацията е реален planner разход → резервирай ПРЕДИ диспечиране (block при недостиг).
        $this->meter->reserve($companyId, 'generation', $task, BillableUnit::estimateGeneration($task));

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
            persist: true, assistantTaskId: $task->id,
        );

        $task->update(['gen_token' => $token]);
    }
}
