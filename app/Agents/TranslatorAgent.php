<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

class TranslatorAgent extends BaseAgent
{
    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        return $this->chat($agent, $agentRun->input);
    }
}
