<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

class CompetitorProfilerAgent extends BaseAgent
{
    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $competitor = ! empty($context['flow_topic'])
            ? $context['flow_topic']
            : mb_substr($agentRun->input, 0, 300);

        // Phase 1: Search for competitor information
        $searchQuery = "{$competitor} services pricing website";
        $searchResults = $this->useTool('web_search', ['query' => $searchQuery]);

        $allResults = '';
        if ($searchResults !== null) {
            $allResults = "\n\n=== SEARCH: \"{$searchQuery}\" ===\n{$searchResults}";
        }

        // Phase 2: Try to find and scrape their website
        $scrapedContent = '';
        if ($allResults && $this->hasTool('scrape_page')) {
            $competitorUrl = $this->extractFirstUrl($allResults);
            if ($competitorUrl) {
                $markdown = $this->useTool('scrape_page', ['url' => $competitorUrl]);
                if ($markdown && $markdown !== 'Scraping not available for this page.') {
                    $scrapedContent = "\n\n=== SCRAPED WEBSITE: {$competitorUrl} ===\n{$markdown}";
                }
            }
        }

        // Phase 3: Synthesize competitor profile
        $extraContext = '';
        if ($allResults) {
            $extraContext .= "\n\n--- WEB SEARCH RESULTS ---\n{$allResults}";
        }
        if ($scrapedContent) {
            $extraContext .= "\n\n--- SCRAPED WEBSITE CONTENT ---\n{$scrapedContent}";
        }
        if ($extraContext) {
            $extraContext .= "\n\nBased on the above data, build a structured competitor profile including:"
                . "\n- Company name"
                . "\n- Main services/products offered"
                . "\n- Pricing tier (budget/mid-range/premium)"
                . "\n- Key strengths"
                . "\n- Key weaknesses"
                . "\n- Market positioning"
                . "\n- Target audience";
        }

        return $this->chat($agent, $agentRun->input, $extraContext);
    }

    /**
     * Extract the first URL found in search results.
     * BraveSearchTool format: "URL: https://..."
     */
    private function extractFirstUrl(string $searchResults): ?string
    {
        preg_match('/URL:\s*(https?:\/\/\S+)/i', $searchResults, $matches);
        return $matches[1] ?? null;
    }
}
