<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

class QaVerifierAgent extends BaseAgent
{
    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $prompt = $this->buildPrompt($agent, $context);
        $response = $this->chat($agent, $prompt);

        // Extract numeric score from response (0-100)
        preg_match('/\b(\d{1,3})\b/', $response, $matches);
        $score = isset($matches[1]) ? (int) $matches[1] : 0;
        $score = min(100, max(0, $score));

        // Store score on agentRun metadata via tokens_used field reuse — we encode in output
        $agentRun->update(['tokens_used' => $score]);

        return $response;
    }

    public function extractScore(AgentRun $agentRun): int
    {
        return (int) $agentRun->tokens_used;
    }
}
