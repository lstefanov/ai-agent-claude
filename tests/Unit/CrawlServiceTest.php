<?php

namespace Tests\Unit;

use App\Services\CrawlService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CrawlServiceTest extends TestCase
{
    public function test_scrape_returns_markdown_on_success(): void
    {
        Http::fake([
            'localhost:8189/scrape' => Http::response([
                'markdown'    => '# Ценоразпис',
                'success'     => true,
                'status_code' => 200,
            ], 200),
        ]);

        $service = new CrawlService();
        $result  = $service->scrape('https://example.com/prices');

        $this->assertSame('# Ценоразпис', $result);
    }

    public function test_scrape_returns_null_when_service_returns_error(): void
    {
        Http::fake([
            'localhost:8189/scrape' => Http::response([], 500),
        ]);

        $service = new CrawlService();
        $this->assertNull($service->scrape('https://example.com/prices'));
    }

    public function test_scrape_returns_null_when_markdown_is_empty(): void
    {
        Http::fake([
            'localhost:8189/scrape' => Http::response([
                'markdown' => '',
                'success'  => false,
            ], 200),
        ]);

        $service = new CrawlService();
        $this->assertNull($service->scrape('https://example.com/prices'));
    }

    public function test_scrape_returns_null_on_connection_exception(): void
    {
        Http::fake([
            'localhost:8189/scrape' => fn () => throw new \Exception('Connection refused'),
        ]);

        $service = new CrawlService();
        $this->assertNull($service->scrape('https://example.com/prices'));
    }

    public function test_is_available_returns_true_when_health_endpoint_responds(): void
    {
        Http::fake([
            'localhost:8189/health' => Http::response(['status' => 'ok'], 200),
        ]);

        $service = new CrawlService();
        $this->assertTrue($service->isAvailable());
    }

    public function test_is_available_returns_false_on_connection_error(): void
    {
        Http::fake([
            'localhost:8189/health' => fn () => throw new \Exception('refused'),
        ]);

        $service = new CrawlService();
        $this->assertFalse($service->isAvailable());
    }
}
