<?php

namespace Tests\Unit;

use App\Agents\CompetitorProfilerAgent;
use App\Agents\Tools\AgentTool;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\OllamaService;
use Tests\TestCase;

class CompetitorProfilerAgentTest extends TestCase
{
    public function test_scrapes_multiple_competitor_pricing_pages_from_search_results(): void
    {
        $searchResults = <<<'TEXT'
[1] Title: Ability Spa Prices
    URL: https://abilityspa.com/prices
    Summary: Fitness cards

[2] Title: V Gym Membership
    URL: https://vgym.bg/pricing
    Summary: Fitness and spa
TEXT;

        $searchTool = \Mockery::mock(AgentTool::class);
        $searchTool->shouldReceive('name')->andReturn('web_search');
        $searchTool->shouldReceive('execute')->once()->andReturn($searchResults);

        $scraperTool = \Mockery::mock(AgentTool::class);
        $scraperTool->shouldReceive('name')->andReturn('scrape_page');
        $scraperTool->shouldReceive('execute')
            ->once()
            ->with(['url' => 'https://abilityspa.com/prices'])
            ->andReturn('| Ability Spa | 68.45 лв. |');
        $scraperTool->shouldReceive('execute')
            ->once()
            ->with(['url' => 'https://vgym.bg/pricing'])
            ->andReturn('| V Gym | 79 лв. |');

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $systemPrompt, $userMessage) {
                return str_contains($systemPrompt, 'SCRAPED PRICING PAGES')
                    && str_contains($systemPrompt, 'Ability Spa | 68.45')
                    && str_contains($systemPrompt, 'V Gym | 79');
            })
            ->andReturn('profiles');

        $agent = new Agent;
        $agent->role = 'Profile competitors';
        $agent->model = 'qwen2.5:14b';
        $agent->output_language = 'bg';
        $agent->config = ['max_pages_to_scrape' => 2];

        $run = new AgentRun;
        $run->input = 'Русе фитнес цени';

        $result = (new CompetitorProfilerAgent($ollama, [$searchTool, $scraperTool]))->run($agent, $run, [
            'flow_topic' => 'конкурентни цени фитнес зали Русе България',
        ]);

        $this->assertSame('profiles', $result);
    }
}
