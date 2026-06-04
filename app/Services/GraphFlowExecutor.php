<?php

namespace App\Services;

use App\Jobs\ExecuteNodeJob;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowRun;
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
            'status' => 'pending',
            'triggered_by' => $triggeredBy,
        ]);

        // qa_verifier nodes are not standalone DAG steps — they run only as inline
        // gates referenced by other nodes' config.qa.verifier_node_key.
        $nodeKeys = $flow->nodes()
            ->where('is_active', true)
            ->where('type', '!=', 'qa_verifier')
            ->pluck('node_key')
            ->all();
        $edges = $flow->edges()->get(['from_node_key', 'to_node_key'])
            ->map(fn ($e) => ['from' => $e->from_node_key, 'to' => $e->to_node_key])
            ->all();

        $analysis = GraphTopology::analyze($nodeKeys, $edges);

        if (! $analysis['ok']) {
            $this->fail($flowRun, 'Невалиден граф: '.implode(' ', $analysis['errors']));

            return $flowRun;
        }

        $context = $flowRun->context ?? [];
        $context['seed'] = $this->buildSeed($flow);
        $context['waves'] = $analysis['waves'];
        // Partial-failure policy: 'fail_fast' (default) cancels the run on the
        // first node error; 'best_effort' lets independent branches continue and
        // fan-in nodes assemble from whatever predecessors completed.
        $context['failure_policy'] = ($flow->graph_layout['failure_policy'] ?? 'fail_fast') === 'best_effort'
            ? 'best_effort'
            : 'fail_fast';

        $flowRun->update([
            'status'         => 'running',
            'context'        => $context,
            'started_at'     => now(),
            // Snapshot the Drawflow graph_layout at the moment the run starts so
            // the historical run viewer can show the exact graph that was executed
            // even if the user edits the flow afterwards.
            'graph_snapshot' => $flow->graph_layout,
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

        $nodeIds = FlowNode::where('flow_id', $flowRun->flow_id)
            ->whereIn('node_key', $waves[$index])
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
    }

    private function terminalAssembly(FlowRun $flowRun): string
    {
        $fromKeys = $flowRun->flow->edges()->pluck('from_node_key')->unique()->all();
        $terminalKeys = $flowRun->flow->nodes()
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
     * @return array<string,string>
     */
    private function buildSeed(Flow $flow): array
    {
        $company = $flow->company;
        $targetUrl = UrlExtractor::first($flow->description ?? '') ?? '';
        $isSiteFlow = $targetUrl !== '';

        return [
            'company_description' => $isSiteFlow ? '' : ($company?->description ?? ''),
            'company_name' => $isSiteFlow ? '' : ($company?->name ?? ''),
            'company_industry' => $isSiteFlow ? '' : ($company?->industry ?? ''),
            'input' => $flow->topic ?? '',
            'topic' => $flow->topic ?? '',
            'flow_topic' => $flow->topic ?? '',
            'url' => $targetUrl,
            'target_url' => $targetUrl,
            'website' => $targetUrl,
        ];
    }
}
