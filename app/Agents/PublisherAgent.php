<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

class PublisherAgent extends BaseAgent
{
    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $prompt = $this->buildPrompt($agent, $context);
        return $this->chat($agent, $prompt);
    }
}
