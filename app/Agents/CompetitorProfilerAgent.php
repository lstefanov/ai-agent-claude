<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;
use Illuminate\Support\Facades\Http;

class CompetitorProfilerAgent extends BaseAgent
{
    private const PRICING_KEYWORDS = [
        'цен', 'абон', 'карт',
        'ceni', 'tseni', 'cena', 'price', 'pricing', 'membership', 'member',
        'plan', 'tarif', 'tariff', 'abon', 'kart',
    ];

    private const PRICING_PATHS = [
        '/цени', '/цена', '/абонамент', '/абонаменти', '/карти',
        '/ceni', '/tseni', '/tseni.html', '/cenorazpis', '/cennik',
        '/abonament', '/abonamenti', '/karti',
        '/prices', '/price', '/pricing', '/price-list', '/price-list.html',
        '/membership', '/memberships', '/plans', '/tarifi', '/tariffs',
    ];

    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $config = $agent->config ?? [];
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
            $maxPages = (int) ($config['max_pages_to_scrape'] ?? config('services.crawl.max_pages', 6));
            $scrapedContent = $this->scrapeTopPricingPages($allResults, $maxPages);
        }

        // Phase 3: Synthesize competitor profile
        $extraContext = '';
        if ($allResults) {
            $extraContext .= "\n\n--- WEB SEARCH RESULTS ---\n{$allResults}";
        }
        if ($scrapedContent) {
            $extraContext .= "\n\n--- SCRAPED PRICING PAGES ---\n{$scrapedContent}";
        }
        if ($extraContext) {
            $extraContext .= "\n\nBased on the above data, build a structured competitor profile including:"
                ."\n- Company name"
                ."\n- Main services/products offered"
                ."\n- Pricing tier (budget/mid-range/premium)"
                ."\n- Key strengths"
                ."\n- Key weaknesses"
                ."\n- Market positioning"
                ."\n- Target audience";
        }

        return $this->chat($agent, $agentRun->input, $extraContext);
    }

    private function scrapeTopPricingPages(string $searchResults, int $maxPages): string
    {
        $domainMap = $this->extractDomainUrls($searchResults);
        $scraped = '';
        $count = 0;

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

    private function extractDomainUrls(string $searchResults): array
    {
        preg_match_all('/URL:\s*(https?:\/\/\S+)/i', $searchResults, $matches);
        $domainMap = [];

        foreach ($matches[1] as $url) {
            $host = parse_url($url, PHP_URL_HOST) ?? '';
            $domain = strtolower(preg_replace('/^www\./i', '', $host));
            if (! $domain) {
                continue;
            }

            if (! isset($domainMap[$domain]) || $this->isPricingUrl($url)) {
                $domainMap[$domain] = $url;
            }
        }

        return $domainMap;
    }

    private function findPricingUrl(string $domain, string $knownUrl): ?string
    {
        if ($this->isPricingUrl($knownUrl)) {
            return $knownUrl;
        }

        $baseUrl = 'https://'.$domain;
        foreach (self::PRICING_PATHS as $path) {
            try {
                if (Http::timeout(3)->head($baseUrl.$path)->successful()) {
                    return $baseUrl.$path;
                }
            } catch (\Exception) {
                // Try next candidate path.
            }
        }

        return null;
    }

    private function isPricingUrl(string $url): bool
    {
        $path = mb_strtolower(parse_url($url, PHP_URL_PATH) ?? '');
        foreach (self::PRICING_KEYWORDS as $keyword) {
            if (str_contains($path, mb_strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }
}
