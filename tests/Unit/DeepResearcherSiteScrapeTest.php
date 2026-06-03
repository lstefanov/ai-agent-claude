<?php

namespace Tests\Unit;

use App\Agents\DeepResearcherAgent;
use App\Agents\Tools\AgentTool;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\OllamaService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class DeepResearcherSiteScrapeTest extends TestCase
{
    public function test_directly_scrapes_target_site_homepage_when_url_in_context(): void
    {
        Http::fake(['*' => Http::response('', 200)]); // HEAD probes succeed

        $ollama = Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->andReturn('synthesized business profile');

        $scrapeTool = new class implements AgentTool
        {
            /** @var array<int, string> */
            public array $calls = [];

            public function name(): string
            {
                return 'scrape_page';
            }

            public function execute(array $params): string
            {
                $this->calls[] = $params['url'] ?? '';

                return '# Page content for '.($params['url'] ?? '');
            }
        };

        $researcher = new DeepResearcherAgent($ollama, [$scrapeTool]);

        $agent = new Agent([
            'type' => 'deep_researcher',
            'model' => 'mistral-nemo',
            'prompt_template' => 'Обходи {{url}} и извлечи услуги и цени.',
            // pre-set queries so generateSearchQueries (which calls the LLM) is skipped
            'config' => ['search_queries' => ['skip-search']],
        ]);
        $agentRun = new AgentRun(['input' => 'Обходи https://primelaser.bg']);

        $output = $researcher->run($agent, $agentRun, [
            'target_url' => 'https://primelaser.bg',
            'url' => 'https://primelaser.bg',
        ]);

        $this->assertSame('synthesized business profile', $output);
        $this->assertContains('https://primelaser.bg', $scrapeTool->calls, 'Homepage must be scraped directly');
        $this->assertNotEmpty($scrapeTool->calls);
    }
}
