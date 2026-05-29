<?php

namespace Tests\Unit;

use App\Services\AgentGeneratorService;
use App\Services\ModelSelectorService;
use App\Services\OllamaService;
use Tests\TestCase;

class AgentGeneratorWebResearchTest extends TestCase
{
    private function makeService(string $llmResponse = 'NO'): AgentGeneratorService
    {
        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->andReturn($llmResponse);

        $selector = \Mockery::mock(ModelSelectorService::class);

        return new AgentGeneratorService($ollama, $selector);
    }

    public function test_keyword_новини_triggers_web_research(): void
    {
        $service = $this->makeService();

        $result = $this->invokeNeedsWebResearch($service, 'Този flow преглежда актуални новини от игровия пазар.');

        $this->assertTrue($result);
    }

    public function test_keyword_web_triggers_web_research(): void
    {
        $service = $this->makeService();

        $result = $this->invokeNeedsWebResearch($service, 'Web scraping of gaming sites for updates.');

        $this->assertTrue($result);
    }

    public function test_no_keywords_falls_back_to_llm_yes(): void
    {
        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->once()->andReturn('YES');
        $selector = \Mockery::mock(ModelSelectorService::class);
        $service  = new AgentGeneratorService($ollama, $selector);

        $result = $this->invokeNeedsWebResearch($service, 'Анализирай продажбените данни от миналия месец.');

        $this->assertTrue($result);
    }

    public function test_no_keywords_falls_back_to_llm_no(): void
    {
        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->once()->andReturn('NO');
        $selector = \Mockery::mock(ModelSelectorService::class);
        $service  = new AgentGeneratorService($ollama, $selector);

        $result = $this->invokeNeedsWebResearch($service, 'Анализирай продажбените данни от миналия месец.');

        $this->assertFalse($result);
    }

    public function test_researcher_moved_to_first_position(): void
    {
        $service = $this->makeService();

        $agents = [
            ['name' => 'Analyzer', 'type' => 'analyzer', 'order' => 1],
            ['name' => 'Researcher', 'type' => 'researcher', 'order' => 2],
            ['name' => 'Content', 'type' => 'content_bg', 'order' => 3],
        ];

        $result = $this->invokeEnsureResearcherFirst($service, $agents);

        $this->assertSame('researcher', $result[0]['type']);
        $this->assertSame(1, $result[0]['order']);
        $this->assertSame(2, $result[1]['order']);
        $this->assertSame(3, $result[2]['order']);
    }

    public function test_researcher_already_first_unchanged(): void
    {
        $service = $this->makeService();

        $agents = [
            ['name' => 'Researcher', 'type' => 'researcher', 'order' => 1],
            ['name' => 'Content', 'type' => 'content_bg', 'order' => 2],
        ];

        $result = $this->invokeEnsureResearcherFirst($service, $agents);

        $this->assertSame('researcher', $result[0]['type']);
        $this->assertSame(1, $result[0]['order']);
    }

    public function test_no_researcher_returns_agents_unchanged(): void
    {
        $service = $this->makeService();

        $agents = [
            ['name' => 'Analyzer', 'type' => 'analyzer', 'order' => 1],
            ['name' => 'Content', 'type' => 'content_bg', 'order' => 2],
        ];

        $result = $this->invokeEnsureResearcherFirst($service, $agents);

        $this->assertSame('analyzer', $result[0]['type']);
    }

    private function invokeNeedsWebResearch(AgentGeneratorService $service, string $description): bool
    {
        $method = new \ReflectionMethod($service, 'needsWebResearch');
        $method->setAccessible(true);
        return $method->invoke($service, $description);
    }

    private function invokeEnsureResearcherFirst(AgentGeneratorService $service, array $agents): array
    {
        $method = new \ReflectionMethod($service, 'ensureResearcherFirst');
        $method->setAccessible(true);
        return $method->invoke($service, $agents);
    }
}
