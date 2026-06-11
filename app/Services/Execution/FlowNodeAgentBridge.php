<?php

namespace App\Services\Execution;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\FlowNode;
use App\Models\FlowRun;
use App\Models\LlmModel;
use App\Services\ModelSelectorService;
use App\Support\PaidModel;

/**
 * Transient Agent / AgentRun bridge — keeps all concrete agents untouched.
 * FlowNode/FlowRun are mapped onto in-memory DTOs (never persisted), and the
 * node's model is resolved/auto-substituted to something actually runnable.
 */
class FlowNodeAgentBridge
{
    public function __construct(
        private ModelSelectorService $modelSelector,
    ) {}

    public function bridgeAgent(FlowNode $node): Agent
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

    public function bridgeRun(FlowRun $flowRun, string $input): AgentRun
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

    public function ensureModelInstalled(Agent $agent): void
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
