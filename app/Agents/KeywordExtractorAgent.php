<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

class KeywordExtractorAgent extends BaseAgent
{
    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $topic = ! empty($context['flow_topic'])
            ? $context['flow_topic']
            : mb_substr($agentRun->input, 0, 300);

        $queries = [
            "{$topic} keywords",
            "{$topic} SEO terms",
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
                . "\n\nBased on the search results above, extract and rank 10-20 keywords most relevant to the topic."
                . "\nFor each keyword, indicate the search intent:"
                . "\n- Informational (user wants to learn)"
                . "\n- Commercial (user is comparing options)"
                . "\n- Transactional (user is ready to buy/act)"
                . "\nFormat as a numbered list: keyword — intent — brief reason";
        }

        return $this->chat($agent, $agentRun->input, $extraContext);
    }
}
