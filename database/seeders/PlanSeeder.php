<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        // Клиентските планове са подредени от trial към растящ работен капацитет.
        // Enterprise остава seed-нат за бъдеща/admin употреба, но не се показва в client UI.
        $plans = [
            [
                'key' => 'free', 'name' => 'Безплатен',
                'price_cents' => 0, 'monthly_credits' => (int) config('billing.plans.free', 100),
                'max_star_tier' => 'medium',
                'features' => ['100 кредита месечно', 'до medium ниво модели', 'първи flow и пробни задачи'],
            ],
            [
                'key' => 'starter', 'name' => 'Starter',
                'price_cents' => 2900, 'monthly_credits' => (int) config('billing.plans.starter', 1000),
                'max_star_tier' => 'medium',
                'features' => ['до medium ниво модели', 'редовна ръчна работа', 'повече flow генерации'],
            ],
            [
                'key' => 'professional', 'name' => 'Professional',
                'price_cents' => 9900, 'monthly_credits' => (int) config('billing.plans.professional', 5000),
                'max_star_tier' => 'high',
                'features' => ['до high ниво модели', 'активен AI екип', 'по-добър research и синтез'],
            ],
            [
                'key' => 'business', 'name' => 'Business',
                'price_cents' => 29900, 'monthly_credits' => (int) config('billing.plans.business', 20000),
                'max_star_tier' => 'ultra',
                'features' => ['до ultra ниво модели', 'ежедневна автономна работа', 'по-дълги workflows и конектори'],
            ],
            [
                'key' => 'enterprise', 'name' => 'Enterprise',
                'price_cents' => 99900, 'monthly_credits' => (int) config('billing.plans.enterprise', 100000),
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
