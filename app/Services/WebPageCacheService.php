<?php

namespace App\Services;

use App\Models\WebPageCache;

/**
 * TTL + content-hash cache over the scrape service. ГЛОБАЛЕН (без company
 * филтър): една обходена страница служи на всички фирми и flows. Two-tier:
 *  - FRESH (last_checked_at within TTL) → serve from cache, no HTTP at all;
 *  - STALE → the caller fetches live; store() then compares content hashes —
 *    unchanged pages only touch last_checked_at, changed ones update.
 * A failed fetch falls back to any cached copy (stale beats nothing).
 *
 * v2 пази и title / meta_description / internal links от рендерирания DOM —
 * BFS кралът чете линковете оттук без повторен fetch.
 */
class WebPageCacheService
{
    public function enabled(): bool
    {
        return (bool) config('services.crawl.cache_enabled', true);
    }

    /**
     * Canonical URL — ЕДИНСТВЕНАТА нормализация в системата (BFS уникалност,
     * кеш ключове, knowledge_pages.url_hash): lowercase scheme+host, no
     * fragment, no tracking params (utm_*, gclid, fbclid, msclkid), remaining
     * query sorted by key (── ?page=2 СЕ ПАЗИ: пагинацията е отделна
     * страница), trailing slash stripped (path case is PRESERVED — many
     * servers are case-sensitive). Returns null for unusable URLs.
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

    /** FRESH cache entry (TTL not expired), else null. Counts the hit. */
    public function fresh(string $url): ?WebPageCache
    {
        $hash = $this->urlHash($url);
        if ($hash === null) {
            return null;
        }

        $ttlHours = max(1, (int) config('services.crawl.cache_ttl_hours', 24));

        $entry = WebPageCache::where('url_hash', $hash)
            ->where('last_checked_at', '>=', now()->subHours($ttlHours))
            ->first();

        $entry?->increment('hit_count');

        return $entry;
    }

    /** Any cached entry regardless of age — the stale fallback for failed fetches. */
    public function any(string $url): ?WebPageCache
    {
        $hash = $this->urlHash($url);

        return $hash === null ? null : WebPageCache::where('url_hash', $hash)->first();
    }

    /**
     * Upsert after a successful live fetch. Unchanged content → touch only
     * (и попълва title/meta/links, ако ги нямаме от по-стар fetch).
     *
     * @param  array<int, string>|null  $links
     */
    public function store(
        string $url,
        string $markdown,
        ?string $title = null,
        ?string $metaDescription = null,
        ?array $links = null,
    ): ?WebPageCache {
        $normalized = $this->normalizeUrl($url);
        if ($normalized === null || trim($markdown) === '') {
            return null;
        }

        $urlHash = hash('sha256', $normalized);
        $contentHash = hash('sha256', trim($markdown));

        $extra = array_filter([
            'title' => $title !== null ? mb_substr($title, 0, 500) : null,
            'meta_description' => $metaDescription,
            'links' => $links,
        ], fn ($v) => $v !== null && $v !== '');

        $entry = WebPageCache::where('url_hash', $urlHash)->first();

        if ($entry && $entry->content_hash === $contentHash) {
            $entry->update(array_merge(['last_checked_at' => now()], $extra));

            return $entry->refresh();
        }

        return WebPageCache::updateOrCreate(
            ['url_hash' => $urlHash],
            array_merge([
                'url' => mb_substr($normalized, 0, 2048),
                'content_hash' => $contentHash,
                'markdown' => $markdown,
                'fetched_at' => now(),
                'last_checked_at' => now(),
            ], $extra),
        );
    }
}
