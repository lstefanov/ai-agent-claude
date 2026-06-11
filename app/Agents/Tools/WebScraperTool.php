<?php

namespace App\Agents\Tools;

use App\Services\CrawlService;

class WebScraperTool implements AgentTool
{
    public function __construct(private CrawlService $service) {}

    public function name(): string
    {
        return 'scrape_page';
    }

    public function description(): string
    {
        return 'Извлича пълното текстово съдържание на ЕДНА страница по URL.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'URL на страницата.'],
            ],
            'required' => ['url'],
        ];
    }

    public function execute(array $params): string
    {
        $url = $params['url'] ?? '';
        $result = $this->service->scrape($url);

        return $result ?? 'Scraping not available for this page.';
    }
}
