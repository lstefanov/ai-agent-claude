<?php

namespace App\Services;

use App\Jobs\DistillFlowMemoryJob;
use App\Jobs\ExecuteNodeJob;
use App\Jobs\HarvestRunKnowledgeJob;
use App\Jobs\JudgeEvalRunJob;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowEvalRun;
use App\Models\FlowNode;
use App\Models\FlowRun;
use App\Models\FlowVersion;
use App\Models\NodeRun;
use App\Support\GraphTopology;
use App\Support\RunLog;
use App\Support\UrlExtractor;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * DAG flow executor with real parallelism.
 *
 *  1. Load nodes + edges, validate (Kahn — no cycles, has a terminal node).
 *  2. Compute topological "waves" (levels) of independent nodes.
 *  3. Per wave: Bus::batch([ExecuteNodeJob, ...]) on the `flows` queue.
 *     ->then() chains the next wave once the batch completes.
 *  4. Final wave done → finalize (assemble the terminal/fan-in output).
 *
 * Node outputs are stored namespaced in node_runs — never overwritten.
 */
class GraphFlowExecutor
{
    public function run(Flow $flow, string $triggeredBy = 'manual', ?FlowRun $flowRun = null): FlowRun
    {
        $flowRun ??= FlowRun::create([
            'flow_id' => $flow->id,
            'flow_version_id' => $flow->activeVersion()->value('id'),
            'status' => 'pending',
            'triggered_by' => $triggeredBy,
        ]);

        // The run is PINNED to one template (version) — its materialized graph
        // is all this run ever reads, so several versions can run in parallel.
        $versionId = $flowRun->flow_version_id ?? $flow->activeVersion()->value('id');
        $version = $versionId ? FlowVersion::find($versionId) : null;

        if (! $version) {
            $this->fail($flowRun, 'Флоуът няма шаблон (версия на графа) за изпълнение.');

            return $flowRun;
        }

        // qa_verifier nodes are not standalone DAG steps — they run only as inline
        // gates referenced by other nodes' config.qa.verifier_node_key.
        $nodeKeys = $version->nodes()
            ->where('is_active', true)
            ->where('type', '!=', 'qa_verifier')
            ->pluck('node_key')
            ->all();
        $edges = $version->edges()->get(['from_node_key', 'to_node_key'])
            ->map(fn ($e) => ['from' => $e->from_node_key, 'to' => $e->to_node_key])
            ->all();

        $analysis = GraphTopology::analyze($nodeKeys, $edges);

        if (! $analysis['ok']) {
            $this->fail($flowRun, 'Невалиден граф: '.implode(' ', $analysis['errors']));

            return $flowRun;
        }

        $context = $flowRun->context ?? [];
        // Per-run inputs come from the run trigger (manual form) or the webhook
        // payload; explicit `inputs` win over a raw webhook payload.
        $runInputs = array_merge(
            (array) ($context['webhook_payload'] ?? []),
            (array) ($context['inputs'] ?? []),
        );
        $context['seed'] = $this->buildSeed($flow, $runInputs);
        $context['waves'] = $analysis['waves'];
        // WS4: only run the branch-pruning logic when the graph actually has a
        // decision node — keeps every existing flow's behaviour unchanged.
        $context['has_decisions'] = $version->nodes()
            ->where('is_active', true)
            ->where('type', 'decision')
            ->exists();
        // Partial-failure policy: 'fail_fast' (default) cancels the run on the
        // first node error; 'best_effort' lets independent branches continue and
        // fan-in nodes assemble from whatever predecessors completed.
        $context['failure_policy'] = ($version->graph_layout['failure_policy'] ?? 'fail_fast') === 'best_effort'
            ? 'best_effort'
            : 'fail_fast';

        $flowRun->update([
            'status' => 'running',
            'context' => $context,
            'started_at' => now(),
            // Which template was executed — webhook/scheduler runs get it here.
            'flow_version_id' => $version->id,
            // Snapshot the cost level used, so the run history reflects it even if
            // the template is re-leveled afterwards. Eval runs override the level
            // (their models are re-pinned run-scoped via context['model_overrides']).
            'model_level' => $context['eval_level'] ?? $version->model_level,
            // Snapshot the Drawflow graph_layout at the moment the run starts so
            // the historical run viewer can show the exact graph that was executed
            // even if the user edits the template afterwards.
            'graph_snapshot' => $version->graph_layout,
        ]);

        RunLog::append($flowRun->id, "FLOW RUN #{$flowRun->id} STARTED — „{$flow->name}“ (тригер: {$triggeredBy}, версия #{$version->id}, "
            .count($analysis['waves']).' вълни, '.count($nodeKeys).' възела)');

        $this->dispatchWave($flowRun->id, $analysis['waves'], 0);

        return $flowRun;
    }

