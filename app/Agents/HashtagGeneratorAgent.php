<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

class HashtagGeneratorAgent extends BaseAgent
{
    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $raw = $this->chat($agent, $agentRun->input);

        // Keep only lines that start with # or contain #word patterns
        $lines    = explode("\n", $raw);
        $hashtags = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Line starts with # or contains at least one hashtag token
            if (str_starts_with($line, '#') || preg_match('/#\w+/', $line)) {
                $hashtags[] = $line;
            }
        }

        if (empty($hashtags)) {
            // Fallback: return raw LLM output if no hashtag lines were found
            return trim($raw);
        }

        return implode("\n", $hashtags);
    }
}
