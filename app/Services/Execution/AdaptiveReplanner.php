<?php

namespace App\Services\Execution;

use App\Models\FlowNode;
use App\Models\FlowRun;
use App\Models\NodeRun;
use App\Services\FlowPlannerService;
use App\Support\LlmContext;
use App\Support\PaidModel;
use Illuminate\Support\Facades\Log;

/**
 * Фаза 3 — adaptive replanning + degenerate-output watchdog: when a node keeps
 * failing its QA gate (or emits placeholder boilerplate), the planner revises
 * the agent for THIS RUN only; a successful revision is offered in the run
 * viewer for one-click persisting into the graph.
 */
class AdaptiveReplanner
{
    public function __construct(
        private FlowPlannerService $planner,
    ) {}

    /**
     * Ask the planner to revise the failing agent. Returns null when adaptive
     * replanning is disabled/unavailable or the planner couldn't help.
     */
    public function requestRevision(FlowRun $flowRun, FlowNode $node, NodeRun $nodeRun, string $badOutput, string $reason): ?array
    {
        if (! config('services.planner.adaptive', true) || ! $this->planner->isAvailable()) {
            return null;
        }

        LlmContext::push([
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
            LlmContext::pop();
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

    /**
     * A revised node that subsequently PASSED gets its revision marked
     * 'succeeded' (with the full payload) in the run context. The run viewer
     * offers a one-click "Приложи в графа" that persists it to the flow.
     */
    public function recordRevisionSuccess(FlowRun $flowRun, FlowNode $node, ?array $revision): void
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
    public function applyRevision(FlowNode $node, array $revision): void
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
    public function looksDegenerate(string $output, FlowNode $node, int $upstreamMaxLen = 0): bool
    {
        // Utility nodes legitimately emit terse confirmations — don't watchdog them.
        if (in_array($node->type, ['webhook_sender', 'slack_notifier'], true)) {
            return false;
        }

        $trimmed = trim($output);

        if ($trimmed === '' || mb_strlen($trimmed) < 20) {
            return true;
        }

        // Кратка „присъда" вместо самия deliverable (run 102: gpt-4o върна
        // „Текстът е прегледан и няма правописни грешки" вместо доклада).
        if (mb_strlen($trimmed) < 200 && preg_match(
            '/няма\s+(правописни\s+|граматически\s+)?грешки|текстът\s+е\s+прегледан|всичко\s+е\s+(написано\s+)?правилно|no\s+(spelling\s+|grammar\s+)?errors/iu',
            $trimmed,
        )) {
            return true;
        }

        // Трансформер (коригира/превежда) трябва да възпроизведе горе-долу
        // входа си — драстично свиване значи резюме/отказ вместо трансформация.
        if ($upstreamMaxLen > 0
            && in_array($node->type, ['bg_text_corrector', 'translator'], true)
            && mb_strlen($trimmed) < (int) ($upstreamMaxLen * 0.5)) {
            return true;
        }

        return (bool) preg_match(
            '/example\.com|@example|lorem ipsum|отдел\s+„?\s*Маркетинг|Иван\s+Вазов["“”]?\s*123/iu',
            $trimmed,
        );
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
}