    /**
     * Dispatch one wave as a batch; chain the next wave in ->then().
     * Called both initially and from the previous batch's completion callback.
     *
     * @param  list<list<string>>  $waves
     */
    public function dispatchWave(int $flowRunId, array $waves, int $index): void
    {
        // Idempotency lock: a finishing batch callback and an approval-resume
        // can both try to dispatch the same wave — the first to record the
        // index in context['dispatched_waves'] wins, the loser exits silently.
        // (resume() clears the marker before re-dispatching a failed run.)
        $flowRun = null;
        DB::transaction(function () use ($flowRunId, $index, &$flowRun) {
            $run = FlowRun::lockForUpdate()->find($flowRunId);
            if (! $run || $run->status !== 'running') {
                return;
            }

            $context = $run->context ?? [];
            $dispatched = (array) ($context['dispatched_waves'] ?? []);
            if (in_array($index, $dispatched, true)) {
                return;
            }

            $context['dispatched_waves'] = [...$dispatched, $index];
            $run->update(['context' => $context]);
            $flowRun = $run;
        });

        if (! $flowRun) {
            return;
        }

        if ($index >= count($waves)) {
            $this->finalize($flowRun);

            return;
        }

        // WS4: prune branches not taken by an upstream decision. A node runs only
        // if it has an active incoming path; the rest are marked 'skipped'.
        $waveKeys = $waves[$index];
        if ($flowRun->context['has_decisions'] ?? false) {
            [$waveKeys, $skipped] = $this->resolveActiveNodes($flowRun, $waveKeys);
            if (! empty($skipped)) {
                $this->markSkipped($flowRun, $skipped);
                RunLog::append($flowRunId, '[SKIP] извън избрания branch: '.implode(', ', $skipped));
            }
        }

        $nodeIds = FlowNode::where('flow_version_id', $flowRun->flow_version_id)
            ->whereIn('node_key', $waveKeys)
            ->pluck('id');

        $jobs = $nodeIds->map(fn ($id) => new ExecuteNodeJob($flowRunId, (int) $id))->all();

        if (empty($jobs)) {
            $this->dispatchWave($flowRunId, $waves, $index + 1);

            return;
        }

        // best_effort → failed nodes don't cancel the batch; later waves still run.
        $bestEffort = ($flowRun->context['failure_policy'] ?? 'fail_fast') === 'best_effort';

        RunLog::append($flowRunId, 'WAVE '.($index + 1).'/'.count($waves).': '.implode(', ', $waveKeys));

        // Wave chaining strategy:
        // - `then` fires when all jobs in the wave succeed → proceed to next wave.
        // - `catch` fires in async queue mode on job failure (callbacks run in worker).
        //   In sync queue mode (tests), exceptions propagate out of dispatch() instead,
        //   so the outer try/catch handles failure there.
        // - Both paths call dispatchWave(index+1) or fail(), which are idempotent.
        try {
            Bus::batch($jobs)
                ->name("flow-run-{$flowRunId}-wave-{$index}")
                ->allowFailures($bestEffort)
                ->then(function (Batch $batch) use ($flowRunId, $waves, $index) {
                    // All jobs succeeded → proceed.
                    app(GraphFlowExecutor::class)->dispatchWave($flowRunId, $waves, $index + 1);
                })
                ->catch(function (Batch $batch, Throwable $e) use ($flowRunId, $waves, $index, $bestEffort) {
                    // Async queue mode: a job failed.
                    $run = FlowRun::find($flowRunId);
                    if (! $run || $run->status !== 'running') {
                        return;
                    }
                    if ($bestEffort) {
                        app(GraphFlowExecutor::class)->dispatchWave($flowRunId, $waves, $index + 1);
                    } else {
                        app(GraphFlowExecutor::class)->fail($run, $e->getMessage());
                    }
                })
                ->onQueue('flows')
                ->dispatch();
        } catch (Throwable $e) {
            // Sync queue mode: job exception propagates directly out of dispatch().
            $run = FlowRun::find($flowRunId);
            if (! $run || $run->status !== 'running') {
                return;
            }
            if ($bestEffort) {
                $this->dispatchWave($flowRunId, $waves, $index + 1);
            } else {
                $this->fail($run, $e->getMessage());
            }
        }
    }

