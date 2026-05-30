<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

class ReviewAnalyzerAgent extends BaseAgent
{
    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $config = $agent->config ?? [];

        // Try to find a review URL: first from agent config, then from input text
        $reviewUrl = $config['review_urls'][0] ?? null;

        if (! $reviewUrl) {
            // Try to extract a URL from the input
            preg_match('/https?:\/\/\S+/i', $agentRun->input, $matches);
            $reviewUrl = $matches[0] ?? null;
        }

        $extraContext = '';

        if ($reviewUrl && $this->hasTool('scrape_page')) {
            $scraped = $this->useTool('scrape_page', ['url' => $reviewUrl]);
            if ($scraped && $scraped !== 'Scraping not available for this page.') {
                $extraContext = "\n\n--- SCRAPED REVIEW CONTENT FROM: {$reviewUrl} ---\n{$scraped}"
                    . "\n\nAnalyze the reviews above and provide:"
                    . "\n- Overall sentiment score (1-10)"
                    . "\n- Recurring themes (positive and negative)"
                    . "\n- Top 3-5 positives"
                    . "\n- Top 3-5 negatives"
                    . "\n- Summary conclusion";
            }
        } else {
            // No URL — analyze the raw text input for sentiment patterns
            $extraContext = "\n\nAnalyze the provided text for sentiment patterns and provide:"
                . "\n- Overall sentiment score (1-10)"
                . "\n- Recurring themes"
                . "\n- Top positives identified"
                . "\n- Top negatives identified"
                . "\n- Summary conclusion";
        }

        return $this->chat($agent, $agentRun->input, $extraContext);
    }
}
