<?php

namespace App\Agents\Tools;

use App\Services\PerplexitySearchService;

class PerplexitySearchTool implements AgentTool
{
    public function __construct(private PerplexitySearchService $service) {}

    public function name(): string
    {
        return 'pro_search';
    }

    public function description(): string
    {
        return 'Премиум търсене в интернет (Perplexity) — по-качествени резултати, domain филтри; за конкурентен анализ и дълбоко проучване.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Заявка за търсене.'],
                'domains' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Ограничи търсенето до тези домейни (по избор).'],
                'max_results' => ['type' => 'integer', 'description' => 'Максимален брой резултати (по избор).'],
            ],
            'required' => ['query'],
        ];
    }

    /**
     * @param  array{query?: string|array<int, string>, domains?: string|array<int, string>, max_results?: int}  $params
     */
    public function execute(array $params): string
    {
        $query = $params['query'] ?? '';
        $domains = $this->normalizeDomains($params['domains'] ?? null);

        $results = $this->service->search($query, array_filter([
            'search_domain_filter' => $domains,
            'max_results' => $params['max_results'] ?? null,
        ], fn ($value) => $value !== null && $value !== []));

        return $this->formatResults($results);
    }

    /**
     * @return array<int, string>
     */
    private function normalizeDomains(mixed $domains): array
    {
        if (is_string($domains)) {
            $domains = array_map('trim', explode(',', $domains));
        }

        if (! is_array($domains)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $domains)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function formatResults(array $results): string
    {
        if ($results === []) {
            return '';
        }

        $lines = [];
        foreach ($results as $i => $result) {
            $num = $i + 1;
            $title = $result['title'] ?? 'No title';
            $url = $result['url'] ?? '';
            $summary = $result['snippet'] ?? $result['description'] ?? 'No description';
            $date = $result['date'] ?? $result['last_updated'] ?? null;

            $entry = "[{$num}] Title: {$title}\n";
            $entry .= "    URL: {$url}\n";
            if ($date) {
                $entry .= "    Date: {$date}\n";
            }
            $entry .= "    Summary: {$summary}";

            $lines[] = $entry;
        }

        return implode("\n\n", $lines);
    }
}
