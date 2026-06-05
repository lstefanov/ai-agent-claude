<?php

namespace Tests\Unit;

use App\Agents\ReviewAnalyzerAgent;
use App\Agents\Tools\AgentTool;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\OllamaService;
use Mockery;
use Tests\TestCase;

class ReviewAnalyzerGooglePlacesTest extends TestCase
{
    public function test_google_places_reviews_are_fed_into_the_analysis(): void
    {
        $capturedSystem = null;
        $ollama = Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')->andReturnUsing(function (...$args) use (&$capturedSystem) {
            // chat(model, systemPrompt, userMessage, options, ...)
            $capturedSystem = $args[1] ?? '';

            return 'Анализ на ревютата.';
        });

        // Stub the google_reviews tool to return Places-formatted data.
        $googleReviews = new class implements AgentTool
        {
            public function name(): string
            {
                return 'google_reviews';
            }

            public function execute(array $params): string
            {
                return "Бизнес (Google): PrimeLaser\nGoogle оценка: 4.9 / 5 (117 ревюта)\nРевю 1 — Иван (5★ преди седмица): Страхотно!";
            }
        };

        $agent = new ReviewAnalyzerAgent($ollama, [$googleReviews]);
        $model = new Agent(['type' => 'review_analyzer', 'model' => 'mistral-nemo', 'system_prompt' => 'Ти анализираш ревюта.']);
        $run   = new AgentRun(['input' => 'Анализирай ревютата за primelaser.bg']);

        $output = $agent->run($model, $run, ['target_url' => 'https://primelaser.bg', 'url' => 'https://primelaser.bg']);

        $this->assertSame('Анализ на ревютата.', $output);
        $this->assertNotNull($capturedSystem);
        $this->assertStringContainsString('Places API', $capturedSystem);
        $this->assertStringContainsString('4.9', $capturedSystem);
        $this->assertStringContainsString('117', $capturedSystem);
    }
}
