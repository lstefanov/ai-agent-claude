<?php

namespace Tests\Unit;

use App\Agents\Tools\BraveSearchTool;
use App\Services\BraveSearchService;
use Tests\TestCase;

class BraveSearchToolTest extends TestCase
{
    public function test_name_returns_web_search(): void
    {
        $service = \Mockery::mock(BraveSearchService::class);
        $tool    = new BraveSearchTool($service);

        $this->assertSame('web_search', $tool->name());
    }

    public function test_formats_results_with_all_fields(): void
    {
        $service = \Mockery::mock(BraveSearchService::class);
        $service->shouldReceive('search')
            ->once()
            ->with('video games')
            ->andReturn([
                ['title' => 'IGN Review', 'url' => 'https://ign.com', 'description' => 'Top games of 2025', 'age' => '3 hours ago'],
            ]);

        $tool   = new BraveSearchTool($service);
        $output = $tool->execute(['query' => 'video games']);

        $this->assertStringContainsString('[1] Title: IGN Review', $output);
        $this->assertStringContainsString('URL: https://ign.com', $output);
        $this->assertStringContainsString('Date: 3 hours ago', $output);
        $this->assertStringContainsString('Summary: Top games of 2025', $output);
    }

    public function test_omits_date_line_when_age_missing(): void
    {
        $service = \Mockery::mock(BraveSearchService::class);
        $service->shouldReceive('search')->andReturn([
            ['title' => 'Title', 'url' => 'https://x.com', 'description' => 'Desc'],
        ]);

        $tool   = new BraveSearchTool($service);
        $output = $tool->execute(['query' => 'test']);

        $this->assertStringNotContainsString('Date:', $output);
    }

    public function test_returns_no_results_message_when_empty(): void
    {
        $service = \Mockery::mock(BraveSearchService::class);
        $service->shouldReceive('search')->andReturn([]);

        $tool   = new BraveSearchTool($service);
        $output = $tool->execute(['query' => 'test']);

        $this->assertSame('No web search results found.', $output);
    }
}
