<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BraveSearchService
{
    private const ENDPOINT     = 'https://api.search.brave.com/res/v1/web/search';
    private const MAX_ATTEMPTS = 3;
    private const RETRY_SLEEP  = 1;

    public function search(string $query, ?int $count = null): array
    {
        $apiKey = config('services.brave.api_key');
        $count  = $count ?? (int) config('services.brave.results_count', 10);

        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'Accept'               => 'application/json',
                    'Accept-Encoding'      => 'gzip',
                    'X-Subscription-Token' => $apiKey,
                ])->get(self::ENDPOINT, [
                    'q'     => $query,
                    'count' => $count,
                ]);

                if ($response->successful()) {
                    return $response->json('web.results') ?? [];
                }

                throw new \RuntimeException(
                    "Brave Search API error: HTTP {$response->status()}"
                );

            } catch (\RuntimeException $e) {
                $lastException = $e;
                Log::warning("[BraveSearch] Attempt {$attempt} failed: " . $e->getMessage());
                if ($attempt < self::MAX_ATTEMPTS) {
                    sleep(self::RETRY_SLEEP);
                }
            }
        }

        throw new \RuntimeException(
            'Brave Search failed after ' . self::MAX_ATTEMPTS . ' attempts: ' . $lastException->getMessage(),
        );
    }
}
