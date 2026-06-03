<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

class HashtagGeneratorAgent extends BaseAgent
{
    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $raw = $this->chat($agent, $agentRun->input);

        // Extract every #token regardless of line/spacing structure.
        // \p{L}\p{N} matches any Unicode letter/digit, including Cyrillic.
        preg_match_all('/#[\p{L}\p{N}_]+/u', $raw, $matches);
        $tokens = $matches[0];

        if (empty($tokens)) {
            return trim($raw);
        }

        // Deduplicate case-insensitively, preserving first occurrence.
        $seen     = [];
        $hashtags = [];
        foreach ($tokens as $tag) {
            $key = mb_strtolower($tag);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $hashtags[] = $tag;
            }
        }

        // Hard cap — prevents LLM repetition loops from polluting downstream context.
        return implode(' ', array_slice($hashtags, 0, 30));
    }
}
