<?php

namespace App\Services;

use App\Support\NodeDeadline;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Локален скрейп/крал слой над Crawl4AI (scripts/crawl_service.py, Playwright
 * + два rendering паса за JS/SPA сайтове) — никакви платени скрейп провайдъри.
 *
 * Централният примитив е fetchPage(): връща пълната структура на страница
 * (markdown + title + meta + вътрешни линкове от РЕНДЕРИРАНИЯ DOM +
 * content_hash) през глобалния WebPageCache (TTL + content-hash dedup).
 *
 * crawlSiteBfs() е истинско BFS обхождане: всяка посетена страница храни
 * frontier-а със своите линкове (вкл. пагинация /page/2/, ?page=2 —
 * нормализацията пази query string-а), уникалност по нормализиран URL,
 * единственият таван е $max страници.
 */
class CrawlService
{
    private string $baseUrl;

    private int $timeout;      // per-page render timeout sent to the scraper

    private int $httpTimeout;  // PHP client timeout — must exceed the scraper's two-pass budget

    // Default value keeps the `new CrawlService` call sites (AgentFactory)
    // working — WebPageCacheService is dependency-free.
    public function __construct(private WebPageCacheService $cache = new WebPageCacheService)
    {
        $this->baseUrl = config('services.crawl.url', 'http://localhost:8189');
        $this->timeout = (int) config('services.crawl.timeout', 35);
        // The scraper does up to two render passes (domcontentloaded → load+magic),
        // so a single /scrape can take ~timeout*2+10s. Give the HTTP client headroom.
        $this->httpTimeout = $this->timeout * 2 + 20;
    }

    /**
     * Пълната структура на една страница, през глобалния кеш:
     *  - FRESH кеш ред → нула HTTP;
     *  - иначе Crawl4AI fetch → store() (същият content_hash = само touch);
     *  - неуспешен fetch → какъвто и да е кеш ред (stale beats nothing).
     *
     * @return array{url: string, title: ?string, meta_description: ?string, markdown: string, content_hash: string, links: array<int, string>, from_cache: bool}|null
     */
    public function fetchPage(string $url, bool $bypassCache = false): ?array
    {
        $normalized = $this->cache->normalizeUrl($url);
        if ($normalized === null) {
            return null;
        }

        $useCache = $this->cache->enabled();

        if ($useCache && ! $bypassCache && ($entry = $this->cache->fresh($normalized)) !== null) {
            return $this->pageFromCacheEntry($entry);
        }

        try {
            $response = Http::timeout($this->httpTimeout)->post("{$this->baseUrl}/scrape", [
                'url' => $normalized,
                'timeout' => $this->timeout,
            ]);

            if ($response->successful()) {
                $markdown = $response->json('markdown');
                if (is_string($markdown) && trim($markdown) !== '') {
                    $title = (string) ($response->json('title') ?? '');
                    $meta = (string) ($response->json('meta_description') ?? '');
                    $links = array_values(array_filter((array) ($response->json('internal_links') ?? []), 'is_string'));

                    if ($links === []) {
                        $links = $this->linksFromMarkdown($markdown, $normalized);
                    }

                    if ($useCache) {
                        $this->cache->store($normalized, $markdown, $title ?: null, $meta ?: null, $links);
                    }

                    return [
                        'url' => $normalized,
                        'title' => $title ?: null,
                        'meta_description' => $meta ?: null,
                        'markdown' => $markdown,
                        'content_hash' => hash('sha256', trim($markdown)),
                        'links' => $links,
                        'from_cache' => false,
                    ];
                }
            }
        } catch (\Exception) {
            // пада към stale кеша
        }

        if ($useCache && ($entry = $this->cache->any($normalized)) !== null) {
            return $this->pageFromCacheEntry($entry);
        }

        return null;
    }

    /**
     * Scrape a URL and return clean markdown. Returns null on any failure.
     */
    public function scrape(string $url, bool $bypassCache = false): ?string
    {
        return $this->fetchPage($url, $bypassCache)['markdown'] ?? null;
    }

