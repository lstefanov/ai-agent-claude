<?php

namespace App\Agents\Tools;

use App\Services\PerplexitySearchService;

class PeopleSearchTool implements AgentTool
{
    public function __construct(private PerplexitySearchService $service) {}

    public function name(): string
    {
        return 'people_search';
    }

    public function description(): string
    {
        return 'Търсене на хора (Perplexity) — намира професионалисти по име, позиция, компания или локация, включително публични профили.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Име/позиция/компания/локация на търсения човек.'],
            ],
            'required' => ['query'],
        ];
    }

    /**
     * @param  array{query?: string}  $params
     */
    public function execute(array $params): string
    {
        $query = trim((string) ($params['query'] ?? ''));
        if ($query === '') {
            return '';
        }

        $results = $this->service->searchPeople($query);
        if ($results === []) {
            return '';
        }

        $lines = [];
        foreach ($results as $i => $result) {
            $num = $i + 1;
            $title = $result['title'] ?? 'No title';
            $url = $result['url'] ?? '';
            $summary = $result['snippet'] ?? $result['description'] ?? 'No description';

            $entry = "[{$num}] Person/Profile: {$title}\n";
            $entry .= "    URL: {$url}\n";
            $entry .= "    Summary: {$summary}";

            $lines[] = $entry;
        }

        return implode("\n\n", $lines);
    }
}
