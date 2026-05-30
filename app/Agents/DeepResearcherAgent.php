<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;
use Illuminate\Support\Facades\Http;

class DeepResearcherAgent extends BaseAgent
{
    private const PRICING_KEYWORDS = [
        'цен', 'абон', 'карт',
        'tseni', 'tsena', 'ceni', 'cena', 'abon',
        'price', 'pricing', 'member', 'plan', 'tarif', 'subscribe', 'kart',
    ];

    private const PRICING_PATHS = [
        '/цени', '/цена', '/абонамент', '/абонаменти', '/карти',
        '/ceni', '/tseni', '/tseni.html', '/abonamant', '/abonament',
        '/prices', '/pricing', '/membership', '/memberships',
        '/plans', '/plan', '/tarifi', '/tariffs', '/subscribe', '/karti',
    ];

    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $config = $agent->config ?? [];

        // Phase 1: BraveSearch
        $queryTemplates = $config['search_queries'] ?? [];
        if (empty($queryTemplates)) {
            $queryTemplates = $this->generateSearchQueries($agent, $agentRun->input, $context);
        } else {
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
                $num        = $i + 1;
                $allResults .= "\n\n=== SEARCH {$num}: \"{$query}\" ===\n{$results}";
            }
        }

        // Phase 2: Scrape pricing pages (only if tool registered and config allows)
        $scrapedContent = '';
        if ($allResults && ($config['scrape_pricing_pages'] ?? true) && $this->hasTool('scrape_page')) {
            $maxPages       = (int) ($config['max_pages_to_scrape'] ?? config('services.crawl.max_pages', 3));
            $scrapedContent = $this->scrapeTopPricingPages($allResults, $maxPages);
        }

        // Phase 3: Synthesize
        $extraContext = '';
        if ($allResults) {
            $extraContext .= "\n\n--- WEB SEARCH RESULTS (preserve EXACT competitor names, EXACT prices, EXACT service names — cite source URL next to every price) ---\n{$allResults}";
        }
        if ($scrapedContent) {
            $extraContext .= "\n\n--- FULL PRICING PAGE CONTENT (complete menus scraped directly from competitor websites) ---\n{$scrapedContent}";
        }

        return $this->chat($agent, $agentRun->input, $extraContext);
    }

    private function scrapeTopPricingPages(string $searchResults, int $maxPages): string
    {
        $domainMap = $this->extractDomainUrls($searchResults);
        $scraped   = '';
        $count     = 0;

        foreach ($domainMap as $domain => $knownUrl) {
            if ($count >= $maxPages) {
                break;
            }
            $pricingUrl = $this->findPricingUrl($domain, $knownUrl);
            if (! $pricingUrl) {
                continue;
            }
            $markdown = $this->useTool('scrape_page', ['url' => $pricingUrl]);
            if ($markdown && $markdown !== 'Scraping not available for this page.') {
                $scraped .= "\n\n=== SCRAPED PRICING PAGE: {$pricingUrl} ===\n{$markdown}";
                $count++;
            }
        }

        return $scraped;
    }

    /**
     * Extract unique domains → first URL from BraveSearchTool output.
     * BraveSearchTool format: "[N] Title: ...\n    URL: https://...\n    Summary: ..."
     */
    private function extractDomainUrls(string $searchResults): array
    {
        preg_match_all('/URL:\s*(https?:\/\/\S+)/i', $searchResults, $matches);
        $domainMap = [];
        foreach ($matches[1] as $url) {
            $host   = parse_url($url, PHP_URL_HOST) ?? '';
            $domain = strtolower(preg_replace('/^www\./i', '', $host));
            if (! $domain) {
                continue;
            }
            // Prefer URLs that already point to a pricing page
            if (! isset($domainMap[$domain])) {
                $domainMap[$domain] = $url;
            } else {
                $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
                foreach (self::PRICING_KEYWORDS as $keyword) {
                    if (str_contains($path, mb_strtolower($keyword))) {
                        $domainMap[$domain] = $url;
                        break;
                    }
                }
            }
        }
        return $domainMap;
    }

    /**
     * Returns the best pricing page URL for a domain.
     * First checks if the known URL already points to a pricing page (keyword match).
     * Falls back to HEAD-checking a list of known pricing paths.
     */
    private function findPricingUrl(string $domain, string $knownUrl): ?string
    {
        $path = strtolower(parse_url($knownUrl, PHP_URL_PATH) ?? '');
        foreach (self::PRICING_KEYWORDS as $keyword) {
            if (str_contains($path, mb_strtolower($keyword))) {
                return $knownUrl;
            }
        }

        $baseUrl = 'https://' . $domain;
        foreach (self::PRICING_PATHS as $pricingPath) {
            try {
                $response = Http::timeout(3)->head($baseUrl . $pricingPath);
                if ($response->successful()) {
                    return $baseUrl . $pricingPath;
                }
            } catch (\Exception) {
                // Try next path
            }
        }

        return null;
    }

    private function generateSearchQueries(Agent $agent, string $input, array $context): array
    {
        $count = (int) ($agent->config['search_queries_count'] ?? 4);
        $topic = ! empty($context['flow_topic']) ? $context['flow_topic'] : mb_substr($input, 0, 300);

        $systemPrompt = 'You are a search query specialist. Output ONLY a JSON array of strings. No explanation.';
        $userMessage  = "Generate {$count} specific search queries to thoroughly research this topic from multiple angles.\n\nTopic: {$topic}\n\nRules:\n- Each query must target a SPECIFIC aspect, source type, or subtopic\n- Include the market/location if evident from the topic\n- Queries must be in the same language as the topic\n- Output ONLY valid JSON array, example: [\"query 1\",\"query 2\"]";

        $raw = $this->ollama->chat(
            model: $agent->model,
            systemPrompt: $systemPrompt,
            userMessage: $userMessage,
            options: ['temperature' => 0.3]
        );

        if (preg_match('/\[.*\]/s', $raw, $m)) {
            $queries = json_decode($m[0], true);
            if (is_array($queries) && count($queries) > 0) {
                return array_slice($queries, 0, $count);
            }
        }

        return [$topic];
    }
}
