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

    public function test_skips_low_value_aggregator_domains_when_direct_competitor_sources_are_available(): void
    {
        $searchResults = <<<'TEXT'
[1] Title: Grabo fitness offers
    URL: https://grabo.bg/ceni
    Summary: Marketplace offers without stable official prices

[2] Title: V Gym Prices
    URL: https://vgym.bg/pricing
    Summary: Official fitness membership prices
TEXT;

        $searchTool = \Mockery::mock(AgentTool::class);
        $searchTool->shouldReceive('name')->andReturn('web_search');
        $searchTool->shouldReceive('execute')->once()->andReturn($searchResults);

        $scraperTool = \Mockery::mock(AgentTool::class);
        $scraperTool->shouldReceive('name')->andReturn('scrape_page');
        $scraperTool->shouldReceive('execute')
            ->once()
            ->with(['url' => 'https://vgym.bg/pricing'])
            ->andReturn('| V Gym | 79 лв. |');

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')
            ->once()
            ->withArgs(fn ($model, $systemPrompt) => str_contains($systemPrompt, 'V Gym | 79'))
            ->andReturn('profiles');

        $agent = new Agent;
        $agent->role = 'Profile competitors';
        $agent->model = 'qwen2.5:14b';
        $agent->output_language = 'bg';
        $agent->config = ['max_pages_to_scrape' => 1];

        $run = new AgentRun;
        $run->input = 'Русе фитнес цени';

        (new CompetitorProfilerAgent($ollama, [$searchTool, $scraperTool]))->run($agent, $run, [
            'flow_topic' => 'конкурентни цени фитнес зали Русе България',
        ]);
    }

    public function test_uses_aggregator_fallback_when_direct_sources_have_no_numeric_prices(): void
    {
        $searchResults = <<<'TEXT'
[1] Title: Generic Gym Prices
    URL: https://generic-gym.bg/prices
    Summary: Official services page

[2] Title: Grabo fitness offers
    URL: https://grabo.bg/ceni
    Summary: Marketplace offers with discounted fitness prices
TEXT;

        $searchTool = \Mockery::mock(AgentTool::class);
        $searchTool->shouldReceive('name')->andReturn('web_search');
        $searchTool->shouldReceive('execute')->once()->andReturn($searchResults);

        $scraperTool = \Mockery::mock(AgentTool::class);
        $scraperTool->shouldReceive('name')->andReturn('scrape_page');
        $scraperTool->shouldReceive('execute')
            ->once()
            ->with(['url' => 'https://generic-gym.bg/prices'])
            ->andReturn('Official services page. Contact us for pricing.');
        $scraperTool->shouldReceive('execute')
            ->once()
            ->with(['url' => 'https://grabo.bg/ceni'])
            ->andReturn('| Grabo | Карта | 39 лв. |');

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')
            ->once()
            ->withArgs(fn ($model, $systemPrompt) => str_contains($systemPrompt, 'Grabo | Карта | 39 лв.')
                && ! str_contains($systemPrompt, 'Contact us for pricing'))
            ->andReturn('profiles');

        $agent = new Agent;
        $agent->role = 'Profile competitors';
        $agent->model = 'qwen2.5:14b';
        $agent->output_language = 'bg';
        $agent->config = ['max_pages_to_scrape' => 1];

        $run = new AgentRun;
        $run->input = 'Русе фитнес цени';

        (new CompetitorProfilerAgent($ollama, [$searchTool, $scraperTool]))->run($agent, $run, [
            'flow_topic' => 'конкурентни цени фитнес зали Русе България',
        ]);
    }

    public function test_uses_low_value_aggregator_domain_as_fallback_when_it_is_the_only_pricing_source(): void
    {
        $searchResults = <<<'TEXT'
[1] Title: Grabo fitness offers
    URL: https://grabo.bg/ceni
    Summary: Marketplace offers with discounted fitness prices
TEXT;

        $searchTool = \Mockery::mock(AgentTool::class);
        $searchTool->shouldReceive('name')->andReturn('web_search');
        $searchTool->shouldReceive('execute')->once()->andReturn($searchResults);

        $scraperTool = \Mockery::mock(AgentTool::class);
        $scraperTool->shouldReceive('name')->andReturn('scrape_page');
        $scraperTool->shouldReceive('execute')
            ->once()
            ->with(['url' => 'https://grabo.bg/ceni'])
            ->andReturn('| Grabo | Карта | 39 лв. |');

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')
            ->once()
            ->withArgs(fn ($model, $systemPrompt) => str_contains($systemPrompt, 'Grabo | Карта | 39 лв.'))
            ->andReturn('profiles');

        $agent = new Agent;
        $agent->role = 'Profile competitors';
        $agent->model = 'qwen2.5:14b';
        $agent->output_language = 'bg';
        $agent->config = ['max_pages_to_scrape' => 1];

        $run = new AgentRun;
        $run->input = 'Русе фитнес цени';

        (new CompetitorProfilerAgent($ollama, [$searchTool, $scraperTool]))->run($agent, $run, [
            'flow_topic' => 'конкурентни цени фитнес зали Русе България',
        ]);
    }

    public function test_skips_scraped_pages_without_numeric_price_signals_before_counting_budget(): void
    {
        $searchResults = <<<'TEXT'
[1] Title: Generic Gym Prices
    URL: https://generic-gym.bg/prices
    Summary: Services page

[2] Title: V Gym Prices
    URL: https://vgym.bg/pricing
    Summary: Official fitness membership prices
TEXT;

        $searchTool = \Mockery::mock(AgentTool::class);
        $searchTool->shouldReceive('name')->andReturn('web_search');
        $searchTool->shouldReceive('execute')->once()->andReturn($searchResults);

        $scraperTool = \Mockery::mock(AgentTool::class);
        $scraperTool->shouldReceive('name')->andReturn('scrape_page');
        $scraperTool->shouldReceive('execute')
            ->once()
            ->with(['url' => 'https://generic-gym.bg/prices'])
            ->andReturn('We offer fitness, spa and classes. Contact us for pricing.');
        $scraperTool->shouldReceive('execute')
            ->once()
            ->with(['url' => 'https://vgym.bg/pricing'])
            ->andReturn('| V Gym | Месечна карта | 79 лв. |');

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')
            ->once()
            ->withArgs(fn ($model, $systemPrompt) => str_contains($systemPrompt, 'V Gym | Месечна карта | 79 лв.')
                && ! str_contains($systemPrompt, 'Contact us for pricing'))
            ->andReturn('profiles');

        $agent = new Agent;
        $agent->role = 'Profile competitors';
        $agent->model = 'qwen2.5:14b';
        $agent->output_language = 'bg';
        $agent->config = ['max_pages_to_scrape' => 1];

        $run = new AgentRun;
        $run->input = 'Русе фитнес цени';

        (new CompetitorProfilerAgent($ollama, [$searchTool, $scraperTool]))->run($agent, $run, [
            'flow_topic' => 'конкурентни цени фитнес зали Русе България',
        ]);
    }
}
