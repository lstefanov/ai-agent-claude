<?php

namespace Tests\Unit;

use App\Services\AgentGeneratorService;
use App\Services\GeneratorService;
use App\Services\ModelSelectorService;
use App\Services\OllamaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentGeneratorWebResearchTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(string $llmResponse = 'NO'): AgentGeneratorService
    {
        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->andReturn($llmResponse);

        // selectModel is now called for EVERY agent (with a description hint as the
        // second arg), so use a catch-all that resolves by type.
        $selector = \Mockery::mock(ModelSelectorService::class);
        $selector->shouldReceive('selectModel')->andReturnUsing(
            fn (string $type, ?string $hint = null): string => $type === 'qa_verifier' ? 'phi3.5:mini' : 'todorov/bggpt'
        );

        return new AgentGeneratorService(new GeneratorService($ollama), $selector);
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
        $service  = new AgentGeneratorService(new GeneratorService($ollama), $selector);

        $result = $this->invokeNeedsWebResearch($service, 'Анализирай продажбените данни от миналия месец.');

        $this->assertTrue($result);
    }

    public function test_no_keywords_falls_back_to_llm_no(): void
    {
        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->once()->andReturn('NO');
        $selector = \Mockery::mock(ModelSelectorService::class);
        $service  = new AgentGeneratorService(new GeneratorService($ollama), $selector);

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

    public function test_required_tail_agents_are_added_when_missing(): void
    {
        $service = $this->makeService();

        $agents = [
            ['name' => 'Analyzer', 'type' => 'analyzer', 'order' => 1],
            ['name' => 'Content', 'type' => 'content_bg', 'order' => 2],
            ['name' => 'Caption', 'type' => 'caption_writer', 'order' => 3],
        ];

        $result = $this->invokeEnsureQaVerifierLast($service, $agents);
        $result = $this->invokeEnsureBgTextCorrectorBeforeQa($service, $result);

        $this->assertSame('bg_text_corrector', $result[count($result) - 2]['type']);
        $this->assertSame('qa_verifier', $result[count($result) - 1]['type']);
        $this->assertSame([1, 2, 3, 4, 5], array_column($result, 'order'));
    }

    public function test_generated_qa_verifier_defaults_to_sixty_percent_threshold(): void
    {
        $service = $this->makeService();

        $agents = $this->invokeParseAgentJson($service, json_encode([
            [
                'name' => 'Content',
                'type' => 'content_bg',
                'role' => 'Writes content',
            ],
            [
                'name' => 'QA',
                'type' => 'qa_verifier',
                'role' => 'Checks quality',
                'qa_threshold' => null,
            ],
        ]));

        $qaAgent = collect($agents)->firstWhere('type', 'qa_verifier');

        $this->assertSame(60, $qaAgent['qa_threshold']);
    }

    public function test_generated_qa_verifier_zero_threshold_defaults_to_sixty_percent(): void
    {
        $service = $this->makeService();

        $agents = $this->invokeParseAgentJson($service, json_encode([
            [
                'name' => 'Content',
                'type' => 'content_bg',
                'role' => 'Writes content',
            ],
            [
                'name' => 'QA',
                'type' => 'qa_verifier',
                'role' => 'Checks quality',
                'qa_threshold' => 0,
            ],
        ]));

        $qaAgent = collect($agents)->firstWhere('type', 'qa_verifier');

        $this->assertSame(60, $qaAgent['qa_threshold']);
    }

    public function test_bg_text_corrector_is_inserted_before_existing_qa(): void
    {
        $service = $this->makeService();

        $agents = [
            ['name' => 'Analyzer', 'type' => 'analyzer', 'order' => 1],
            ['name' => 'Content', 'type' => 'content_bg', 'order' => 2],
            ['name' => 'QA', 'type' => 'qa_verifier', 'order' => 3],
        ];

        $result = $this->invokeEnsureQaVerifierLast($service, $agents);
        $result = $this->invokeEnsureBgTextCorrectorBeforeQa($service, $result);

        $this->assertSame('bg_text_corrector', $result[2]['type']);
        $this->assertSame('qa_verifier', $result[3]['type']);
        $this->assertSame([1, 2, 3, 4], array_column($result, 'order'));
    }

    public function test_existing_bg_text_corrector_and_qa_are_reordered_to_pipeline_tail(): void
    {
        $service = $this->makeService();

        $agents = [
            ['name' => 'QA', 'type' => 'qa_verifier', 'order' => 1],
            ['name' => 'Corrector', 'type' => 'bg_text_corrector', 'order' => 2],
            ['name' => 'Analyzer', 'type' => 'analyzer', 'order' => 3],
            ['name' => 'Content', 'type' => 'content_bg', 'order' => 4],
        ];

        $result = $this->invokeEnsureQaVerifierLast($service, $agents);
        $result = $this->invokeEnsureBgTextCorrectorBeforeQa($service, $result);

        $this->assertSame(['analyzer', 'content_bg', 'bg_text_corrector', 'qa_verifier'], array_column($result, 'type'));
        $this->assertSame([1, 2, 3, 4], array_column($result, 'order'));
    }

    public function test_duplicate_tail_agents_are_deduplicated_to_exactly_one_corrector_and_one_qa(): void
    {
        $service = $this->makeService();

        $agents = [
            ['name' => 'First QA', 'type' => 'qa_verifier', 'order' => 1],
            ['name' => 'First Corrector', 'type' => 'bg_text_corrector', 'order' => 2],
            ['name' => 'Analyzer', 'type' => 'analyzer', 'order' => 3],
            ['name' => 'Second Corrector', 'type' => 'bg_text_corrector', 'order' => 4],
            ['name' => 'Content', 'type' => 'content_bg', 'order' => 5],
            ['name' => 'Second QA', 'type' => 'qa_verifier', 'order' => 6],
        ];

        $result = $this->invokeEnsureQaVerifierLast($service, $agents);
        $result = $this->invokeEnsureBgTextCorrectorBeforeQa($service, $result);

        $this->assertSame(1, count(array_filter($result, fn ($agent) => $agent['type'] === 'bg_text_corrector')));
        $this->assertSame(1, count(array_filter($result, fn ($agent) => $agent['type'] === 'qa_verifier')));
        $this->assertSame('bg_text_corrector', $result[count($result) - 2]['type']);
        $this->assertSame('qa_verifier', $result[count($result) - 1]['type']);
        $this->assertSame([1, 2, 3, 4], array_column($result, 'order'));
    }

    public function test_generate_forwards_progress_callback_and_uses_bounded_architect_output(): void
    {
        $stages = [];
        $onProgress = function (?string $stage = null) use (&$stages): void {
            if ($stage) {
                $stages[] = $stage;
            }
        };

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $systemPrompt, $userMessage, $options, $progressCallback) use ($onProgress) {
                return $model !== ''
                    && str_contains($systemPrompt, 'AI архитект')
                    && str_contains($userMessage, 'Flow за изграждане')
                    && ($options['num_predict'] ?? null) === 4000
                    && $progressCallback === $onProgress;
            })
            ->andReturn(json_encode([
                ['name' => 'Researcher', 'type' => 'researcher', 'role' => 'Researches', 'order' => 1],
                ['name' => 'Writer', 'type' => 'content_bg', 'role' => 'Writes', 'order' => 2],
                ['name' => 'Corrector', 'type' => 'bg_text_corrector', 'role' => 'Corrects', 'order' => 3],
                ['name' => 'QA', 'type' => 'qa_verifier', 'role' => 'Checks', 'order' => 4],
            ]));

        // selectModel is now called for EVERY agent (with a description hint as the
        // second arg), so use a catch-all that resolves by type.
        $selector = \Mockery::mock(ModelSelectorService::class);
        $selector->shouldReceive('selectModel')->andReturnUsing(
            fn (string $type, ?string $hint = null): string => $type === 'qa_verifier' ? 'phi3.5:mini' : 'todorov/bggpt'
        );

        $company = new \App\Models\Company([
            'name' => 'Test Company',
            'industry' => 'Marketing',
            'description' => 'Company description',
        ]);
        $flow = new \App\Models\Flow([
            'name' => 'Test Flow',
            'description' => 'Create weekly content with актуални новини.',
        ]);
        $flow->setRelation('company', $company);

        $agents = (new AgentGeneratorService(new GeneratorService($ollama), $selector))->generate($flow, $onProgress);

        $this->assertNotEmpty($agents);
        $this->assertContains('Генериране на агенти', $stages);
        $this->assertContains('Обработка на резултата', $stages);
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

    private function invokeEnsureQaVerifierLast(AgentGeneratorService $service, array $agents): array
    {
        $method = new \ReflectionMethod($service, 'ensureQaVerifierLast');
        $method->setAccessible(true);
        return $method->invoke($service, $agents);
    }

    private function invokeParseAgentJson(AgentGeneratorService $service, string $raw): array
    {
        $method = new \ReflectionMethod($service, 'parseAgentJson');
        $method->setAccessible(true);
        return $method->invoke($service, $raw);
    }

    private function invokeEnsureBgTextCorrectorBeforeQa(AgentGeneratorService $service, array $agents): array
    {
        $method = new \ReflectionMethod($service, 'ensureBgTextCorrectorBeforeQa');
        $method->setAccessible(true);
        return $method->invoke($service, $agents);
    }
}
