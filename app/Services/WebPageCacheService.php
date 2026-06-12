<?php

namespace App\Services;

use App\Models\WebPageCache;

/**
 * TTL + content-hash cache over the scrape service. Two-tier semantics:
 *  - FRESH (last_checked_at within TTL) → serve from cache, no HTTP at all;
 *  - STALE → the caller fetches live; store() then compares content hashes —
 *    unchanged pages only touch last_checked_at, changed ones update.
 * A failed fetch falls back to any cached copy (stale beats nothing).
 */
class WebPageCacheService
{
    public function enabled(): bool
    {
        return (bool) config('services.crawl.cache_enabled', true);
    }

    /**
     * Canonical URL for cache keying: lowercase scheme+host, no fragment, no
     * tracking params (utm_*, gclid, fbclid, msclkid), remaining query sorted
     * by key, trailing slash stripped (path case is PRESERVED — many servers
     * are case-sensitive). Returns null for unusable URLs.
     */
    public function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = rtrim($parts['path'] ?? '', '/');

        $query = '';
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $params);
            $params = array_filter(
                $params,
                fn ($key) => ! preg_match('/^(utm_|gclid$|fbclid$|msclkid$)/i', (string) $key),
                ARRAY_FILTER_USE_KEY,
            );
            if ($params !== []) {
                ksort($params);
                $query = '?'.http_build_query($params);
            }
        }

        return $scheme.'://'.$host.$port.$path.$query;
    }

    public function urlHash(string $url): ?string
    {
        $normalized = $this->normalizeUrl($url);

        return $normalized === null ? null : hash('sha256', $normalized);
    }

    /** Markdown from a FRESH cache entry (TTL not expired), else null. */
    public function freshHit(string $url): ?string
    {
        $hash = $this->urlHash($url);
        if ($hash === null) {
            return null;
        }

        $ttlHours = max(1, (int) config('services.crawl.cache_ttl_hours', 24));

        $entry = WebPageCache::where('url_hash', $hash)
            ->where('last_checked_at', '>=', now()->subHours($ttlHours))
            ->first();

        if (! $entry) {
            return null;
        }

        $entry->increment('hit_count');

        return $entry->markdown;
    }

    /** Any cached copy regardless of age — the stale fallback for failed fetches. */
    public function any(string $url): ?string
    {
        $hash = $this->urlHash($url);

        return $hash === null ? null : WebPageCache::where('url_hash', $hash)->value('markdown');
    }

    /** Upsert after a successful live fetch. Unchanged content → touch only. */
    public function store(string $url, string $markdown): void
    {
        $normalized = $this->normalizeUrl($url);
        if ($normalized === null || trim($markdown) === '') {
            return;
        }

        $urlHash = hash('sha256', $normalized);
        $contentHash = hash('sha256', $markdown);

        $entry = WebPageCache::where('url_hash', $urlHash)->first();

        if ($entry && $entry->content_hash === $contentHash) {
            $entry->update(['last_checked_at' => now()]);

            return;
        }

        WebPageCache::updateOrCreate(
            ['url_hash' => $urlHash],
            [
                'url' => mb_substr($normalized, 0, 2048),
                'content_hash' => $contentHash,
                'markdown' => $markdown,
                'fetched_at' => now(),
                'last_checked_at' => now(),
            ],
        );
    }
}
