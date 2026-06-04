<?php

namespace App\Services;

use App\Agents\AgentFactory;
use App\Agents\QaVerifierAgent;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowRun;
use App\Models\LlmModel;
use App\Models\NodeRun;
use App\Support\PricingOutputMetrics;
use App\Support\ReasoningStripper;
use RuntimeException;
use Throwable;

/**
 * Executes a single graph node. Responsibilities:
 *
 *  - Input assembled from direct predecessors' node_run.output (namespaced,
 *    never a flat mutating array → no information loss across the DAG).
 *  - Technical retry ×3 on transient errors (Ollama timeout, etc.).
 *  - Optional step-QA gate with retry: if config['qa']['verifier_node_key'] is
 *    set and the verifier score < threshold, the node is re-executed up to
 *    max_retries times. Verifier runs inline (not as a separate queue job).
 *  - Output written namespaced to node_runs.output — never overwritten.
 *
 * All concrete agents (BaseAgent subclasses) stay untouched. We bridge
 * FlowNode/NodeRun → transient Agent/AgentRun so agent internals need no changes.
 */
class NodeExecutorService
{
    private const DEFAULT_QA_THRESHOLD = 60;

    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private AgentFactory $factory,
        private ModelSelectorService $modelSelector,
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

        // Idempotency — a re-dispatched job must not re-run a completed node.
        if ($nodeRun->exists && $nodeRun->status === 'completed') {
            return;
        }

        $this->runWithQaRetry($flowRun, $node, $nodeRun);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Core execution + step-QA retry loop
    // ──────────────────────────────────────────────────────────────────────

