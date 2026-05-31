<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\Company;
use App\Services\FlowExecutorService;
use App\Support\PricingOutputMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlowPricingIntelligenceRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_final_report_keeps_rich_pricing_table_after_extractor_quality_retry(): void
    {
        $weakTable = <<<'MARKDOWN'
| Конкурент | Услуга | Цена | Тип | Линк |
|-----------|--------|------|-----|------|
| Grabo | Оферти | н/д | агрегатор | grabo.bg |
MARKDOWN;

        $richTable = <<<'MARKDOWN'
| Конкурент | Услуга | Цена | Тип | Линк |
|-----------|--------|------|-----|------|
| V Gym | Еднократно посещение | 48.90 лв. | фитнес | vgym.bg |
| V Gym | Месечна карта | 273.82 лв. | фитнес | vgym.bg |
| V Gym | Тримесечна карта | 684.54 лв. | фитнес | vgym.bg |
| Ability Spa | Еднократно посещение | 25 лв. | фитнес | abilityspa.com |
| Ability Spa | Месечна карта | 89 лв. | фитнес | abilityspa.com |
| Next Level | Карта 10 посещения | 120 лв. | групови | nextlevelclub.bg |
| Pilates Ruse | Групова тренировка | 18 лв. | пилатес | pilates-ruse.bg |
| Yoga Ruse | Месечна карта | 95 лв. | йога | yoga-ruse.bg |
MARKDOWN;

        Http::fake([
            'localhost:11434/api/chat' => Http::sequence()
                ->push(['message' => ['content' => $weakTable]])
                ->push(['message' => ['content' => $richTable]])
                ->push(['message' => ['content' => 'Препоръка: позиционирайте месечната карта спрямо 89-95 лв.']]),
        ]);

        $company = Company::create([
            'name' => 'Game Sport Center Русе',
            'description' => 'Групови тренировки',
            'industry' => 'Fitness',
            'language' => 'bg',
        ]);

        $flow = $company->flows()->create([
            'name' => 'Pricing report',
            'description' => 'Regression test',
            'status' => 'active',
        ]);

        $flow->agents()->create([
            'name' => 'Екстрактор на данни',
            'type' => 'analyzer',
            'role' => 'Extract prices',
            'prompt_template' => 'Extract {{topic}}',
            'model' => 'extractor-model',
            'order' => 1,
            'is_active' => true,
            'config' => [
                'quality_guard' => [
                    'min_priced_rows' => 8,
                    'max_retries' => 1,
                ],
            ],
        ]);

        $reportAgent = $flow->agents()->create([
            'name' => 'Финален доклад',
            'type' => 'report_composer',
            'role' => 'Compose report',
            'prompt_template' => 'Compose final report',
            'model' => 'report-model',
            'order' => 2,
            'is_active' => true,
            'config' => [
                'table_source' => 'Екстрактор на данни',
            ],
        ]);

        app(FlowExecutorService::class)->run($flow);

        $finalRun = AgentRun::where('agent_id', $reportAgent->id)->firstOrFail();
        $metrics = PricingOutputMetrics::fromOutput((string) $finalRun->output);

        $this->assertStringContainsString('## 1. Ценово разузнаване', (string) $finalRun->output);
        $this->assertSame(8, $metrics['priced_rows']);
        $this->assertStringContainsString('| Yoga Ruse | Месечна карта | 95 лв.', (string) $finalRun->output);
        $this->assertStringNotContainsString('| Grabo | Оферти | н/д', (string) $finalRun->output);
    }
}
