<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

class ResearcherAgent extends BaseAgent
{
    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        // Prefer an explicit target site URL when the flow is about a specific website,
        // so the researcher actually investigates that site rather than a generic topic.
        $query = $context['target_url']
            ?? $context['url']
            ?? null;
        if (empty($query)) {
            $query = !empty($context['flow_topic']) ? $context['flow_topic'] : mb_substr($agentRun->input, 0, 200);
        }
        $searchResults = $this->useTool('web_search', ['query' => $query]);

        $extraContext = '';
        if ($searchResults !== null) {
            $extraContext = "\n\n--- WEB SEARCH RESULTS (use these as your primary source. For every fact or news item you include in your report, cite the original source URL in parentheses next to it) ---\n" . $searchResults;
        }

        return $this->chat($agent, $agentRun->input, $extraContext);
    }
}
