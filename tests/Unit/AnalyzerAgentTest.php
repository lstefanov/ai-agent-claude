<?php

namespace Tests\Unit;

use App\Agents\AnalyzerAgent;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\OllamaService;
use Tests\TestCase;

class AnalyzerAgentTest extends TestCase
{
    public function test_retries_pricing_extraction_when_output_has_too_few_priced_rows(): void
    {
        $weakOutput = <<<'MARKDOWN'
| Конкурент | Услуга | Цена | Тип | Линк |
|-----------|--------|------|-----|------|
| Grabo | Оферти | н/д | агрегатор | grabo.bg |
MARKDOWN;

        $richOutput = <<<'MARKDOWN'
| Конкурент | Услуга | Цена | Тип | Линк |
|-----------|--------|------|-----|------|
| V Gym | Еднократно посещение | 48.90 лв. | фитнес | vgym.bg |
| V Gym | Месечна карта | 273.82 лв. | фитнес | vgym.bg |
MARKDOWN;

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')
            ->once()
            ->withArgs(fn ($model, $systemPrompt, $userMessage) => ! str_contains($userMessage, 'QUALITY RETRY'))
            ->andReturn($weakOutput);
        $ollama->shouldReceive('chat')
            ->once()
            ->withArgs(fn ($model, $systemPrompt, $userMessage) => str_contains($userMessage, 'QUALITY RETRY')
                && str_contains($userMessage, 'at least 2 rows with numeric prices'))
            ->andReturn($richOutput);

        $agent = new Agent;
        $agent->name = 'Екстрактор на данни';
        $agent->role = 'Extract prices';
        $agent->model = 'qwen2.5:14b';
        $agent->output_language = 'bg';
        $agent->config = [
            'quality_guard' => [
                'min_priced_rows' => 2,
                'max_retries' => 1,
            ],
        ];

        $run = new AgentRun;
        $run->input = 'Extract competitor prices.';

        $result = (new AnalyzerAgent($ollama))->run($agent, $run, []);

        $this->assertSame($richOutput, $result);
    }
}
