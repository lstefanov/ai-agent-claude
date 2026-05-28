<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

class OrchestratorAgent extends BaseAgent
{
    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $contextSummary = collect($context)
            ->map(fn($v, $k) => "{$k}: " . (is_string($v) ? mb_substr($v, 0, 200) : json_encode($v)))
            ->implode("\n");

        $prompt = $this->buildPrompt($agent, array_merge($context, ['context_summary' => $contextSummary]));
        return $this->chat($agent, $prompt);
    }
}