    /**
     * Scrape many URLs CONCURRENTLY. Returns url => markdown for pages that
     * returned non-empty content. Requests run in waves of $concurrency.
     *
     * Cache reads cost nothing and are never deadline-gated; only the HTTP
     * waves check NodeDeadline, so a budget-hit run still returns everything
     * already cached + the waves completed so far.
     *
     * @param  array<int, string>  $urls
     * @return array<string, string> keyed by the CALLER's original URL strings
     */
    public function scrapeMany(array $urls, int $concurrency = 4, bool $bypassCache = false): array
    {
        $concurrency = max(1, $concurrency);
        $useCache = ! $bypassCache && $this->cache->enabled();
        $out = [];
        $toFetch = [];

        foreach (array_values($urls) as $url) {
            if ($useCache && ($entry = $this->cache->fresh($url)) !== null) {
                $out[$url] = $entry->markdown;
            } else {
                $toFetch[] = $url;
            }
        }

        foreach (array_chunk($toFetch, $concurrency) as $wave) {
            if (NodeDeadline::passed(45)) {
                break;
            }

            $responses = Http::pool(function ($pool) use ($wave) {
                $calls = [];
                foreach ($wave as $i => $url) {
                    $calls[] = $pool->as((string) $i)
                        ->timeout($this->httpTimeout)
                        ->post("{$this->baseUrl}/scrape", [
                            'url' => $url,
                            'timeout' => $this->timeout,
                        ]);
                }

                return $calls;
            });

            foreach ($wave as $i => $url) {
                $resp = $responses[(string) $i] ?? null;
                try {
                    if ($resp instanceof Response && $resp->successful()) {
                        $md = $resp->json('markdown');
                        if (is_string($md) && trim($md) !== '') {
                            $out[$url] = $md;
                            if ($this->cache->enabled()) {
                                $this->cache->store(
                                    $url,
                                    $md,
                                    ((string) $resp->json('title')) ?: null,
                                    ((string) $resp->json('meta_description')) ?: null,
                                    array_values(array_filter((array) ($resp->json('internal_links') ?? []), 'is_string')),
                                );
                            }

                            continue;
                        }
                    }
                } catch (\Throwable) {
                    // пада към stale кеша по-долу
                }

                if ($this->cache->enabled() && ($stale = $this->cache->any($url)) !== null) {
                    $out[$url] = $stale->markdown;
                }
            }
        }

        return $out;
    }

