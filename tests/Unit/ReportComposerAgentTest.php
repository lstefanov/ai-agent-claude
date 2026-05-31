<?php

namespace Tests\Unit;

use App\Agents\ReportComposerAgent;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\OllamaService;
use Tests\TestCase;

class ReportComposerAgentTest extends TestCase
{
    public function test_composes_report_with_verbatim_tables_and_llm_narrative(): void
    {
        $priceTable = <<<'MARKDOWN'
| Конкурент | Услуга | Цена | Тип | Линк |
|-----------|--------|------|-----|------|
| Ability Spa | Месечна карта | 68.45 лв. | фитнес абонамент | abilityspa.com |
MARKDOWN;

        $ollama = \Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $systemPrompt, $userMessage, $options) use ($priceTable) {
                return $model === 'qwen2.5:14b'
                    && str_contains($systemPrompt, 'write only the narrative')
                    && ! str_contains($userMessage, $priceTable);
            })
            ->andReturn("### Анализ и препоръки\n- Позиционирайте месечната карта около 65-75 лв.");

        $agent = new Agent;
        $agent->name = 'Финален доклад';
        $agent->type = 'report_composer';
        $agent->model = 'qwen2.5:14b';
        $agent->output_language = 'bg';
        $agent->config = [
            'profile_source' => 'Профили',
            'table_source' => 'Цени',
            'analysis_source' => 'Анализ',
        ];

        $agentRun = new AgentRun;
        $agentRun->input = 'Compose final report.';

        $context = [
            'company_name' => 'Game Sport Center Русе',
            'company_description' => 'Групови тренировки',
            'Профили' => "### Профили\n#### Ability Spa\n- Technogym оборудване",
            'Цени' => $priceTable,
            'Анализ' => 'Ability Spa е benchmark с 68.45 лв. месечна карта.',
        ];

        $composer = new ReportComposerAgent($ollama);
        $result = $composer->run($agent, $agentRun, $context);

        $this->assertStringContainsString('# Конкурентен доклад — Game Sport Center Русе', $result);
        $this->assertStringContainsString($priceTable, $result);
        $this->assertStringContainsString('#### Ability Spa', $result);
        $this->assertStringContainsString('Позиционирайте месечната карта около 65-75 лв.', $result);
        $this->assertNull($composer->rawOutput());
    }
}
