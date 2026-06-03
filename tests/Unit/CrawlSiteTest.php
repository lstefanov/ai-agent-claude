<?php

namespace Tests\Unit;

use App\Services\CrawlService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CrawlSiteTest extends TestCase
{
    public function test_discover_urls_uses_sitemap(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response("User-agent: *\n", 200),
            'https://example.com/sitemap.xml' => Http::response(
                '<urlset><url><loc>https://example.com/</loc></url>'
                .'<url><loc>https://example.com/uslugi</loc></url>'
                .'<url><loc>https://example.com/ceni</loc></url>'
                .'<url><loc>https://example.com/logo.png</loc></url></urlset>',
                200
            ),
            '*' => Http::response('', 404),
        ]);

        $urls = (new CrawlService)->discoverUrls('https://example.com');

        $this->assertContains('https://example.com', $urls);
        $this->assertContains('https://example.com/uslugi', $urls);
        $this->assertContains('https://example.com/ceni', $urls);
        // asset URLs are filtered out
        $this->assertNotContains('https://example.com/logo.png', $urls);
    }

    public function test_crawl_site_scrapes_each_discovered_page(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response(
                '<urlset><url><loc>https://example.com/</loc></url>'
                .'<url><loc>https://example.com/contacts</loc></url></urlset>',
                200
            ),
            '*/scrape' => Http::response(['markdown' => '# Page content'], 200),
            '*' => Http::response('', 404),
        ]);

        $pages = (new CrawlService)->crawlSite('https://example.com');

        $this->assertArrayHasKey('https://example.com', $pages);
        $this->assertArrayHasKey('https://example.com/contacts', $pages);
        $this->assertStringContainsString('Page content', $pages['https://example.com']);
    }
}
