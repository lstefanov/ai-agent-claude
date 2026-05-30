<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

class TrendResearcherAgent extends BaseAgent
{
    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $topic = ! empty($context['flow_topic'])
            ? $context['flow_topic']
            : mb_substr($agentRun->input, 0, 300);

        $year     = date('Y');
        $prevYear = $year - 1;

        $queries = [
            "trending topics {$topic} {$prevYear} {$year}",
            "{$topic} latest trends insights",
        ];

        $allResults = '';
        foreach ($queries as $i => $query) {
            $results = $this->useTool('web_search', ['query' => $query]);
            if ($results !== null) {
                $num        = $i + 1;
                $allResults .= "\n\n=== SEARCH {$num}: \"{$query}\" ===\n{$results}";
            }
        }

        $extraContext = '';
        if ($allResults) {
            $extraContext = "\n\n--- WEB SEARCH RESULTS ---\n{$allResults}"
                . "\n\nBased on the search results above, identify 5-10 trending topics, angles, or subtopics that are most relevant and timely. Format as a numbered list with a brief explanation for each.";
        }

        return $this->chat($agent, $agentRun->input, $extraContext);
    }
}
