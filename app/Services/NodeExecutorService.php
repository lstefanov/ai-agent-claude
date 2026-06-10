<?php

namespace App\Services;

use App\Agents\AgentFactory;
use App\Agents\DecisionAgent;
use App\Agents\QaVerifierAgent;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowRun;
use App\Models\LlmModel;
use App\Models\NodeRun;
use App\Support\LlmContext;
use App\Support\LlmUsage;
use App\Support\PaidModel;
use App\Support\PricingOutputMetrics;
use App\Support\ReasoningStripper;
use Illuminate\Support\Facades\Log;
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
        private FlowPlannerService $planner,
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
        // Фаза 3: revision applied for THIS RUN only (the saved graph stays
        // untouched — the suggestion is recorded in the run context instead).
        $revision = null;

        while (true) {
            $output = $this->runOnce($flowRun, $node, $nodeRun, $revision);

            // Watchdog: degenerate output (empty/placeholder boilerplate) is a
            // failure even before QA — try one planner revision immediately.
            if ($revision === null && $this->looksDegenerate($output, $node)) {
                $revision = $this->requestRevision($flowRun, $node, $nodeRun, $output, 'Изходът е изроден: празен/твърде кратък или съдържа шаблонен placeholder текст.');
                if ($revision !== null) {
                    $this->resetForRetry($nodeRun);

                    continue;
                }
            }

            if (! $qaConfig) {
                // No step-QA — done. A revision that produced a healthy output
                // is a success — offer it for persisting into the graph.
                $this->recordRevisionSuccess($flowRun, $node, $revision);

                return;
            }

            $qaResult = $this->runVerifierInline($flowRun, $node, $nodeRun, $qaConfig, $output);

            if ($qaResult['passed']) {
                $this->recordRevisionSuccess($flowRun, $node, $revision);

                return;
            }

            if ($qaRetriesUsed < $maxQaRetries) {
                $qaRetriesUsed++;

                // First retry is plain (cheap). From the second on, ask the
                // planner to revise the failing agent (Фаза 3).
                if ($qaRetriesUsed >= 2 && $revision === null) {
                    $revision = $this->requestRevision(
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
    private function runOnce(FlowRun $flowRun, FlowNode $node, NodeRun $nodeRun, ?array $revision = null): string
    {
        if ($revision !== null) {
            $this->applyRevision($node, $revision);
        }

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
                'error' => $message,
                'duration_ms' => $durationMs,
                'completed_at' => now(),
            ]));

            throw new RuntimeException($message, previous: $lastError);
        }

        $nodeRun->update(array_merge($usage, [
            'status' => 'completed',
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

    // ──────────────────────────────────────────────────────────────────────
    // Фаза 3 — adaptive replanning + degenerate-output watchdog
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Ask the planner to revise the failing agent. Returns null when adaptive
     * replanning is disabled/unavailable or the planner couldn't help.
     */
    private function requestRevision(FlowRun $flowRun, FlowNode $node, NodeRun $nodeRun, string $badOutput, string $reason): ?array
    {
        if (! config('services.planner.adaptive', true) || ! $this->planner->isAvailable()) {
            return null;
        }

        LlmContext::set([
            'purpose' => 'agent_revision',
            'company_id' => $flowRun->flow?->company_id,
            'flow_id' => $node->flow_id,
            'flow_run_id' => $flowRun->id,
            'node_run_id' => $nodeRun->id,
            'agent_name' => $node->name,
            'agent_type' => $node->type,
        ]);

        try {
            $revision = $this->planner->reviseAgent(
                [
                    'name' => $node->name,
                    'type' => $node->type,
                    'system_prompt' => (string) $node->system_prompt,
                    'prompt_template' => (string) $node->prompt_template,
                    'model' => (string) $node->model,
                    'temperature' => isset($node->config['temperature']) ? (float) $node->config['temperature'] : null,
                ],
                (string) $nodeRun->input,
                $badOutput,
                $reason,
            );
        } finally {
            LlmContext::clear();
        }

        if ($revision === null) {
            return null;
        }

        $escalationProvider = $revision['escalate'] ? $this->escalationProvider() : null;

        // Audit trail: visible in the run context (and thus the run viewer).
        $context = $flowRun->fresh()->context ?? [];
        $context['replan'][$node->node_key][] = [
            'trigger' => $reason,
            'planner_reason' => $revision['reason'],
            'escalated_to' => $escalationProvider,
            'at' => now()->toISOString(),
        ];
        $flowRun->update(['context' => $context]);

        Log::info("[NodeExecutor] Plan revision for {$node->name}: {$revision['reason']}"
            .($escalationProvider ? " (ескалация към {$escalationProvider})" : ''));

        return $revision;
    }

    /** The paid provider used for mid-run escalations, or null when unavailable. */
    private function escalationProvider(): ?string
    {
        $provider = (string) config('services.planner.escalation_provider', 'openai');

        if (! array_key_exists($provider, PaidModel::PREFIXES)) {
            $provider = 'openai';
        }

        return PaidModel::available($provider) ? $provider : null;
    }

    /**
     * A revised node that subsequently PASSED gets its revision marked
     * 'succeeded' (with the full payload) in the run context. The run viewer
     * offers a one-click "Приложи в графа" that persists it to the flow.
     */
    private function recordRevisionSuccess(FlowRun $flowRun, FlowNode $node, ?array $revision): void
    {
        if ($revision === null) {
            return;
        }

        $context = $flowRun->fresh()->context ?? [];
        $entries = $context['replan'][$node->node_key] ?? [];

        if ($entries === []) {
            return;
        }

        $lastIdx = array_key_last($entries);
        $entries[$lastIdx]['succeeded'] = true;
        $entries[$lastIdx]['payload'] = [
            'system_prompt' => $revision['system_prompt'],
            'prompt_template' => $revision['prompt_template'],
            'temperature' => $revision['temperature'],
            // The model actually used after a possible escalation.
            'model' => (string) $node->model,
        ];

        $context['replan'][$node->node_key] = $entries;
        $flowRun->update(['context' => $context]);
    }

    /** Apply a planner revision to the IN-MEMORY node for this run only. */
    private function applyRevision(FlowNode $node, array $revision): void
    {
        $node->system_prompt = $revision['system_prompt'];
        $node->prompt_template = $revision['prompt_template'];

        $config = $node->config ?? [];
        $config['temperature'] = $revision['temperature'];
        $node->config = $config;

        if (($revision['escalate'] ?? false)
            && ! PaidModel::isPaid($node->model)
            && ($provider = $this->escalationProvider()) !== null) {
            $node->model = PaidModel::pin($provider);
        }
    }

    /**
     * Watchdog: an output that is empty, suspiciously short, or contains the
     * classic placeholder boilerplate is treated as a failure before QA.
     */
    private function looksDegenerate(string $output, FlowNode $node): bool
    {
        // Utility nodes legitimately emit terse confirmations — don't watchdog them.
        if (in_array($node->type, ['webhook_sender', 'slack_notifier'], true)) {
            return false;
        }

        $trimmed = trim($output);

        if ($trimmed === '' || mb_strlen($trimmed) < 20) {
            return true;
        }

        return (bool) preg_match(
            '/example\.com|@example|lorem ipsum|отдел\s+„?\s*Маркетинг|Иван\s+Вазов["“”]?\s*123/iu',
            $trimmed,
        );
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
    // Step-QA gate (inline verifier node execution)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @return array{threshold: int, max_retries: int, verifier_node_key: string, custom_prompt: string}|null
     */
    private function resolvedQaConfig(FlowNode $node): ?array
    {
        $qa = $node->config['qa'] ?? [];

        if (! filter_var($qa['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        return [
            // Optional: a dedicated verifier node. Empty → the verifier is
            // synthesized from the criteria below (the default).
            'verifier_node_key' => trim((string) ($qa['verifier_node_key'] ?? '')),
            'threshold' => (int) ($qa['threshold'] ?? self::DEFAULT_QA_THRESHOLD),
            'max_retries' => min(10, max(0, (int) ($qa['max_retries'] ?? 3))),
            // Per-gate criteria — the gated node decides what "good" means.
            'custom_prompt' => trim((string) ($qa['custom_prompt'] ?? '')),
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
        $verifierKey = (string) ($qaConfig['verifier_node_key'] ?? '');
        $verifierNode = $verifierKey !== ''
            ? FlowNode::where('flow_id', $node->flow_id)->where('node_key', $verifierKey)->first()
            : null;

        // Build context for the verifier: include the subject node's output.
        $agentContext = array_merge(
            $flowRun->context['seed'] ?? [],
            ['input' => $nodeOutput]
        );

        // Use a dedicated verifier node when one is referenced; otherwise
        // synthesize the verifier from the gate's own criteria (the default for
        // planner-generated flows — no separate node to lay out).
        $verifierAgent = $verifierNode
            ? $this->bridgeAgent($verifierNode)
            : $this->syntheticVerifier($node);
        // Override qa_threshold so QaVerifierAgent builds its system prompt with
        // the threshold from this gate's config.
        $verifierAgent->qa_threshold = $qaConfig['threshold'];
        // Per-gate criteria win: QaVerifierAgent uses system_prompt as the
        // evaluation criteria, so the gated node's qa.custom_prompt decides what
        // "good" means for THIS gate.
        if (($qaConfig['custom_prompt'] ?? '') !== '') {
            $verifierAgent->system_prompt = $qaConfig['custom_prompt'];
        }
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
        $this->recordQaResult($flowRun, $node, $verifierNode?->node_key, $score, $qaConfig);

        $passed = $score >= $qaConfig['threshold'];

        return ['passed' => $passed, 'score' => $score];
    }

    /**
     * A QA verifier agent created on the fly from a node's own gate config — the
     * default when the gate doesn't reference a dedicated verifier node. The
     * criteria come from qa.custom_prompt (applied by the caller); the model is
     * the best installed QA model.
     */
    private function syntheticVerifier(FlowNode $node): Agent
    {
        $agent = new Agent;
        $agent->forceFill([
            'type' => 'qa_verifier',
            'name' => 'QA · '.$node->name,
            'model' => $this->modelSelector->resolveRunnable('qa_verifier', 'qa verifier'),
            'config' => [],
            'is_verifier' => true,
            'flow_id' => $node->flow_id,
        ]);
        $agent->exists = false;

        return $agent;
    }

    private function recordQaResult(
        FlowRun $flowRun,
        FlowNode $node,
        ?string $verifierKey,
        int $score,
        array $qaConfig
    ): void {
        $context = $flowRun->fresh()->context ?? [];
        $context['step_qa_results'] ??= [];
        $context['step_qa_results'][$node->node_key] = [
            'verifier_node_key' => $verifierKey, // null when synthesized
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
     *                                                                          upstream keyed by predecessor node NAME (falling back to node_key).
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
            'flow_id' => $node->flow_id,
        ]);
        $agent->exists = false;

        return $agent;
    }

    private function bridgeRun(FlowRun $flowRun, string $input): AgentRun
    {
        // Transient DTO — never persisted. QaVerifierAgent sets tokens_used
        // (the QA score) as a plain in-memory attribute.
        $run = new AgentRun;
        $run->forceFill([
            'flow_run_id' => $flowRun->id,
            'input' => $input,
        ]);

        return $run;
    }

    private function ensureModelInstalled(Agent $agent): void
    {
        // Paid-provider models (openai/*, anthropic/*) run remotely — nothing
        // to install or substitute locally.
        if (PaidModel::isPaid($agent->model)) {
            return;
        }

        // "(по подразбиране)" in the builder = empty model → the code picks the
        // best INSTALLED Ollama model for the agent's type at run time.
        if (! $agent->model) {
            $agent->model = $this->modelSelector->resolveRunnable($agent->type, $agent->name.' '.($agent->role ?? ''));

            return;
        }

        if (! config('services.ollama.auto_pull', true)) {
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
