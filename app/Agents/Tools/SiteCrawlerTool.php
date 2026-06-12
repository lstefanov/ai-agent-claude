<?php

namespace App\Agents\Tools;

use App\Services\CrawlService;
use App\Support\PageContent;

class SiteCrawlerTool implements AgentTool
{
    /**
     * Max chars of USEFUL content per page, measured AFTER boilerplate stripping.
     * WordPress/Elementor pages have ~10 KB of cookie popup + nav before the real
     * content starts. After stripping, 4 000 chars captures: product title + price
     * range + first description paragraph — everything an LLM needs for analysis.
     */
    private const MAX_CHARS_PER_PAGE = 4000;

    public function __construct(private CrawlService $service) {}

    public function name(): string
    {
        return 'crawl_site';
    }

    public function description(): string
    {
        return 'Обхожда цял сайт страница по страница и връща съдържанието им (изчистено от boilerplate).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'Начален URL на сайта.'],
                'max' => ['type' => 'integer', 'description' => 'Максимален брой страници (по избор).'],
            ],
            'required' => ['url'],
        ];
    }

    /**
     * @param  array{url?: string, max?: int}  $params
     */
    public function execute(array $params): string
    {
        $url = trim((string) ($params['url'] ?? ''));
        if ($url === '') {
            return '';
        }

        // LLM-ът ПРЕДЛАГА брой страници, кодът ГАРАНТИРА тавана: без клампа
        // един tool call може да поиска стотици страници (~10s скрейп всяка)
        // и да надхвърли job timeout-а на ExecuteNodeJob (1200s).
        $ceiling = (int) config('services.crawl.max_pages', 20);
        $max = isset($params['max']) ? max(1, min((int) $params['max'], $ceiling)) : null;
        $pages = $this->service->crawlSite($url, $max);

        if (empty($pages)) {
            return '';
        }

        $sections = [];
        foreach ($pages as $pageUrl => $markdown) {
            $content = PageContent::stripBoilerplate($markdown);
            if ($content === '') {
                continue;
            }
            if (mb_strlen($content) > self::MAX_CHARS_PER_PAGE) {
                $content = mb_substr($content, 0, self::MAX_CHARS_PER_PAGE).' [...]';
            }
            $sections[] = "=== СТРАНИЦА: {$pageUrl} ===\n".$content;
        }

        return implode("\n\n", $sections);
    }
}
