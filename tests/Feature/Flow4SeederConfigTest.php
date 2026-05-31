<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Company;
use App\Models\Flow;
use Database\Seeders\OptimiseFlow4ForGameSportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Flow4SeederConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_flow_four_seeded_extraction_agents_use_deterministic_pricing_configs(): void
    {
        $company = Company::create([
            'name' => 'Game Sport Center',
            'description' => 'Sports center',
            'industry' => 'Fitness',
            'language' => 'bg',
        ]);

        Flow::forceCreate([
            'id' => 4,
            'company_id' => $company->id,
            'name' => 'Конкуренция',
            'description' => 'Competition',
            'status' => 'active',
        ]);

        foreach ([
            1 => ['name' => 'Изследовател на ценовите стратегии', 'type' => 'deep_researcher', 'order' => 1],
            2 => ['name' => 'Екстрактор на данни', 'type' => 'analyzer', 'order' => 2],
            3 => ['name' => 'Анализатор на ценови тенденции', 'type' => 'analyzer', 'order' => 3],
            4 => ['name' => 'Финален доклад', 'type' => 'analyzer', 'order' => 4],
            5 => ['name' => 'Контролер на качество', 'type' => 'qa_verifier', 'order' => 5],
            7 => ['name' => 'Скрапер на конкурентни сайтове', 'type' => 'competitor_profiler', 'order' => 0],
        ] as $id => $attributes) {
            Agent::forceCreate([
                'id' => $id,
                'flow_id' => 4,
                'model' => 'old-model',
                'is_active' => true,
                'output_language' => 'bg',
                'config' => [],
                ...$attributes,
            ]);
        }

        $this->seed(OptimiseFlow4ForGameSportSeeder::class);

        $scraperConfig = Agent::findOrFail(7)->config;
        $extractorConfig = Agent::findOrFail(2)->config;
        $reportAgent = Agent::findOrFail(4);

        $this->assertSame(0, $scraperConfig['temperature']);
        $this->assertSame(-1, $scraperConfig['num_predict']);
        $this->assertSame(42, $scraperConfig['seed']);
        $this->assertSame(0, $extractorConfig['temperature']);
        $this->assertSame(4000, $extractorConfig['num_predict']);
        $this->assertSame(42, $extractorConfig['seed']);
        $this->assertSame(8, $extractorConfig['quality_guard']['min_priced_rows']);
        $this->assertSame(1, $extractorConfig['quality_guard']['max_retries']);
        $this->assertSame('report_composer', $reportAgent->type);
    }
}