    public function isAvailable(): bool
    {
        try {
            return Http::timeout(3)->get("{$this->baseUrl}/health")->successful();
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Истинско BFS обхождане на сайт: start URL + sitemap seeds → всяка
     * посетена страница добавя линковете си (от рендерирания DOM) във
     * frontier-а → пак и пак, докато frontier-ът се изчерпи или стигнем $max
     * УНИКАЛНИ страници. Пагинацията се следва като нормални линкове.
     *
     * @param  float|null  $deadlineTs  microtime(true) бюджет на викащия job —
     *                                  при изтичане връща събраното дотук.
     * @param  callable|null  $onProgress  fn(int $parsed, int $discovered) за UI прогрес.
     * @return array<int, array{url: string, title: ?string, meta_description: ?string, markdown: string, content_hash: string, links: array<int, string>, from_cache: bool}>
     */
    public function crawlSiteBfs(
        string $startUrl,
        ?int $max = null,
        bool $bypassCache = false,
        ?float $deadlineTs = null,
        ?callable $onProgress = null,
    ): array {
        $max = $max ?? (int) config('services.knowledge.site_max_pages', 200);
        $start = $this->cache->normalizeUrl($startUrl);
        if ($start === null) {
            return [];
        }
        $host = parse_url($start, PHP_URL_HOST);

        /** @var \SplQueue<string> $frontier */
        $frontier = new \SplQueue;
        $seen = [$start => true];
        $frontier->enqueue($start);

        // Sitemap-ите са помощни seeds (страници, до които няма навигация);
        // BFS-ът остава основният откривател.
        foreach ($this->sitemapUrls($this->rootUrl($start) ?? $start) as $sitemap) {
            foreach ($this->locsFromSitemap($sitemap) as $loc) {
                $norm = $this->cache->normalizeUrl($loc);
                if ($norm !== null
                    && parse_url($norm, PHP_URL_HOST) === $host
                    && ! isset($seen[$norm])
                    && ! $this->isAsset($norm)
                    && ! $this->isSkippedPage($norm)) {
                    $seen[$norm] = true;
                    $frontier->enqueue($norm);
                }
                if (count($seen) >= $max * 5) {
                    break 2; // bound the frontier — линковете от BFS-а допълват
                }
            }
        }

        $pages = [];

        while (! $frontier->isEmpty() && count($pages) < $max) {
            // Времеви бюджети: на node job-а (агентски контекст) и на викащия
            // ingest job — частичен crawl е по-добър от умрял job.
            if (NodeDeadline::passed(45) || ($deadlineTs !== null && microtime(true) >= $deadlineTs)) {
                break;
            }

            $url = $frontier->dequeue();
            $page = $this->fetchPage($url, $bypassCache);

            if ($page === null || trim($page['markdown']) === '') {
                continue;
            }

            $pages[] = $page;

            foreach ($page['links'] as $link) {
                $abs = $this->absoluteUrl($link, $page['url']);
                $norm = $abs === null ? null : $this->cache->normalizeUrl($abs);

                if ($norm === null
                    || isset($seen[$norm])
                    || parse_url($norm, PHP_URL_HOST) !== $host
                    || $this->isAsset($norm)
                    || $this->isSkippedPage($norm)
                    || count($seen) >= $max * 10) {
                    continue;
                }

                $seen[$norm] = true;
                $frontier->enqueue($norm);
            }

            if ($onProgress !== null) {
                $onProgress(count($pages), count($seen));
            }
        }

        return $pages;
    }

    /**
     * Crawl an entire site to markdown (агентският crawl_site tool).
     *
     * @return array<string, string> map of url => markdown (only non-empty pages)
     */
    public function crawlSite(string $url, ?int $max = null): array
    {
        $max = $max ?? (int) config('services.crawl.max_pages', 30);

        $pages = [];
        foreach ($this->crawlSiteBfs($url, $max) as $page) {
            $pages[$page['url']] = $page['markdown'];
        }

        return $pages;
    }

    /**
     * Discover internal page URLs WITHOUT full rendering: лек BFS върху суров
     * HTML (бърз, без JS) + sitemap seeds. За SPA сайтове без сървърен HTML
     * sitemap-ът е основният източник.
     *
     * @return array<int, string>
     */
    public function discoverUrls(string $url, ?int $max = null): array
    {
        $max = $max ?? (int) config('services.crawl.max_pages', 30);
        $start = $this->cache->normalizeUrl($url);
        if ($start === null) {
            return [];
        }
        $host = parse_url($start, PHP_URL_HOST);

        /** @var \SplQueue<string> $frontier */
        $frontier = new \SplQueue;
        $seen = [$start => true];
        $found = [];
        $frontier->enqueue($start);

        foreach ($this->sitemapUrls($this->rootUrl($start) ?? $start) as $sitemap) {
            foreach ($this->locsFromSitemap($sitemap) as $loc) {
                $norm = $this->cache->normalizeUrl($loc);
                if ($norm !== null
                    && parse_url($norm, PHP_URL_HOST) === $host
                    && ! isset($seen[$norm])
                    && ! $this->isAsset($norm)
                    && ! $this->isSkippedPage($norm)) {
                    $seen[$norm] = true;
                    $frontier->enqueue($norm);
                }
                if (count($seen) >= $max * 5) {
                    break 2;
                }
            }
        }

        // Линковете се вадят от суров HTML само докато списъкът не е пълен.
        $fetched = 0;
        while (! $frontier->isEmpty() && count($found) < $max) {
            $current = $frontier->dequeue();
            $found[] = $current;

            if (count($found) + $frontier->count() >= $max || $fetched >= $max) {
                continue; // frontier-ът вече покрива тавана — само източваме
            }

            $fetched++;
            foreach ($this->internalLinks($current, $host) as $link) {
                if (! isset($seen[$link]) && ! $this->isSkippedPage($link)) {
                    $seen[$link] = true;
                    $frontier->enqueue($link);
                }
            }
        }

        return array_slice($found, 0, $max);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Internals
    // ──────────────────────────────────────────────────────────────────────

    /** @return array{url: string, title: ?string, meta_description: ?string, markdown: string, content_hash: string, links: array<int, string>, from_cache: bool} */
    private function pageFromCacheEntry(\App\Models\WebPageCache $entry): array
    {
        $links = is_array($entry->links) ? $entry->links : [];
        if ($links === []) {
            $links = $this->linksFromMarkdown($entry->markdown, $entry->url);
        }

        return [
            'url' => $entry->url,
            'title' => $entry->title ?: null,
            'meta_description' => $entry->meta_description ?: null,
            'markdown' => $entry->markdown,
            'content_hash' => $entry->content_hash,
            'links' => $links,
            'from_cache' => true,
        ];
    }

    /**
     * Fallback линк-екстракция от markdown (за кеш редове отпреди links
     * колоната или ако скрейпърът не върна links): [text](url) + голи URL-и.
     *
     * @return array<int, string>
     */
    private function linksFromMarkdown(string $markdown, string $baseUrl): array
    {
        preg_match_all('/\]\(([^)\s]+)\)/', $markdown, $m);

        $links = [];
        foreach (array_slice($m[1] ?? [], 0, 500) as $href) {
            $abs = $this->absoluteUrl($href, $baseUrl);
            if ($abs !== null && ! $this->isAsset($abs)) {
                $links[] = $abs;
            }
        }

        return array_values(array_unique($links));
    }

    /** @return array<int, string> */
    private function sitemapUrls(string $root): array
    {
        $sitemaps = [$root.'/sitemap.xml'];

        $robots = $this->fetchRaw($root.'/robots.txt');
        if ($robots !== null && preg_match_all('/^\s*Sitemap:\s*(\S+)/im', $robots, $m)) {
            $sitemaps = array_merge($m[1], $sitemaps);
        }

        return array_values(array_unique($sitemaps));
    }

    /**
     * Return <loc> entries from a sitemap. Follows one level of sitemap-index
     * nesting (a sitemap that lists other sitemaps).
     *
     * @return array<int, string>
     */
    private function locsFromSitemap(string $sitemapUrl, bool $followIndex = true): array
    {
        $xml = $this->fetchRaw($sitemapUrl);
        if ($xml === null) {
            return [];
        }

        preg_match_all('/<loc>\s*([^<\s]+)\s*<\/loc>/i', $xml, $m);
        $locs = $m[1] ?? [];

        // If this is a sitemap index, the <loc>s point at other sitemaps.
        if ($followIndex && stripos($xml, '<sitemapindex') !== false) {
            $nested = [];
            foreach (array_slice($locs, 0, 10) as $childSitemap) {
                $nested = array_merge($nested, $this->locsFromSitemap($childSitemap, false));
            }

            return $nested;
        }

        return $locs;
    }

    /**
     * Extract same-host internal links from a page's RAW HTML (без JS — бързият
     * път за discoverUrls).
     *
     * @return array<int, string>
     */
    private function internalLinks(string $pageUrl, ?string $host): array
    {
        $html = $this->fetchRaw($pageUrl);
        if ($html === null) {
            return [];
        }

        preg_match_all('/href\s*=\s*["\']([^"\'#]+)["\']/i', $html, $m);

        $links = [];
        foreach ($m[1] ?? [] as $href) {
            $abs = $this->absoluteUrl($href, $pageUrl);
            if ($abs === null || parse_url($abs, PHP_URL_HOST) !== $host || $this->isAsset($abs)) {
                continue;
            }
            $norm = $this->cache->normalizeUrl($abs);
            if ($norm !== null) {
                $links[] = $norm;
            }
        }

        return array_values(array_unique($links));
    }

    private function fetchRaw(string $url): ?string
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; FlowAI-Crawler/1.0)'])
                ->get($url);

            return $response->successful() ? $response->body() : null;
        } catch (\Exception) {
            return null;
        }
    }

