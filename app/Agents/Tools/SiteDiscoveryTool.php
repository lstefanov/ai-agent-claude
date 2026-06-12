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

    public function description(): string
    {
        return 'Открива списъка от вътрешни URL адреси на даден сайт (без да ги скрейпва).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'URL на сайта.'],
                'max' => ['type' => 'integer', 'description' => 'Максимален брой URL адреси (по избор).'],
            ],
            'required' => ['url'],
        ];
    }

    /**
     * Discover internal page URLs for a site (sitemap → internal links).
     * Returns a newline-separated list of URLs, or an empty string on failure.
     *
     * @param  array{url?: string, max?: int}  $params
     */
    public function execute(array $params): string
    {
        $url = trim((string) ($params['url'] ?? ''));
        if ($url === '') {
            return '';
        }

        // Списъкът е евтин текст (sitemap-driven), но клампваме срещу
        // дегенеративни заявки от LLM-а (max=10000 и т.н.).
        $max = isset($params['max']) ? max(1, min((int) $params['max'], 100)) : null;
        $urls = $this->service->discoverUrls($url, $max);

        return implode("\n", $urls);
    }
}
