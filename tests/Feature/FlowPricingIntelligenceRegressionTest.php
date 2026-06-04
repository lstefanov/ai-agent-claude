<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NodeRun;
use App\Services\GraphFlowExecutor;
use App\Support\PricingOutputMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlowPricingIntelligenceRegressionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ReportComposerAgent has a built-in quality_guard that retries when the
     * pricing table doesn't meet a minimum number of priced rows. The graph engine
     * must pass both the weak (first) and rich (retried) table through to the
     * composer and the final report must contain only the rich table.
     */
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
                ->push(['message' => ['content' => $weakTable]])    // extractor first try
                ->push(['message' => ['content' => $richTable]])    // extractor retry
                ->push(['message' => ['content' => 'Препоръка: позиционирайте месечната карта спрямо 89-95 лв.']]),
        ]);

        $company = Company::create([
            'name' => 'Game Sport Center Русе', 'description' => 'Групови тренировки',
            'industry' => 'Fitness', 'language' => 'bg',
        ]);

        $flow = $company->flows()->create([
            'name' => 'Pricing report', 'description' => 'Regression test', 'status' => 'active',
        ]);

        $flow->nodes()->create([
            'node_key' => 'E', 'name' => 'Екстрактор на данни', 'type' => 'analyzer',
            'model' => 'extractor-model', 'prompt_template' => 'Extract {{topic}}',
            'is_active' => true, 'output_role' => 'hidden',
            'config' => [
                'quality_guard' => [
                    'min_priced_rows' => 8,
                    'max_retries' => 1,
                ],
            ],
        ]);

        $flow->nodes()->create([
            'node_key' => 'R', 'name' => 'Финален доклад', 'type' => 'report_composer',
            'model' => 'report-model', 'prompt_template' => 'Compose final report',
            'is_active' => true, 'output_role' => 'body',
            'config' => ['table_source' => 'Екстрактор на данни'],
        ]);

        $flow->edges()->create(['from_node_key' => 'E', 'to_node_key' => 'R']);

        $flowRun = app(GraphFlowExecutor::class)->run($flow);
        $flowRun->refresh();

        $finalNodeRun = NodeRun::where('flow_run_id', $flowRun->id)
            ->where('node_key', 'R')->firstOrFail();

        $metrics = PricingOutputMetrics::fromOutput((string) $finalNodeRun->output);

        $this->assertStringContainsString('## 1. Ценово разузнаване', (string) $finalNodeRun->output);
        $this->assertSame(8, $metrics['priced_rows']);
        $this->assertStringContainsString('| Yoga Ruse | Месечна карта | 95 лв.', (string) $finalNodeRun->output);
        $this->assertStringNotContainsString('| Grabo | Оферти | н/д', (string) $finalNodeRun->output);
    }
}
