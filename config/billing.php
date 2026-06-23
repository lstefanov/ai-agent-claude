<?php

return [
    // base(star_tier) множител в кредити за пускане; индексът = ModelLevel.
    'star_multipliers' => [
        'low' => 1, 'medium' => 3, 'high' => 6, 'ultra' => 12, 'god' => 25,
    ],
    // Flat кредитни цени за не-LLM инструменти (BillableUnit, §0.5.1) — тези
    // услуги подават явна cost_usd, не минават през token формулата.
    'flat_costs' => [
        'brave_search' => 1, 'places' => 1, 'ocr_page' => 1,
        'avatar' => 5,       // един ComfyUI портрет
        'embedding' => 0,    // локален bge-m3 ≈ безплатен
    ],
    'credit_markup' => (float) env('BILLING_CREDIT_MARKUP', 3.0),  // надценка над реален inference
    'work_per_token' => (float) env('BILLING_WORK_PER_KTOKEN', 1.0), // кредити / 1k predict tokens
    'overage_enabled' => (bool) env('BILLING_OVERAGE', true),
    // Stripe — ПО-КЪСНА фаза (Фаза 6); зад PaymentProvider. Сега зареждането е
    // админ-симулирано (AdminSimulatedPaymentProvider), тези остават празни.
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],
];
