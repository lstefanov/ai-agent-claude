<?php

namespace Tests\Unit;

use App\Agents\DeepResearcherAgent;
use App\Agents\Tools\AgentTool;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\OllamaService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeepResearcherAgentTest extends TestCase
{
    private function makeAgent(array $config = []): Agent
    {
        $agent = new Agent;
        $agent->role = 'Deep researcher';
        $agent->model = 'gemma2:9b';
        $agent->output_language = 'bg';
        $agent->config = array_merge(['search_queries' => ['фитнес цени']], $config);

        return $agent;
    }

    private function makeAgentRun(string $input = 'Research prices'): AgentRun
    {
        $run = new AgentRun;
        $run->input = $input;

        return $run;
    }

    private function makeSearchTool(string $resultsToReturn): AgentTool
    {
        $tool = \Mockery::mock(AgentTool::class);
        $tool->shouldReceive('name')->andReturn('web_search');
        $tool->shouldReceive('execute')->andReturn($resultsToReturn);

        return $tool;
    }

    public function test_scrapes_pricing_url_detected_in_search_results(): void
    {
        // /tseni/ path contains 'tseni' keyword → used directly, no HEAD check needed
        $searchResults = "[1] Title: Next Level\n    URL: https://nextlevelclub.bg/tseni/\n    Summary: prices";

        $scraperTool = \Mockery::mock(AgentTool::class);
        $scraperTool->shouldReceive('name')->andReturn('scrape_page');
        $scraperTool->shouldReceive('execute')
            ->once()
            ->with(['url' => 'https://nextlevelclub.bg/tseni/'])
            ->andReturn('## Ценоразпис\n| Абонамент | 89 лв. |');

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->andReturn('synthesis output');

        $agent = $this->makeAgent(['scrape_pricing_pages' => true, 'max_pages_to_scrape' => 1]);
        $deep = new DeepResearcherAgent($ollama, [$this->makeSearchTool($searchResults), $scraperTool]);
        $result = $deep->run($agent, $this->makeAgentRun(), []);

        $this->assertSame('synthesis output', $result);
    }

    public function test_falls_back_gracefully_when_scraper_not_registered(): void
    {
        $searchResults = "[1] Title: Next Level\n    URL: https://nextlevelclub.bg/tseni/\n    Summary: prices";

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->once()->andReturn('search only output');

        $deep = new DeepResearcherAgent($ollama, [$this->makeSearchTool($searchResults)]);
        $result = $deep->run($this->makeAgent(), $this->makeAgentRun(), []);

        $this->assertSame('search only output', $result);
    }

    public function test_skips_scraping_when_config_flag_is_false(): void
    {
        $scraperTool = \Mockery::mock(AgentTool::class);
        $scraperTool->shouldReceive('name')->andReturn('scrape_page');
        $scraperTool->shouldNotReceive('execute');

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->once()->andReturn('output');

        $agent = $this->makeAgent(['scrape_pricing_pages' => false]);
        $deep = new DeepResearcherAgent(
            $ollama,
            [$this->makeSearchTool('some results'), $scraperTool]
        );
        $deep->run($agent, $this->makeAgentRun(), []);
    }

    public function test_discovers_pricing_page_via_head_request_when_url_has_no_pricing_path(): void
    {
        $searchResults = "[1] Title: MAXIFIT\n    URL: https://maxifit.bg/about/\n    Summary: gym";

        Http::fake([
            'https://maxifit.bg/цени' => Http::response('', 404),
            'https://maxifit.bg/цена' => Http::response('', 404),
            'https://maxifit.bg/абонамент' => Http::response('', 404),
            'https://maxifit.bg/абонаменти' => Http::response('', 404),
            'https://maxifit.bg/карти' => Http::response('', 404),
            'https://maxifit.bg/ceni' => Http::response('', 404),
            'https://maxifit.bg/tseni' => Http::response('', 200),
            '*' => Http::response('', 404),
        ]);

        $scraperTool = \Mockery::mock(AgentTool::class);
        $scraperTool->shouldReceive('name')->andReturn('scrape_page');
        $scraperTool->shouldReceive('execute')
            ->once()
            ->with(['url' => 'https://maxifit.bg/tseni'])
            ->andReturn('# Prices\n| 89 лв. |');

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->andReturn('result');

        $agent = $this->makeAgent(['scrape_pricing_pages' => true, 'max_pages_to_scrape' => 1]);
        $deep = new DeepResearcherAgent($ollama, [$this->makeSearchTool($searchResults), $scraperTool]);
        $deep->run($agent, $this->makeAgentRun(), []);
    }

    public function test_discovers_price_list_path_via_head_request(): void
    {
        $searchResults = "[1] Title: Gym\n    URL: https://example-gym.bg/about/\n    Summary: gym";

        Http::fake([
            'https://example-gym.bg/price-list' => Http::response('', 200),
            '*' => Http::response('', 404),
        ]);

        $scraperTool = \Mockery::mock(AgentTool::class);
        $scraperTool->shouldReceive('name')->andReturn('scrape_page');
        $scraperTool->shouldReceive('execute')
            ->once()
            ->with(['url' => 'https://example-gym.bg/price-list'])
            ->andReturn('# Prices');

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->andReturn('result');

        $agent = $this->makeAgent(['scrape_pricing_pages' => true, 'max_pages_to_scrape' => 1]);
        $deep = new DeepResearcherAgent($ollama, [$this->makeSearchTool($searchResults), $scraperTool]);
        $deep->run($agent, $this->makeAgentRun(), []);
    }

    public function test_deduplicates_domains_across_search_results(): void
    {
        $searchResults = "[1] Title: A\n    URL: https://nextlevelclub.bg/news/\n    Summary: s";
        $searchResults .= "\n\n[2] Title: B\n    URL: https://nextlevelclub.bg/tseni/\n    Summary: prices";

        $scraperTool = \Mockery::mock(AgentTool::class);
        $scraperTool->shouldReceive('name')->andReturn('scrape_page');
        $scraperTool->shouldReceive('execute')
            ->once()   // only once despite two results from same domain
            ->andReturn('# Prices');

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->andReturn('output');

        $agent = $this->makeAgent(['scrape_pricing_pages' => true, 'max_pages_to_scrape' => 5]);
        $deep = new DeepResearcherAgent($ollama, [$this->makeSearchTool($searchResults), $scraperTool]);
        $deep->run($agent, $this->makeAgentRun(), []);
    }
}
