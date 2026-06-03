<?php

namespace App\Agents\Tools;

use App\Services\CrawlService;

class SiteDiscoveryTool implements AgentTool
{
    public function __construct(private CrawlService $service) {}

    public function name(): string
    {
        return 'discover_urls';
    }

    /**
     * Discover internal page URLs for a site (sitemap → internal links).
     * Returns a newline-separated list of URLs, or an empty string on failure.
     *
     * @param array{url?: string, max?: int} $params
     */
    public function execute(array $params): string
    {
        $url = trim((string) ($params['url'] ?? ''));
        if ($url === '') {
            return '';
        }

        $max  = isset($params['max']) ? (int) $params['max'] : null;
        $urls = $this->service->discoverUrls($url, $max);

        return implode("\n", $urls);
    }
}
