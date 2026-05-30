<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class CrawlService
{
    private string $baseUrl;
    private int    $timeout;

    public function __construct()
    {
        $this->baseUrl = config('services.crawl.url', 'http://localhost:8189');
        $this->timeout = (int) config('services.crawl.timeout', 15);
    }

    /**
     * Scrape a URL and return clean markdown. Returns null on any failure.
     */
    public function scrape(string $url): ?string
    {
        try {
            $response = Http::timeout($this->timeout)->post("{$this->baseUrl}/scrape", [
                'url'     => $url,
                'timeout' => $this->timeout,
            ]);

            if (! $response->successful()) {
                return null;
            }

            return $response->json('markdown') ?: null;
        } catch (\Exception) {
            return null;
        }
    }

    public function isAvailable(): bool
    {
        try {
            return Http::timeout(3)->get("{$this->baseUrl}/health")->successful();
        } catch (\Exception) {
            return false;
        }
    }
}
