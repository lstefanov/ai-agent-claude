<?php

namespace App\Services\Execution;

use App\Agents\AgentFactory;
use App\Agents\QaVerifierAgent;
use App\Models\Agent;
use App\Models\FlowNode;
use App\Models\FlowRun;
use App\Models\NodeRun;
use App\Services\ModelSelectorService;
use Throwable;

/**
 * Step-QA gate: resolves a node's qa config and runs the verifier INLINE
 * (dedicated verifier node or one synthesized from the gate's own criteria) —
 * not as a separate queue job. Scores land in context['step_qa_results'].
 */
class StepQaGate
{
    public const DEFAULT_QA_THRESHOLD = 60;

    public function __construct(
        private AgentFactory $factory,
        private ModelSelectorService $modelSelector,
        private FlowNodeAgentBridge $bridge,
    ) {}

    /**
     * @return array{threshold: int, max_retries: int, verifier_node_key: string, custom_prompt: string}|null
     */
    public function configFor(FlowNode $node): ?array
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
    public function verify(
        FlowRun $flowRun,
        FlowNode $node,
        NodeRun $nodeRun,
        array $qaConfig,
        string $nodeOutput
    ): array {
        $verifierKey = (string) ($qaConfig['verifier_node_key'] ?? '');
        $verifierNode = $verifierKey !== ''
            ? FlowNode::where('flow_version_id', $node->flow_version_id)->where('node_key', $verifierKey)->first()
            : null;

        // Build context for the verifier: the subject node's output PLUS the
        // material the node actually sent to its model (params_snapshot is
        // persisted by runOnce before the gate runs). Without the source,
        // "пълнота спрямо подадените данни" is unjudgeable — run 102 scored a
        // 85-char verdict 90/100 because the verifier never saw the 26K input.
        $sourceInput = (string) (data_get($nodeRun->params_snapshot, 'user_message') ?: $nodeRun->input);
        $agentContext = array_merge(
            $flowRun->context['seed'] ?? [],
            [
                'input' => $nodeOutput,
                'source_input' => mb_substr($sourceInput, 0, 6000),
                'source_len' => mb_strlen($sourceInput),
            ]
        );

        // Use a dedicated verifier node when one is referenced; otherwise
        // synthesize the verifier from the gate's own criteria (the default for
        // planner-generated flows — no separate node to lay out).
        $verifierAgent = $verifierNode
            ? $this->bridge->bridgeAgent($verifierNode)
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
        $verifierBridgeRun = $this->bridge->bridgeRun($flowRun, $nodeOutput);

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
}
