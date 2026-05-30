<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

class MultiResearcherAgent extends BaseAgent
{
    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $config = $agent->config ?? [];

        // Read custom queries from agent config, or fall back to auto-generated ones
        $queryTemplates = $config['search_queries'] ?? [];

        if (empty($queryTemplates)) {
            $queryTemplates = $this->generateSearchQueries($agent, $agentRun->input, $context);
        } else {
            // Substitute {{variables}} in query templates
            foreach ($queryTemplates as &$tpl) {
                foreach ($context as $key => $value) {
                    if (is_string($value)) {
                        $tpl = str_replace('{{' . $key . '}}', $value, $tpl);
                    }
                }
            }
        }

        $allResults = '';
        foreach ($queryTemplates as $i => $query) {
            $results = $this->useTool('web_search', ['query' => $query]);
            if ($results !== null) {
                $num = $i + 1;
                $allResults .= "\n\n=== SEARCH {$num}: \"{$query}\" ===\n{$results}";
            }
        }

        $extraContext = $allResults
            ? "\n\n--- WEB SEARCH RESULTS (preserve EXACT competitor names, EXACT prices, EXACT service names — cite source URL next to every price) ---\n{$allResults}"
            : '';

        return $this->chat($agent, $agentRun->input, $extraContext);
    }

    /**
     * Ask the LLM to generate N focused search queries from the topic.
     * Returns an array of query strings.
     */
    private function generateSearchQueries(Agent $agent, string $input, array $context): array
    {
        $count = (int) ($agent->config['search_queries_count'] ?? 4);
        $topic = $context['flow_topic'] ?? mb_substr($input, 0, 300);

        $systemPrompt = 'You are a search query specialist. Output ONLY a JSON array of strings. No explanation.';
        $userMessage  = "Generate {$count} specific search queries to thoroughly research this topic from multiple angles.\n\nTopic: {$topic}\n\nRules:\n- Each query must target a SPECIFIC aspect, source type, or subtopic\n- Include the market/location if evident from the topic\n- Queries must be in the same language as the topic\n- Output ONLY valid JSON array, example: [\"query 1\",\"query 2\"]";

        $raw = $this->ollama->chat(
            model: $agent->model,
            systemPrompt: $systemPrompt,
            userMessage: $userMessage,
            options: ['temperature' => 0.3]
        );

        // Extract JSON array from response
        if (preg_match('/\[.*\]/s', $raw, $m)) {
            $queries = json_decode($m[0], true);
            if (is_array($queries) && count($queries) > 0) {
                return array_slice($queries, 0, $count);
            }
        }

        // Fallback: single query from topic
        return [$topic];
    }
}
