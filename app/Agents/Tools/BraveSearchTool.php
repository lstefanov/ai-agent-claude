<?php

namespace App\Agents\Tools;

use App\Services\BraveSearchService;

class BraveSearchTool implements AgentTool
{
    public function __construct(private BraveSearchService $service) {}

    public function name(): string
    {
        return 'web_search';
    }

    public function execute(array $params): string
    {
        $query   = $params['query'] ?? '';
        $results = $this->service->search($query);

        if (empty($results)) {
            return 'No web search results found.';
        }

        $lines = [];
        foreach ($results as $i => $result) {
            $num     = $i + 1;
            $title   = $result['title'] ?? 'No title';
            $url     = $result['url'] ?? '';
            $summary = $result['description'] ?? 'No description';
            $age     = $result['age'] ?? null;

            $entry = "[{$num}] Title: {$title}\n";
            $entry .= "    URL: {$url}\n";
            if ($age) {
                $entry .= "    Date: {$age}\n";
            }
            $entry .= "    Summary: {$summary}";

            $lines[] = $entry;
        }

        return implode("\n\n", $lines);
    }
}