    private function finalize(FlowRun $flowRun): void
    {
        // Re-check status — the run may have been marked failed by the batch
        // catch callback between when dispatchWave read the status and now.
        $flowRun = $flowRun->fresh();
        if ($flowRun->status !== 'running') {
            return;
        }

        // Deterministic, role-aware assembly from node_runs (body/appendix),
        // with an optional guarded LLM formatting pass.
        $composed = app(FinalComposerService::class)->compose($flowRun);

        // Fallback to terminal node outputs if no body/appendix nodes exist.
        $output = $composed['output'] !== '' ? $composed['output'] : $this->terminalAssembly($flowRun);

        $flowRun->update([
            'status' => 'completed',
            'final_output' => $output,
            'final_output_model' => $composed['model'],
            'completed_at' => now(),
        ]);

        RunLog::append($flowRun->id, "FLOW RUN #{$flowRun->id} COMPLETED — краен output ".mb_strlen($output).' chars');

        // Tripwire: краен output многократно по-кратък от най-дългото
        // произведено body съдържание значи, че последният възел е изпуснал
        // материала (run 102: 85-знакова „присъда" погреба 26K доклад).
        $bodyKeys = FlowNode::where('flow_version_id', $flowRun->flow_version_id)
            ->where('output_role', 'body')
            ->pluck('node_key');
        $maxBodyLen = (int) NodeRun::where('flow_run_id', $flowRun->id)
            ->where('status', 'completed')
            ->whereIn('node_key', $bodyKeys)
            ->max(DB::raw('CHAR_LENGTH(output)'));
        if ($maxBodyLen > 0 && mb_strlen($output) < (int) ($maxBodyLen * 0.2)) {
            RunLog::append($flowRun->id, '[WARN] Краен output ('.mb_strlen($output).' chars) е многократно по-кратък от произведеното съдържание ('.$maxBodyLen.' chars) — провери изхода на последния възел');
        }

        $flowRun->flow->update(['last_run_at' => now()]);

        // Eval runs са СИНТЕТИЧНИ тестове — оценяват се и спират дотук, БЕЗ
        // странични ефекти (не учат plan library/паметта, не жънат знание, не
        // доставят). Само диспечваме judge-а върху произведения изход.
        if (isset($flowRun->context['eval_run_id'])) {
            JudgeEvalRunJob::dispatch((int) $flowRun->context['eval_run_id']);

            return;
        }

        // Plan library (Фаза 2): a successful run proves the approved plan —
        // it becomes a few-shot example for future planning.
        app(PlanLibraryService::class)->recordRunOutcome($flowRun);

        // Памет на flow-а: distill what this run produced (content digests +
        // embeddings + lessons) so future runs avoid duplicating it.
        if (FlowMemoryService::enabled($flowRun->flow)) {
            DistillFlowMemoryJob::dispatch($flowRun->id);
        }

        // Знание: жътва на фирмени ФАКТИ от изходите на агентите — профилът
        // на фирмата се обновява с всеки успешен run (одит в knowledge_events).
        if (KnowledgeService::enabledForFlow($flowRun->flow)) {
            HarvestRunKnowledgeJob::dispatch($flowRun->id);
        }

        // Deliver the result to the flow's configured channel (email/Slack/
        // webhook/file). Best-effort — a delivery failure never fails the run.
        try {
            app(DeliveryService::class)->deliver($flowRun);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function terminalAssembly(FlowRun $flowRun): string
    {
        $version = $flowRun->flowVersion;
        $fromKeys = $version->edges()->pluck('from_node_key')->unique()->all();
        $terminalKeys = $version->nodes()
            ->where('is_active', true)
            ->pluck('node_key')
            ->reject(fn ($k) => in_array($k, $fromKeys, true))
            ->values()
            ->all();

        return NodeRun::where('flow_run_id', $flowRun->id)
            ->whereIn('node_key', $terminalKeys)
            ->where('status', 'completed')
            ->pluck('output')
            ->filter()
            ->implode("\n\n");
    }

    /**
     * WS4 — split a wave into nodes to run vs. nodes pruned by an upstream
     * decision. A node is active if it has no predecessors, or at least one
     * incoming edge is active: the predecessor completed AND — when it is a
     * decision that recorded a choice — the edge's from_port is the chosen one.
     * Decisions without a recorded choice don't prune (safe fallback).
     *
     * @param  list<string>  $waveKeys
     * @return array{0: list<string>, 1: list<string>} [active, skipped]
     */
    private function resolveActiveNodes(FlowRun $flowRun, array $waveKeys): array
    {
        $edges = FlowEdge::where('flow_version_id', $flowRun->flow_version_id)
            ->whereIn('to_node_key', $waveKeys)
            ->get(['from_node_key', 'to_node_key', 'from_port']);

        if ($edges->isEmpty()) {
            return [$waveKeys, []]; // every node in this wave is a root
        }

        $fromKeys = $edges->pluck('from_node_key')->unique()->all();

        $statuses = NodeRun::where('flow_run_id', $flowRun->id)
            ->whereIn('node_key', $fromKeys)
            ->pluck('status', 'node_key');

        $decisionKeys = FlowNode::where('flow_version_id', $flowRun->flow_version_id)
            ->where('type', 'decision')
            ->whereIn('node_key', $fromKeys)
            ->pluck('node_key')
            ->flip();

        $decisions = $flowRun->context['decisions'] ?? [];

        $incoming = [];
        foreach ($edges as $edge) {
            $incoming[$edge->to_node_key][] = $edge;
        }

        $active = [];
        $skipped = [];

        foreach ($waveKeys as $key) {
            $edgesIn = $incoming[$key] ?? [];

            if ($edgesIn === []) {
                $active[] = $key; // root within this sub-wave

                continue;
            }

            $hasActiveEdge = false;
            foreach ($edgesIn as $edge) {
                if (($statuses[$edge->from_node_key] ?? null) !== 'completed') {
                    continue; // predecessor failed/skipped/not-run → edge inactive
                }
                // Decision predecessor with a recorded choice → only its chosen port.
                if (isset($decisionKeys[$edge->from_node_key], $decisions[$edge->from_node_key])
                    && (string) $decisions[$edge->from_node_key] !== (string) $edge->from_port) {
                    continue;
                }
                $hasActiveEdge = true;
                break;
            }

            $hasActiveEdge ? $active[] = $key : $skipped[] = $key;
        }

        return [$active, $skipped];
    }

    /**
     * Mark pruned nodes as 'skipped' so the UI shows them greyed out and their
     * own successors see an inactive incoming edge (the skip propagates).
     *
     * @param  list<string>  $keys
     */
    private function markSkipped(FlowRun $flowRun, array $keys): void
    {
        $nodes = FlowNode::where('flow_version_id', $flowRun->flow_version_id)
            ->whereIn('node_key', $keys)
            ->get(['id', 'node_key']);

        foreach ($nodes as $node) {
            NodeRun::updateOrCreate(
                ['flow_run_id' => $flowRun->id, 'flow_node_id' => $node->id],
                ['node_key' => $node->node_key, 'status' => 'skipped', 'completed_at' => now()],
            );
        }
    }

    /**
     * Resume a previously failed run from the first wave that still has
     * un-completed nodes.  Completed/skipped nodes are untouched — the
     * idempotency guard in NodeExecutorService skips them automatically.
     * Failed (and any stuck-running) NodeRun rows are reset to 'pending'
     * so they re-execute when their wave is dispatched again.
     */
    public function resume(FlowRun $flowRun): void
    {
        if ($flowRun->status !== 'failed') {
            return;
        }

        $waves = $flowRun->context['waves'] ?? [];
        if (empty($waves)) {
            return;
        }

        // Reset failed/stuck/paused node runs so they will be re-executed —
        // a paused approval node re-pauses cleanly when its wave re-runs.
        NodeRun::where('flow_run_id', $flowRun->id)
            ->whereIn('status', ['failed', 'running', 'paused'])
            ->update([
                'status' => 'pending',
                'output' => null,
                'raw_output' => null,
                'error' => null,
                'started_at' => null,
                'completed_at' => null,
            ]);

        // Find the first wave that contains a node without a completed/skipped run.
        $doneKeys = NodeRun::where('flow_run_id', $flowRun->id)
            ->whereIn('status', ['completed', 'skipped'])
            ->pluck('status', 'node_key')
            ->keys()
            ->all();

        $resumeIndex = count($waves) - 1; // fallback: last wave
        foreach ($waves as $i => $waveKeys) {
            $pending = array_diff($waveKeys, $doneKeys);
            if (! empty($pending)) {
                $resumeIndex = $i;
                break;
            }
        }

        $context = $flowRun->context ?? [];
        unset($context['failure_message']);
        // Re-dispatching waves the failed run already saw — drop the
        // idempotency marker so dispatchWave doesn't refuse them.
        unset($context['dispatched_waves']);

        $flowRun->update([
            'status' => 'running',
            'context' => $context,
            'completed_at' => null,
        ]);

        RunLog::append($flowRun->id, "FLOW RUN #{$flowRun->id} RESUMED от вълна ".($resumeIndex + 1).'/'.count($waves));

        $this->dispatchWave($flowRun->id, $waves, $resumeIndex);
    }

    /**
     * Continue a run paused on a human_approval node, after the approval
     * endpoint marked that NodeRun completed. Advances to the next wave only
     * when the whole wave is settled; otherwise the still-running batch's
     * ->then() advances on its own once the status is back to 'running'
     * (double dispatch is absorbed by the dispatched_waves guard).
     */
    public function resumeAfterApproval(FlowRun $flowRun, string $nodeKey): void
    {
        // Another node still awaiting approval keeps the run paused.
        if (NodeRun::where('flow_run_id', $flowRun->id)->where('status', 'paused')->exists()) {
            return;
        }

        $updated = FlowRun::whereKey($flowRun->id)
            ->where('status', 'waiting_approval')
            ->update(['status' => 'running']);

        if (! $updated) {
            return;
        }

        RunLog::append($flowRun->id, "[APPROVAL] „{$nodeKey}“ одобрен — изпълнението продължава");

        $flowRun = $flowRun->fresh();
        $waves = $flowRun->context['waves'] ?? [];
        $index = $flowRun->context['approvals'][$nodeKey]['wave_index'] ?? null;

        if ($index === null || empty($waves)) {
            return;
        }

        $statuses = NodeRun::where('flow_run_id', $flowRun->id)
            ->whereIn('node_key', (array) ($waves[$index] ?? []))
            ->pluck('status', 'node_key');

        foreach ((array) ($waves[$index] ?? []) as $key) {
            if (! in_array($statuses[$key] ?? null, ['completed', 'failed', 'skipped'], true)) {
                return; // a sibling is still running — its batch will advance
            }
        }

        $this->dispatchWave($flowRun->id, $waves, $index + 1);
    }

    public function fail(FlowRun $flowRun, string $message): void
    {
        $context = $flowRun->fresh()->context ?? [];
        $context['failure_message'] = $message;

        $flowRun->update([
            'status' => 'failed',
            'context' => $context,
            'completed_at' => now(),
        ]);

        // Осиротели node_runs (напр. worker, убит от job timeout, не е успял да
        // отбележи реда си) иначе се въртят вечно в run viewer-а.
        NodeRun::where('flow_run_id', $flowRun->id)
            ->whereIn('status', ['pending', 'running'])
            ->update(['status' => 'failed', 'error' => $message, 'completed_at' => now()]);

        // Eval run, чийто FlowRun се провали → маркирай и eval реда като провален.
        if (isset($context['eval_run_id'])) {
            FlowEvalRun::whereKey((int) $context['eval_run_id'])
                ->whereIn('status', ['pending', 'running'])
                ->update(['status' => 'failed', 'error' => $message]);
        }

        RunLog::append($flowRun->id, "FLOW RUN #{$flowRun->id} FAILED — {$message}");
    }

    /**
     * Immutable seed context for the whole run (ported from the old executor's
     * pre-seed block). Lives under context['seed'] and is never mutated.
     *
     * Per-run inputs override the flow defaults: a run can supply its own
     * {{topic}}/{{url}} plus arbitrary {{placeholder}} keys, so one flow serves
     * many inputs without editing the graph.
     *
     * @param  array<string,mixed>  $runInputs
     * @return array<string,string>
     */
    private function buildSeed(Flow $flow, array $runInputs = []): array
    {
        $company = $flow->company;

        $topic = trim((string) ($runInputs['topic'] ?? $runInputs['input'] ?? $flow->topic ?? ''));
        $targetUrl = trim((string) ($runInputs['url'] ?? $runInputs['target_url'] ?? $runInputs['website'] ?? ''))
            ?: (UrlExtractor::first($flow->description ?? '') ?? '');
        $isSiteFlow = $targetUrl !== '';

        $seed = [
            'company_description' => $isSiteFlow ? '' : ($company?->description ?? ''),
            'company_name' => $isSiteFlow ? '' : ($company?->name ?? ''),
            'company_industry' => $isSiteFlow ? '' : ($company?->industry ?? ''),
            'input' => $topic,
            'topic' => $topic,
            'flow_topic' => $topic,
            'url' => $targetUrl,
            'target_url' => $targetUrl,
            'website' => $targetUrl,
        ];

        // Arbitrary extra per-run inputs become first-class {{placeholder}} keys
        // (never overwrite the reserved keys above).
        foreach ($runInputs as $key => $value) {
            if (is_string($key) && $key !== '' && is_scalar($value) && ! array_key_exists($key, $seed)) {
                $seed[$key] = (string) $value;
            }
        }

        return $seed;
    }
}
