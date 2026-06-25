<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        // 4 плана с фиксиран max_star_tier ∈ ModelLevel. `low` е универсалният под —
        // никой план не го „забранява". Звездите са презентационен слой над тези нива.
        $plans = [
            [
                'key' => 'starter', 'name' => 'Starter',
                'price_cents' => 2900, 'monthly_credits' => 1000,
                'max_star_tier' => 'medium',
                'features' => ['до medium ниво модели', '1 организация', 'админ зареждане на кредити'],
            ],
            [
                'key' => 'professional', 'name' => 'Professional',
                'price_cents' => 9900, 'monthly_credits' => 5000,
                'max_star_tier' => 'high',
                'features' => ['до high ниво модели', 'график на задачи', 'чат с членове'],
            ],
            [
                'key' => 'business', 'name' => 'Business',
                'price_cents' => 29900, 'monthly_credits' => 20000,
                'max_star_tier' => 'ultra',
                'features' => ['до ultra ниво модели', 'act през конектори', 'приоритетна опашка'],
            ],
            [
                'key' => 'enterprise', 'name' => 'Enterprise',
                'price_cents' => 99900, 'monthly_credits' => 100000,
                'max_star_tier' => 'god',
                'features' => ['до god ниво (флагмани)', 'без лимит организации', 'overage по договор'],
            ],
        ];

        foreach ($plans as $plan) {
            // updateOrCreate по уникалния key → seed-ът е идемпотентен.
            Plan::updateOrCreate(['key' => $plan['key']], $plan);
        }
    }
}
