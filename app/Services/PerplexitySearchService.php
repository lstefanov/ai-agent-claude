<?php

namespace App\Services;

use App\Support\LlmRequestRecorder;
use App\Support\LlmUsage;
use App\Support\Utf8;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PerplexitySearchService
{
    private const ENDPOINT = 'https://api.perplexity.ai/search';

    private const MAX_ATTEMPTS = 3;

    private const RETRY_SLEEP = 1;

    /**
     * @param  string|array<int, string>  $query
     * @param  array<string, mixed>  $opts
     * @return array<int, array<string, mixed>>
     */
    public function search(string|array $query, array $opts = []): array
    {
        return $this->request($query, $opts);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchPeople(string $query): array
    {
        return $this->request($query, ['search_type' => 'people']);
    }

    /**
     * @param  string|array<int, string>  $query
     * @param  array<string, mixed>  $opts
     * @return array<int, array<string, mixed>>
     */
    private function request(string|array $query, array $opts): array
    {
        $apiKey = config('services.perplexity.api_key');
        if (empty($apiKey) || $this->emptyQuery($query)) {
            return [];
        }

        if (is_array($query)) {
            $query = array_values(array_filter(array_map('trim', $query)));
        }

        $payload = [
            'query' => $query,
            'max_results' => (int) ($opts['max_results'] ?? config('services.perplexity.max_results', 10)),
        ];

        foreach (['search_type', 'search_domain_filter', 'search_language_filter', 'country'] as $key) {
            $value = $opts[$key] ?? null;
            if ($value !== null && $value !== '' && $value !== []) {
                $payload[$key] = $value;
            }
        }

        if (! isset($payload['country']) && filled(config('services.perplexity.country'))) {
            $payload['country'] = config('services.perplexity.country');
        }

        $lastException = null;
        $startMs = (int) (microtime(true) * 1000);
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::withToken((string) $apiKey)
                    ->acceptJson()
                    ->timeout(30)
                    ->post((string) config('services.perplexity.search_url', self::ENDPOINT), $payload);

                if ($response->successful()) {
                    // Scrub invalid UTF-8 from result snippets/titles at the source.
                    $results = Utf8::clean($this->normalizeResults($response->json()));
                    $flatCost = (float) config('services.perplexity.request_cost_usd', 0.005);
                    LlmUsage::addFlatCost($flatCost);

                    // Per-request одит за admin "Разходи" (секция Външни API).
                    LlmRequestRecorder::record(
                        'perplexity', 'search',
                        ($payload['search_type'] ?? null) === 'people' ? 'people_search' : 'web_search',
                        null,
                        is_array($query) ? implode(' | ', $query) : (string) $query,
                        $this->resultsPreview($results),
                        array_intersect_key($payload, array_flip(['max_results', 'country', 'search_type', 'search_domain_filter'])),
                        0, 0,
                        (int) (microtime(true) * 1000) - $startMs,
                        costOverride: $flatCost,
                    );

                    return $results;
                }

                throw new \RuntimeException("Perplexity Search API error: HTTP {$response->status()}");
            } catch (\Throwable $e) {
                $lastException = $e;
                Log::warning("[PerplexitySearch] Attempt {$attempt} failed: ".$e->getMessage());
                if ($attempt < self::MAX_ATTEMPTS) {
                    sleep(self::RETRY_SLEEP);
                }
            }
        }

        throw new \RuntimeException(
            'Perplexity Search failed after '.self::MAX_ATTEMPTS.' attempts: '.($lastException?->getMessage() ?? 'unknown error'),
        );
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array<int, array<string, mixed>>
     */
    private function normalizeResults(?array $json): array
    {
        $results = $json['results'] ?? [];
        if (! is_array($results)) {
            return [];
        }

        return array_values(array_filter($results, 'is_array'));
    }

    /** Кратък преглед на резултатите за одит реда (response_text). */
    private function resultsPreview(array $results): string
    {
        $lines = [];
        foreach (array_slice($results, 0, 10) as $i => $result) {
            $n = $i + 1;
            $title = $result['title'] ?? 'No title';
            $url = $result['url'] ?? '';
            $snippet = $result['snippet'] ?? $result['description'] ?? '';
            $lines[] = trim("[{$n}] {$title}\n{$url}\n{$snippet}");
        }

        return implode("\n\n", $lines);
    }

    private function emptyQuery(string|array $query): bool
    {
        if (is_string($query)) {
            return trim($query) === '';
        }

        return array_values(array_filter($query, fn ($item) => is_string($item) && trim($item) !== '')) === [];
    }
}
