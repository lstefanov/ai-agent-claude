<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Company;
use App\Models\Flow;
use Illuminate\Database\Seeder;

class UpgradeFlow4ToDeepResearcherSeeder extends Seeder
{
    public function run(): void
    {
        // ── Ensure company exists ──────────────────────────────────────
        $company = Company::firstOrCreate(
            ['id' => 1],
            [
                'name'        => 'Default Company',
                'industry'    => 'fitness',
                'description' => 'Default company for testing flows',
            ]
        );

        // ── Ensure Flow 4 exists ───────────────────────────────────────
        $flow = Flow::firstOrCreate(
            ['id' => 4],
            [
                'company_id'    => $company->id,
                'name'          => 'Конкуренция',
                'description'   => 'Analyze competitive pricing and market intelligence',
                'status'        => 'active',
                'is_archived'   => false,
                'topic'         => 'market_research',
            ]
        );

        // ── Ensure multi_researcher agent exists ───────────────────────
        $researcher = Agent::firstOrCreate(
            ['flow_id' => 4, 'type' => 'multi_researcher'],
            [
                'name'              => 'Изследовател на ценовите стратегии',
                'order'             => 1,
                'model'             => 'gemma2:9b',
                'is_active'         => true,
                'output_language'   => 'bg',
                'output_format'     => 'text',
                'role'              => 'Ти си изследовател на конкурентни цени. Твоята задача е да намериш КОНКРЕТНИ данни: КОНКУРЕНТ → УСЛУГА → ТОЧНА ЦЕНА → ЛИНК.',
                'system_prompt'     => 'Ти си експерт в търсене на ценови данни. Събираш конкретни, проверени цени от конкурентни услуги.',
                'prompt_template'   => 'Изследвай следната тема и предостави структуриран доклад с конкретни цени: {input}',
                'config'            => [
                    'search_queries_count' => 5,
                    'search_queries'       => [],
                ],
            ]
        );

        // ── Upgrade to deep_researcher ─────────────────────────────────
        $researcher->update([
            'type'   => 'deep_researcher',
            'config' => array_merge($researcher->config ?? [], [
                'scrape_pricing_pages' => true,
                'max_pages_to_scrape'  => 3,
            ]),
        ]);

        $this->command->info("Agent {$researcher->id} ({$researcher->name}) upgraded: multi_researcher → deep_researcher");
        $this->command->info('Config: scrape_pricing_pages=true, max_pages_to_scrape=3');
    }
}
