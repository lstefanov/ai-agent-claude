<?php

namespace Tests\Unit;

use App\Services\BraveSearchService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BraveSearchServiceTest extends TestCase
{
    public function test_returns_parsed_results_on_success(): void
    {
        Http::fake([
            'api.search.brave.com/*' => Http::response([
                'web' => [
                    'results' => [
                        ['title' => 'Game News', 'url' => 'https://ign.com/1', 'description' => 'Latest gaming news', 'age' => '2 hours ago'],
                        ['title' => 'Xbox Update', 'url' => 'https://ign.com/2', 'description' => 'Xbox gets new update', 'age' => '1 day ago'],
                    ],
                ],
            ], 200),
        ]);

        $service = new BraveSearchService();
        $results = $service->search('video games');

        $this->assertCount(2, $results);
        $this->assertEquals('Game News', $results[0]['title']);
        $this->assertEquals('https://ign.com/1', $results[0]['url']);
    }

    public function test_retries_three_times_then_throws(): void
    {
        Http::fake([
            'api.search.brave.com/*' => Http::sequence()
                ->push([], 500)
                ->push([], 500)
                ->push([], 500),
        ]);

        $service = new BraveSearchService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/failed after 3 attempts/');

        $service->search('video games');
    }

    public function test_returns_empty_array_when_no_web_results(): void
    {
        Http::fake([
            'api.search.brave.com/*' => Http::response(['web' => []], 200),
        ]);

        $service = new BraveSearchService();
        $results = $service->search('video games');

        $this->assertSame([], $results);
    }
}
