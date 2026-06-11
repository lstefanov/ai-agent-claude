<?php

namespace App\Services;

use App\Jobs\ExecuteNodeJob;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowRun;
use App\Models\FlowVersion;
use App\Models\NodeRun;
use App\Support\GraphTopology;
use App\Support\UrlExtractor;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
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
            // Snapshot the Drawflow graph_layout at the moment the run starts so
            // the historical run viewer can show the exact graph that was executed
            // even if the user edits the template afterwards.
            'graph_snapshot' => $version->graph_layout,
        ]);

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
        $flowRun = FlowRun::find($flowRunId);
        if (! $flowRun || $flowRun->status !== 'running') {
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

        $flowRun->flow->update(['last_run_at' => now()]);

        // Plan library (Фаза 2): a successful run proves the approved plan —
        // it becomes a few-shot example for future planning.
        app(PlanLibraryService::class)->recordRunOutcome($flowRun);

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

    public function fail(FlowRun $flowRun, string $message): void
    {
        $context = $flowRun->fresh()->context ?? [];
        $context['failure_message'] = $message;

        $flowRun->update([
            'status' => 'failed',
            'context' => $context,
            'completed_at' => now(),
        ]);
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
