<?php

namespace App\Agents\Tools;

use App\Services\GooglePlacesService;

/**
 * Agent tool: returns a business's Google rating + sample reviews via the
 * official Google Places API. Returns '' when no key is set or no place matches,
 * so the agent can fall back to other sources.
 */
class GoogleReviewsTool implements AgentTool
{
    public function __construct(private GooglePlacesService $places) {}

    public function name(): string
    {
        return 'google_reviews';
    }

    /**
     * @param  array{query?: string, region?: string}  $params
     */
    public function execute(array $params): string
    {
        $query = trim((string) ($params['query'] ?? ''));
        if ($query === '') {
            return '';
        }

        $data = $this->places->reviewsFor($query, $params['region'] ?? null);
        if (! $data) {
            return '';
        }

        $lines = [];
        $lines[] = 'Бизнес (Google): '.($data['name'] !== '' ? $data['name'] : $query);
        if ($data['address'] !== '') {
            $lines[] = 'Адрес (Google): '.$data['address'];
        }
        if ($data['rating'] !== null) {
            $lines[] = 'Google оценка: '.$data['rating'].' / 5 ('.($data['total'] ?? 0).' ревюта)';
        }
        foreach ($data['reviews'] as $i => $r) {
            $n      = $i + 1;
            $rating = $r['rating'] !== null ? $r['rating'].'★' : '';
            $lines[] = "Ревю {$n} — {$r['author']} ({$rating} {$r['time']}): {$r['text']}";
        }

        if (count($lines) <= 1) {
            return ''; // only the name, no rating/reviews — treat as no data
        }

        return implode("\n", $lines);
    }
}
