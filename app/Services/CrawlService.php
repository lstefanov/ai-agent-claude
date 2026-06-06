<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class CrawlService
{
    private string $baseUrl;

    private int $timeout;      // per-page render timeout sent to the scraper

    private int $httpTimeout;  // PHP client timeout — must exceed the scraper's two-pass budget

    public function __construct()
    {
        $this->baseUrl = config('services.crawl.url', 'http://localhost:8189');
        $this->timeout = (int) config('services.crawl.timeout', 35);
        // The scraper now does up to two render passes (networkidle → load+magic),
        // so a single /scrape can take ~timeout*2+10s. Give the HTTP client headroom.
        $this->httpTimeout = $this->timeout * 2 + 20;
    }

    /**
     * Scrape a URL and return clean markdown. Returns null on any failure.
     */
    public function scrape(string $url): ?string
    {
        try {
            $response = Http::timeout($this->httpTimeout)->post("{$this->baseUrl}/scrape", [
                'url' => $url,
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

    /**
     * Scrape many URLs CONCURRENTLY. Returns url => markdown for pages that
     * returned non-empty content. Requests run in waves of $concurrency.
     *
     * @param  array<int, string>  $urls
     * @return array<string, string>
     */
    public function scrapeMany(array $urls, int $concurrency = 4): array
    {
        $concurrency = max(1, $concurrency);
        $out = [];

        foreach (array_chunk(array_values($urls), $concurrency) as $wave) {
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
                        }
                    }
                } catch (\Throwable) {
                    // skip this URL
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
     * Crawl an entire site: discover its pages (sitemap → internal links) and
     * scrape each one to markdown.
     *
     * Pages are ordered so that product/service pages (path starts with /p/)
     * come first — they carry the most business-relevant data (names, prices).
     * The homepage is always first regardless.
     *
     * @return array<string, string> map of url => markdown (only non-empty pages)
     */
    public function crawlSite(string $url, ?int $max = null): array
    {
        $max = $max ?? (int) config('services.crawl.max_pages', 20);
        $urls = $this->discoverUrls($url, $max);

        // Prioritise: homepage first, then /p/ product/service pages, then the rest.
        $homepage = rtrim($this->rootUrl($url) ?? '', '/');
        usort($urls, function (string $a, string $b) use ($homepage): int {
            $aHome = rtrim($a, '/') === $homepage ? 0 : 1;
            $bHome = rtrim($b, '/') === $homepage ? 0 : 1;
            if ($aHome !== $bHome) {
                return $aHome - $bHome;
            }
            $aProduct = str_contains(parse_url($a, PHP_URL_PATH) ?? '', '/p/') ? 0 : 1;
            $bProduct = str_contains(parse_url($b, PHP_URL_PATH) ?? '', '/p/') ? 0 : 1;

            return $aProduct - $bProduct;
        });

        $pages = [];
        foreach ($urls as $pageUrl) {
            if (count($pages) >= $max) {
                break;
            }
            $markdown = $this->scrape($pageUrl);
            if ($markdown !== null && trim($markdown) !== '') {
                $pages[$pageUrl] = $markdown;
            }
        }

        return $pages;
    }

    /**
     * Discover internal page URLs for a site. Order: homepage first, then sitemap
     * entries, then links found by crawling the homepage (and a few sub-pages).
     *
     * @return array<int, string>
     */
    public function discoverUrls(string $url, ?int $max = null): array
    {
        $max = $max ?? (int) config('services.crawl.max_pages', 20);
        $root = $this->rootUrl($url);
        if ($root === null) {
            return [];
        }
        $host = parse_url($root, PHP_URL_HOST);

        $homepage = rtrim($url, '/') ?: $root;
        $found = array_filter([$this->normalizeUrl($homepage)]);

        // 1) sitemap(s) — most complete source of pages
        foreach ($this->sitemapUrls($root) as $sitemap) {
            foreach ($this->locsFromSitemap($sitemap) as $loc) {
                $found[] = $loc;
                if (count(array_unique($found)) >= $max) {
                    break 2;
                }
            }
        }

        // 2) link discovery from the homepage (+ a few discovered pages) if we are
        //    still short of pages — covers sites without a sitemap.
        if (count(array_unique($found)) < $max) {
            $linkSeeds = array_slice(array_values(array_unique($found)), 0, 6);
            foreach ($linkSeeds as $seed) {
                foreach ($this->internalLinks($seed, $host) as $link) {
                    $found[] = $link;
                }
                if (count(array_unique($found)) >= $max) {
                    break;
                }
            }
        }

        // normalise, keep same host, drop assets + utility pages, dedupe, cap
        $clean = [];
        foreach ($found as $candidate) {
            $norm = $this->normalizeUrl($candidate);
            if ($norm === null
                || parse_url($norm, PHP_URL_HOST) !== $host
                || $this->isAsset($norm)
                || $this->isUtilityPage($norm)
                || in_array($norm, $clean, true)) {
                continue;
            }
            $clean[] = $norm;
            if (count($clean) >= $max) {
                break;
            }
        }

        return $clean;
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
     * Extract same-host internal links from a page's HTML.
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
            if ($abs !== null && parse_url($abs, PHP_URL_HOST) === $host && ! $this->isAsset($abs)) {
                $links[] = $this->normalizeUrl($abs);
            }
        }

        return array_values(array_unique(array_filter($links)));
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

    private function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return null;
        }
        // strip fragment and trailing slash
        $url = preg_replace('/#.*$/', '', $url);

        return rtrim($url, '/');
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
     * Returns true for pages that carry no business-relevant content: login,
     * cart, checkout, account, legal notices, cookie/privacy policies, feeds, etc.
     */
    private function isUtilityPage(string $url): bool
    {
        // URL-decode before matching so Cyrillic slugs like /общи-условия are
        // compared as plain text, not as %d0%be%d0%b1%d1%89%d0%b8-... hex sequences.
        $path = strtolower(urldecode(parse_url($url, PHP_URL_PATH) ?? ''));

        // Exact-segment patterns (matches /login, /s/login, /bg/login, etc.)
        $segments = array_filter(explode('/', $path));
        $utilitySegments = [
            'login', 'logout', 'register', 'signup', 'sign-up', 'sign-in',
            'cart', 'checkout', 'basket', 'order', 'orders', 'payment',
            'account', 'profile', 'dashboard', 'wishlist', 'favorites',
            'search', 'feed', 'rss', 'sitemap', 'robots',
            'tag', 'tags', 'category', 'categories',
            'author', 'authors', 'wp-json', 'embed', 'amp',
        ];
        foreach ($segments as $segment) {
            if (in_array($segment, $utilitySegments, true)) {
                return true;
            }
        }

        // Substring patterns (catches /cookie-policy, /политика-за-поверителност, etc.)
        return (bool) preg_match(
            '/cookie[_-]?polic|privacy[_-]?polic|polic[iy]|terms[_-]?of|general[_-]?condition'
            .'|повери|условия|поверит|gdpr|disclaimer|impressum|imprint|legal|404|500'
            .'|sitemap\.xml|wp-admin|wp-login|xmlrpc|wp-json|oembed|\/embed|wp-content/i',
            $path
        );
    }
}
