<?php

namespace Tests\Unit;

use App\Agents\DeepResearcherAgent;
use App\Agents\Tools\AgentTool;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\OllamaService;
use Mockery;
use Tests\TestCase;

class DeepResearcherAntiHallucinationTest extends TestCase
{
    public function test_zero_scraped_pages_returns_warning_not_fabricated_data(): void
    {
        // The LLM must NEVER be asked to synthesize a report when nothing was
        // scraped — that is exactly how run 65 produced a fake price table.
        $ollama = Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->never();

        // scrape_page always fails (simulates Crawl4AI :8189 being down). No
        // discover_urls / crawl_site tools → map-reduce and crawl fallback both skip,
        // so siteContent ends up empty and the anti-hallucination guard fires.
        $failingScrape = new class implements AgentTool
        {
            public function name(): string
            {
                return 'scrape_page';
            }

            public function execute(array $params): string
            {
                return 'Scraping not available for this page.';
            }
        };

        $researcher = new DeepResearcherAgent($ollama, [$failingScrape]);

        $agent = new Agent([
            'type' => 'deep_researcher',
            'model' => 'mistral-nemo',
            'prompt_template' => 'Обходи {{url}} и извлечи услуги и цени.',
        ]);
        $agentRun = new AgentRun(['input' => 'Обходи https://primelaser.bg']);

        $output = $researcher->run($agent, $agentRun, [
            'target_url' => 'https://primelaser.bg',
            'url' => 'https://primelaser.bg',
        ]);

        // Clear warning, and NO fabricated prices / phones.
        $this->assertStringContainsString('НЕ можа да бъде обходен', $output);
        $this->assertStringNotContainsString('лв', $output);
        $this->assertDoesNotMatchRegularExpression('/\+359/', $output);
    }
}
