<?php

namespace App\Services;

use App\Agents\AgentFactory;
use App\Agents\DecisionAgent;
use App\Models\FlowNode;
use App\Models\FlowRun;
use App\Models\NodeRun;
use App\Services\Execution\AdaptiveReplanner;
use App\Services\Execution\FlowNodeAgentBridge;
use App\Services\Execution\NodePromptBuilder;
use App\Services\Execution\StepQaGate;
use App\Support\LlmContext;
use App\Support\LlmUsage;
use App\Support\PricingOutputMetrics;
use App\Support\ReasoningStripper;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Executes a single graph node — the ORCHESTRATOR of the execution layer.
 * The specialised concerns live in collaborators (app/Services/Execution/):
 *
 *  - NodePromptBuilder — input assembled from direct predecessors' output
 *    (namespaced, never a flat mutating array → no information loss) and
 *    rendered into the prompt template.
 *  - StepQaGate — optional inline verifier with retry: score < threshold
 *    re-executes the node up to max_retries times.
 *  - AdaptiveReplanner — degenerate-output watchdog + planner revision of a
 *    failing agent (run-scoped only).
 *  - FlowNodeAgentBridge — FlowNode/NodeRun → transient Agent/AgentRun DTOs,
 *    so all concrete agents (BaseAgent subclasses) stay untouched.
 *
 * This class keeps: idempotency, the QA/dedup retry loop, technical retry ×3
 * on transient errors, cost/audit bookkeeping and run-level failure handling.
 */
