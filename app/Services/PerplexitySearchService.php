<?php

namespace App\Services;

use App\Support\LlmUsage;
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
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::withToken((string) $apiKey)
                    ->acceptJson()
                    ->timeout(30)
                    ->post((string) config('services.perplexity.search_url', self::ENDPOINT), $payload);

                if ($response->successful()) {
                    LlmUsage::addFlatCost((float) config('services.perplexity.request_cost_usd', 0.005));

                    return $this->normalizeResults($response->json());
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

    private function emptyQuery(string|array $query): bool
    {
        if (is_string($query)) {
            return trim($query) === '';
        }

        return array_values(array_filter($query, fn ($item) => is_string($item) && trim($item) !== '')) === [];
    }
}