    private function rootUrl(string $url): ?string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($url, PHP_URL_HOST);

        return $host ? $scheme.'://'.$host : null;
    }

    private function absoluteUrl(string $href, string $base): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:') || str_starts_with($href, 'javascript:')) {
            return null;
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $root = $this->rootUrl($base);
        if ($root === null) {
            return null;
        }
        if (str_starts_with($href, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';

            return $scheme.':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $root.$href;
        }

        return rtrim($base, '/').'/'.$href;
    }

    private function isAsset(string $url): bool
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');

        return (bool) preg_match('/\.(jpg|jpeg|png|gif|svg|webp|ico|css|js|woff2?|ttf|eot|pdf|zip|rar|mp4|mp3|avi|mov|doc|docx|xls|xlsx)$/i', $path);
    }

    /**
     * Страници без съдържателна стойност: количка/вход/чекаут/wp-json/feed и
     * т.н. ПРАВНИТЕ страници (общи условия, политики) се ПАЗЯТ — носят реални
     * условия на бизнеса. Списъците са конфигурируеми (services.crawl).
     */
    private function isSkippedPage(string $url): bool
    {
        // URL-decode before matching so Cyrillic slugs like /количка are
        // compared as plain text, not as %d0%ba… hex sequences.
        $path = strtolower(urldecode(parse_url($url, PHP_URL_PATH) ?? ''));

        $segments = array_filter(explode('/', $path));
        $skipSegments = (array) config('services.crawl.skip_segments', [
            'login', 'logout', 'register', 'signup', 'sign-up', 'sign-in',
            'cart', 'checkout', 'basket', 'order', 'orders', 'payment',
            'account', 'profile', 'dashboard', 'wishlist', 'favorites',
            'search', 'feed', 'rss', 'sitemap', 'robots',
            'tag', 'tags', 'author', 'authors', 'wp-json', 'embed', 'amp',
            'количка', 'кошница', 'вход', 'изход', 'регистрация',
            'плащане', 'поръчка', 'профил',
        ]);
        foreach ($segments as $segment) {
            if (in_array($segment, $skipSegments, true)) {
                return true;
            }
        }

        $pattern = (string) config(
            'services.crawl.skip_pattern',
            '/wp-admin|wp-login|xmlrpc|oembed|\/embed|wp-content|sitemap\.xml|\/404$|\/500$/i',
        );

        return (bool) preg_match($pattern, $path);
    }
}