class NodeExecutorService
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private AgentFactory $factory,
        private FlowMemoryService $memory,
        private FlowNodeAgentBridge $bridge,
        private StepQaGate $qaGate,
        private AdaptiveReplanner $replanner,
        private NodePromptBuilder $promptBuilder,
    ) {}

    // ──────────────────────────────────────────────────────────────────────
    // Public entry point called by ExecuteNodeJob
    // ──────────────────────────────────────────────────────────────────────

    public function executeNode(int $flowRunId, int $flowNodeId): void
    {
        $flowRun = FlowRun::findOrFail($flowRunId);
        $node = FlowNode::findOrFail($flowNodeId);

        $nodeRun = NodeRun::firstOrNew([
            'flow_run_id' => $flowRunId,
            'flow_node_id' => $flowNodeId,
        ]);

        // Idempotency — a re-dispatched job must not re-run a completed node,
        // and a paused approval node stays paused until the approval endpoint
        // settles it.
        if ($nodeRun->exists && in_array($nodeRun->status, ['completed', 'paused'], true)) {
            return;
        }

        // Human-in-the-loop: the node never executes an agent — it pauses the
        // run until a person approves/rejects via the run UI.
        if ($node->type === 'human_approval') {
            $this->pauseForApproval($flowRun, $node, $nodeRun);

            return;
        }

        $this->runWithQaRetry($flowRun, $node, $nodeRun);
    }

    /**
     * Pause the run on a human_approval node: the NodeRun goes 'paused' with
     * the upstream material as its input (so the approver sees WHAT they are
     * approving), the FlowRun goes 'waiting_approval' — which blocks
     * GraphFlowExecutor::dispatchWave() from advancing past this wave. The
     * job itself completes normally, so the wave batch still succeeds.
     */
    private function pauseForApproval(FlowRun $flowRun, FlowNode $node, NodeRun $nodeRun): void
    {
        $ctx = $this->promptBuilder->buildNodeInput($flowRun, $node);
        $input = $this->promptBuilder->renderPrompt($node, $ctx);

        $nodeRun->fill([
            'node_key' => $node->node_key,
            'status' => 'paused',
            'input' => $input,
            'error' => null,
            'started_at' => $nodeRun->started_at ?? now(),
        ])->save();

        $waveIndex = null;
        foreach ($flowRun->context['waves'] ?? [] as $i => $keys) {
            if (in_array($node->node_key, (array) $keys, true)) {
                $waveIndex = $i;
                break;
            }
        }

        $context = $flowRun->fresh()->context ?? [];
        $context['approvals'][$node->node_key] = [
            'status' => 'pending',
            'node_name' => $node->name,
            'wave_index' => $waveIndex,
            'requested_at' => now()->toISOString(),
        ];
        $flowRun->update(['context' => $context]);

        // Conditional flip keeps a concurrent failure final (status-only update,
        // safe as a query-builder write).
        FlowRun::whereKey($flowRun->id)
            ->where('status', 'running')
            ->update(['status' => 'waiting_approval']);

        Log::info("[NodeExecutor] {$node->name} чака одобрение от човек (run {$flowRun->id})");
    }

    // ──────────────────────────────────────────────────────────────────────
    // Core execution + step-QA retry loop
    // ──────────────────────────────────────────────────────────────────────

    private function runWithQaRetry(FlowRun $flowRun, FlowNode $node, NodeRun $nodeRun): void
    {
        $qaConfig = $this->qaGate->configFor($node);
        $maxQaRetries = $qaConfig ? min(10, max(0, (int) ($qaConfig['max_retries'] ?? 3))) : 0;
        $qaRetriesUsed = 0;
        // Фаза 3: revision applied for THIS RUN only (the saved graph stays
        // untouched — the suggestion is recorded in the run context instead).
        $revision = null;
        // Памет: a content output too similar to remembered content retries
        // with concrete feedback; after the cap it is accepted with a flag.
        $maxDedupRetries = min(5, max(0, (int) config('services.memory.dedup_max_retries', 2)));
        $dedupRetriesUsed = 0;
        $dedupFeedback = null;

        while (true) {
            $output = $this->runOnce($flowRun, $node, $nodeRun, $revision, $dedupFeedback);

            // Watchdog: degenerate output (empty/placeholder boilerplate) is a
            // failure even before QA — try one planner revision immediately.
            if ($revision === null && $this->replanner->looksDegenerate($output, $node)) {
                $revision = $this->replanner->requestRevision($flowRun, $node, $nodeRun, $output, 'Изходът е изроден: празен/твърде кратък или съдържа шаблонен placeholder текст.');
                if ($revision !== null) {
                    $this->resetForRetry($nodeRun);

                    continue;
                }
            }

            if (! $qaConfig) {
                if (($feedback = $this->dedupGate($flowRun, $node, $nodeRun, $output, $dedupRetriesUsed, $maxDedupRetries)) !== null) {
                    $dedupRetriesUsed++;
                    $dedupFeedback = $feedback;
                    $this->resetForRetry($nodeRun);

                    continue;
                }

                // No step-QA — done. A revision that produced a healthy output
                // is a success — offer it for persisting into the graph.
                $this->replanner->recordRevisionSuccess($flowRun, $node, $revision);

                return;
            }

            $qaResult = $this->qaGate->verify($flowRun, $node, $nodeRun, $qaConfig, $output);

            // Историята за model router-а: финалният QA score остава на
            // node_run-а (при retry следващата оценка го презаписва).
            $nodeRun->update(['qa_score' => max(0, min(100, (int) ($qaResult['score'] ?? 0)))]);

            if ($qaResult['passed']) {
                // Dedup gate AFTER the QA gate — no point embedding an output
                // QA would reject anyway. The retried output re-runs QA.
                if (($feedback = $this->dedupGate($flowRun, $node, $nodeRun, $output, $dedupRetriesUsed, $maxDedupRetries)) !== null) {
                    $dedupRetriesUsed++;
                    $dedupFeedback = $feedback;
                    $this->resetForRetry($nodeRun);

                    continue;
                }

                $this->replanner->recordRevisionSuccess($flowRun, $node, $revision);

                return;
            }

            if ($qaRetriesUsed < $maxQaRetries) {
                $qaRetriesUsed++;

                // First retry is plain (cheap). From the second on, ask the
                // planner to revise the failing agent (Фаза 3).
                if ($qaRetriesUsed >= 2 && $revision === null) {
                    $revision = $this->replanner->requestRevision(
                        $flowRun, $node, $nodeRun, $output,
                        "QA score {$qaResult['score']} < праг {$qaConfig['threshold']} (".($qaConfig['custom_prompt'] ?? 'обща проверка').')',
                    );
                }

                $this->resetForRetry($nodeRun);

                continue;
            }

            // Max retries exhausted — QA gate failed.
            // We handle this as a business-logic failure (not a thrown exception) so that:
            //  1. The NodeRun update is committed (no DB rollback from batch transaction).
            //  2. The ExecuteNodeJob returns normally → batch thinks job succeeded.
            //  3. The FlowRun is marked 'failed' here; subsequent dispatchWave calls
            //     see status ≠ 'running' and stop the chain.
            // Technical failures (Ollama down, etc.) still throw — those are caught by
            // the batch dispatcher and handled via the try/catch in dispatchWave.
            $message = "QA gate failed after {$maxQaRetries} retries for {$node->name}: "
                ."score {$qaResult['score']} < threshold {$qaConfig['threshold']}";
            $nodeRun->update(['status' => 'failed', 'error' => $message]);
            $this->failFlowRun($flowRun, $message);

            return;
        }
    }

    private function failFlowRun(FlowRun $flowRun, string $message): void
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
     * Execute the node exactly once. Returns the cleaned output string.
     * Throws RuntimeException after MAX_ATTEMPTS technical failures.
     *
     * A planner revision (Фаза 3) is applied to the in-memory node only —
     * never persisted to flow_nodes.
     */
    private function runOnce(FlowRun $flowRun, FlowNode $node, NodeRun $nodeRun, ?array $revision = null, ?string $dedupFeedback = null): string
    {
        if ($revision !== null) {
            $this->replanner->applyRevision($node, $revision);
        }

        $ctx = $this->promptBuilder->buildNodeInput($flowRun, $node);
        $input = $this->promptBuilder->renderPrompt($node, $ctx);

        // Памет: steer content nodes away from already-produced content and
        // give every node its distilled lessons. The blocks land in
        // node_runs.input — visible verbatim in the node-detail viewer.
        if (FlowMemoryService::enabled($flowRun->flow)) {
            if (($memoryBlock = $this->memory->outputMemoryBlock($flowRun->flow, $node)) !== '') {
                $input .= "\n\n".$memoryBlock;
            }
            if (($lessons = $this->memory->lessonsBlock($flowRun->flow, $node)) !== '') {
                $input .= "\n\n".$lessons;
            }
        }

        if ($dedupFeedback !== null) {
            $input .= "\n\n".$dedupFeedback;
        }

        $nodeRun->fill([
            'node_key' => $node->node_key,
            'status' => 'running',
            'input' => $input,
            'model_used' => $node->model,
            'error' => null,
            'started_at' => $nodeRun->started_at ?? now(),
        ])->save();

        $agent = $this->bridge->bridgeAgent($node);
        $this->bridge->ensureModelInstalled($agent);

        $bridgeRun = $this->bridge->bridgeRun($flowRun, $input);
        $agentContext = $this->promptBuilder->agentContext($ctx);

        $lastError = null;
        $output = null;
        $rawOutput = null;
        $agentInstance = null;
        $startMs = now()->valueOf();

        // Attribute every paid call this node makes (see LlmRequestRecorder).
        LlmContext::set([
            'purpose' => 'runtime',
            'company_id' => $flowRun->flow?->company_id,
            'flow_id' => $node->flow_id,
            'flow_run_id' => $flowRun->id,
            'node_run_id' => $nodeRun->id,
            'agent_name' => $node->name,
            'agent_type' => $node->type,
        ]);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                if ($attempt > 1) {
                    sleep(2);
                }
                $agentInstance = $this->factory->make($agent);
                $returned = $agentInstance->run($agent, $bridgeRun, $agentContext);
                $rawOutput = $agentInstance->rawOutput() ?? $returned;
                $output = ReasoningStripper::strip($rawOutput);
                $lastError = null;
                break;
            } catch (Throwable $e) {
                $lastError = $e;
            }
        }

        $durationMs = now()->valueOf() - $startMs;

        // Collect paid-provider usage accumulated during this node execution
        // (openai/* chat calls + any planner revision calls it triggered).
        $usage = LlmUsage::take();
        LlmContext::clear();

        if ($lastError !== null) {
            $message = "Node {$node->name} failed after ".self::MAX_ATTEMPTS.' attempts: '.$lastError->getMessage();
            $nodeRun->update(array_merge($usage, [
                'status' => 'failed',
                // The resolved model (auto-select leaves node->model empty).
                'model_used' => $agentInstance?->chatParams()['model'] ?? $node->model,
                'error' => $message,
                'duration_ms' => $durationMs,
                'completed_at' => now(),
            ]));

            throw new RuntimeException($message, previous: $lastError);
        }

        $nodeRun->update(array_merge($usage, [
            'status' => 'completed',
            // The resolved model (auto-select leaves node->model empty).
            'model_used' => $agentInstance?->chatParams()['model'] ?? $node->model,
            'output' => $output,
            'raw_output' => $rawOutput !== $output ? $rawOutput : null,
            'quality_metrics' => PricingOutputMetrics::fromOutput($output),
            'params_snapshot' => $agentInstance?->chatParams(),
            'duration_ms' => $durationMs,
            'completed_at' => now(),
        ]));

        // WS4: a decision node records which branch it picked so GraphFlowExecutor
        // can prune the not-taken branches in later waves.
        if ($agentInstance instanceof DecisionAgent && ($port = $agentInstance->chosenBranch()) !== null) {
            $this->recordDecision($flowRun, $node, $port);
        }

        return $output;
    }

    /** Persist a decision node's chosen output port into the run context. */
    private function recordDecision(FlowRun $flowRun, FlowNode $node, string $port): void
    {
        $context = $flowRun->fresh()->context ?? [];
        $context['decisions'][$node->node_key] = $port;
        $flowRun->update(['context' => $context]);

        Log::info("[NodeExecutor] Decision {$node->name} → {$port}");
    }

    private function resetForRetry(NodeRun $nodeRun): void
    {
        $nodeRun->update([
            'status' => 'running',
            'output' => null,
            'raw_output' => null,
            'error' => null,
            'started_at' => now(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Памет — post-generation dedup gate (enforces "не повтаряй" with retries)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Returns retry feedback when the output is too similar to remembered
     * content, null when the node may finish. Never fails the run — exhausted
     * retries accept the output with an 'accepted_flagged' audit entry.
     */
    private function dedupGate(
        FlowRun $flowRun,
        FlowNode $node,
        NodeRun $nodeRun,
        string $output,
        int $retriesUsed,
        int $maxRetries
    ): ?string {
        if (! FlowMemoryService::enabled($flowRun->flow) || ! $this->memory->isContentNode($node)) {
            return null;
        }

        $match = $this->memory->similarityCheck($flowRun->flow, $output, [
            'company_id' => $flowRun->flow?->company_id,
            'flow_id' => $node->flow_id,
            'flow_run_id' => $flowRun->id,
            'node_run_id' => $nodeRun->id,
            'agent_name' => $node->name,
            'agent_type' => $node->type,
        ]);

        // Fold the embed call's usage into this node's cost columns — runOnce
        // already took its own accumulation, so without this the worker's next
        // unit of work would inherit it.
        $usage = LlmUsage::take();
        if (($usage['cost_usd'] ?? null) !== null) {
            $nodeRun->update([
                'prompt_tokens' => (int) $nodeRun->prompt_tokens + (int) ($usage['prompt_tokens'] ?? 0),
                'cost_usd' => round((float) $nodeRun->cost_usd + (float) $usage['cost_usd'], 6),
            ]);
        }

        if ($match === null) {
            return null;
        }

        $pct = (int) round($match['similarity'] * 100);
        $action = $retriesUsed < $maxRetries ? 'retry' : 'accepted_flagged';

        // Audit trail for the run viewer (mirrors the replan pattern).
        $context = $flowRun->fresh()->context ?? [];
        $context['memory_dedup'][$node->node_key][] = [
            'similarity' => $match['similarity'],
            'matched_title' => $match['title'],
            'action' => $action,
            'at' => now()->toISOString(),
        ];
        $flowRun->update(['context' => $context]);

        if ($action === 'accepted_flagged') {
            Log::info("[NodeExecutor] Памет: {$node->name} е {$pct}% подобен на „{$match['title']}“ — приет с предупреждение (изчерпани retry-та)");

            return null;
        }

        Log::info("[NodeExecutor] Памет: {$node->name} е {$pct}% подобен на „{$match['title']}“ — повторен опит с feedback");

        return "ВНИМАНИЕ: Предишният ти вариант е {$pct}% подобен на вече създадено съдържание: "
            ."„{$match['title']}“ ({$match['summary']}). Това надхвърля допустимите 30-40% припокриване. "
            .'Напиши СЪЩЕСТВЕНО различен вариант — нова тема/ъгъл, нови заглавия и формулировки.';
    }
}