    private function runWithQaRetry(FlowRun $flowRun, FlowNode $node, NodeRun $nodeRun): void
    {
        $qaConfig = $this->resolvedQaConfig($node);
        $maxQaRetries = $qaConfig ? min(10, max(0, (int) ($qaConfig['max_retries'] ?? 3))) : 0;
        $qaRetriesUsed = 0;

        while (true) {
            $output = $this->runOnce($flowRun, $node, $nodeRun);

            if (! $qaConfig) {
                // No step-QA — done.
                return;
            }

            $qaResult = $this->runVerifierInline($flowRun, $node, $nodeRun, $qaConfig, $output);

            if ($qaResult['passed']) {
                return;
            }

            if ($qaRetriesUsed < $maxQaRetries) {
                $qaRetriesUsed++;
                // Reset the node_run for the retry attempt.
                $nodeRun->update([
                    'status' => 'running',
                    'output' => null,
                    'raw_output' => null,
                    'error' => null,
                    'started_at' => now(),
                ]);

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
     */
    private function runOnce(FlowRun $flowRun, FlowNode $node, NodeRun $nodeRun): string
    {
        $ctx = $this->buildNodeInput($flowRun, $node);
        $input = $this->renderPrompt($node, $ctx);

        $nodeRun->fill([
            'node_key' => $node->node_key,
            'status' => 'running',
            'input' => $input,
            'model_used' => $node->model,
            'error' => null,
            'started_at' => $nodeRun->started_at ?? now(),
        ])->save();

        $agent = $this->bridgeAgent($node);
        $this->ensureModelInstalled($agent);

        $bridgeRun = $this->bridgeRun($flowRun, $input);
        $agentContext = $this->agentContext($ctx);

        $lastError = null;
        $output = null;
        $rawOutput = null;
        $agentInstance = null;
        $startMs = now()->valueOf();

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

        if ($lastError !== null) {
            $message = "Node {$node->name} failed after ".self::MAX_ATTEMPTS.' attempts: '.$lastError->getMessage();
            $nodeRun->update([
                'status' => 'failed',
                'error' => $message,
                'duration_ms' => $durationMs,
                'completed_at' => now(),
            ]);

            throw new RuntimeException($message, previous: $lastError);
        }

        $nodeRun->update([
            'status' => 'completed',
            'output' => $output,
            'raw_output' => $rawOutput !== $output ? $rawOutput : null,
            'quality_metrics' => PricingOutputMetrics::fromOutput($output),
            'params_snapshot' => $agentInstance?->chatParams(),
            'duration_ms' => $durationMs,
            'completed_at' => now(),
        ]);

        return $output;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Step-QA gate (inline verifier node execution)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @return array{threshold: int, max_retries: int, verifier_node_key: string}|null
     */
    private function resolvedQaConfig(FlowNode $node): ?array
    {
        $qa = $node->config['qa'] ?? [];

        if (! filter_var($qa['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $verifierKey = trim((string) ($qa['verifier_node_key'] ?? ''));
        if ($verifierKey === '') {
            return null;
        }

        return [
            'verifier_node_key' => $verifierKey,
            'threshold' => (int) ($qa['threshold'] ?? self::DEFAULT_QA_THRESHOLD),
            'max_retries' => min(10, max(0, (int) ($qa['max_retries'] ?? 3))),
        ];
    }

    /**
     * @return array{passed: bool, score: int}
     */
    private function runVerifierInline(
        FlowRun $flowRun,
        FlowNode $node,
        NodeRun $nodeRun,
        array $qaConfig,
        string $nodeOutput
    ): array {
        $verifierNode = FlowNode::where('flow_id', $node->flow_id)
            ->where('node_key', $qaConfig['verifier_node_key'])
            ->first();

        if (! $verifierNode) {
            // Verifier node missing → skip gate (don't fail).
            return ['passed' => true, 'score' => 100];
        }

        // Build context for the verifier: include the subject node's output.
        $agentContext = array_merge(
            $flowRun->context['seed'] ?? [],
            ['input' => $nodeOutput]
        );

        $verifierAgent = $this->bridgeAgent($verifierNode);
        // Override qa_threshold so QaVerifierAgent builds its system prompt with
        // the threshold from the writer's QA config (not the verifier's own setting).
        $verifierAgent->qa_threshold = $qaConfig['threshold'];
        $verifierBridgeRun = $this->bridgeRun($flowRun, $nodeOutput);

        try {
            $verifierInstance = $this->factory->make($verifierAgent);
            $verifierOutput = $verifierInstance->run($verifierAgent, $verifierBridgeRun, $agentContext);

            $score = $verifierInstance instanceof QaVerifierAgent
                ? $verifierInstance->extractScore($verifierBridgeRun)
                : $this->extractScoreFromOutput($verifierOutput);
        } catch (Throwable $e) {
            // Verifier failure → treat as passed so the node isn't blocked.
            return ['passed' => true, 'score' => 100];
        }

        // Record QA results in the run context for UI display.
        $this->recordQaResult($flowRun, $node, $verifierNode, $score, $qaConfig);

        $passed = $score >= $qaConfig['threshold'];

        return ['passed' => $passed, 'score' => $score];
    }

    private function recordQaResult(
        FlowRun $flowRun,
        FlowNode $node,
        FlowNode $verifier,
        int $score,
        array $qaConfig
    ): void {
        $context = $flowRun->fresh()->context ?? [];
        $context['step_qa_results'] ??= [];
        $context['step_qa_results'][$node->node_key] = [
            'verifier_node_key' => $verifier->node_key,
            'score' => $score,
            'threshold' => $qaConfig['threshold'],
        ];
        $flowRun->update(['context' => $context]);
    }

    private function extractScoreFromOutput(string $output): int
    {
        preg_match('/\b(\d{1,3})\b/', $output, $m);

        return isset($m[1]) ? min(100, max(0, (int) $m[1])) : 0;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Context assembly — the heart of the no-information-loss change
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @return array{seed: array<string,mixed>, upstream: array<string,string>}
     *   upstream keyed by predecessor node NAME (falling back to node_key).
     */
    private function buildNodeInput(FlowRun $flowRun, FlowNode $node): array
    {
        $predecessorKeys = FlowEdge::where('flow_id', $node->flow_id)
            ->where('to_node_key', $node->node_key)
            ->pluck('from_node_key')
            ->all();

        $upstream = [];
        if (! empty($predecessorKeys)) {
            $names = FlowNode::where('flow_id', $node->flow_id)
                ->whereIn('node_key', $predecessorKeys)
                ->pluck('name', 'node_key');

            $runs = NodeRun::where('flow_run_id', $flowRun->id)
                ->whereIn('node_key', $predecessorKeys)
                ->where('status', 'completed')
                ->get(['node_key', 'output']);

            foreach ($runs as $run) {
                if ($run->output === null || $run->output === '') {
                    continue;
                }
                $label = $names[$run->node_key] ?? $run->node_key;
                $upstream[$label] = $run->output;
            }
        }

        return [
            'seed' => $flowRun->context['seed'] ?? [],
            'upstream' => $upstream,
        ];
    }

    /**
     * Build the user message: seed placeholders, explicit {{node:Name}} refs, and
     * a full named block of every not-yet-inlined predecessor output — so a fan-in
     * node sees ALL of them. Clean output (reasoning stripped) is passed downstream.
     */
    private function renderPrompt(FlowNode $node, array $ctx): string
    {
        $prompt = $node->prompt_template ?? '';
        $original = $prompt;

        foreach ($ctx['seed'] as $k => $v) {
            if (is_string($v)) {
                $prompt = str_replace(['{{'.$k.'}}', '{'.$k.'}'], $v, $prompt);
            }
        }

        // Explicit {{node:Name}} inline substitution — runs for ALL node types including
        // bg_text_corrector (so the prompt template can reference specific predecessors).
        foreach ($ctx['upstream'] as $label => $output) {
            $prompt = str_replace('{{node:'.$label.'}}', $output, $prompt);
        }

        // bg_text_corrector only gets its rendered prompt — no auto-appended context.
        if ($node->type === 'bg_text_corrector') {
            return $prompt;
        }

        // Append all not-yet-inlined upstream outputs as named blocks.
        $blocks = [];
        foreach ($ctx['upstream'] as $label => $output) {
            if (str_contains($original, '{{node:'.$label.'}}')) {
                continue; // already inlined explicitly
            }
            if ($this->promptReferencesKey($original, $label)) {
                continue; // referenced via {{AgentName}} placeholder
            }
            if (str_contains($prompt, $output)) {
                continue; // already present (e.g. inlined via seed)
            }
            $blocks[] = "[{$label}]:\n".$this->handoffText($output);
        }

        if ($blocks) {
            $prompt .= "\n\n--- Context from previous agents ---\n".implode("\n\n", $blocks);
        }

        return $prompt;
    }

    private function promptReferencesKey(string $prompt, string $key): bool
    {
        return str_contains($prompt, '{{'.$key.'}}')
            || str_contains($prompt, '{'.$key.'}');
    }

    /**
     * Flat context array passed as agent's 3rd argument, mirroring the legacy
     * shape (seed keys + predecessor outputs keyed by name + input alias).
     */
    private function agentContext(array $ctx): array
    {
        $context = $ctx['seed'];

        foreach ($ctx['upstream'] as $label => $output) {
            $context[$label] = $output;
        }

        // `input` alias — union of all upstream outputs.
        if (! empty($ctx['upstream'])) {
            $context['input'] = implode("\n\n", array_values($ctx['upstream']));
        }

        return $context;
    }

    private function handoffText(string $output, int $maxChars = 60000): string
    {
        if (mb_strlen($output) <= $maxChars) {
            return $output;
        }

        return mb_substr($output, 0, $maxChars)."\n\n[Truncated after {$maxChars} chars for node handoff.]";
    }

    // ──────────────────────────────────────────────────────────────────────
    // Transient Agent / AgentRun bridge — keeps all concrete agents untouched
    // ──────────────────────────────────────────────────────────────────────

    private function bridgeAgent(FlowNode $node): Agent
    {
        $agent = new Agent;
        $agent->forceFill([
            'type' => $node->type,
            'name' => $node->name,
            'role' => $node->role,
            'prompt_template' => $node->prompt_template,
            'system_prompt' => $node->system_prompt,
            'model' => $node->model,
            'config' => $node->config ?? [],
            'is_verifier' => $node->type === 'qa_verifier',
            'qa_threshold' => $node->config['qa']['threshold'] ?? null,
            'output_language' => $node->output_language,
            'output_tone' => $node->output_tone,
            'output_style' => $node->output_style,
            'output_format' => $node->output_format,
            'output_role' => $node->output_role,
            // BgTextCorrectorAgent::findBodyContent() queries Agent by flow_id + order.
            // Set flow_id to the real value so the WHERE clause is valid (even though
            // no legacy Agent rows exist). With order=999 the query returns 0 results
            // and the agent falls through to the context-based heuristic.
            'flow_id' => $node->flow_id,
            'order' => 999,
        ]);
        $agent->exists = false;

        return $agent;
    }

    private function bridgeRun(FlowRun $flowRun, string $input): AgentRun
    {
        $run = new AgentRun;
        $run->forceFill([
            'flow_run_id' => $flowRun->id,
            'input' => $input,
        ]);
        // exists=true so QaVerifierAgent's $run->update(['tokens_used'=>$score])
        // writes to the in-memory attribute (the stray UPDATE hits no real row — harmless).
        $run->exists = true;

        return $run;
    }

    private function ensureModelInstalled(Agent $agent): void
    {
        if (! config('services.ollama.auto_pull', true) || ! $agent->model) {
            return;
        }

        if (LlmModel::where('ollama_tag', $agent->model)->where('is_available', true)->exists()) {
            return;
        }

        $replacement = $this->modelSelector->resolveRunnable($agent->type, $agent->name.' '.($agent->role ?? ''));
        if ($replacement && $replacement !== $agent->model) {
            $agent->model = $replacement;
        }
    }
}
