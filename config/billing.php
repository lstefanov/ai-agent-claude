<?php

return [
    // base(star_tier) множител в кредити за пускане; индексът = ModelLevel.
    // ВСеки лост е env-конфигурируем, за да може собственикът да мени тарифите без код.
    'star_multipliers' => [
        'low' => (int) env('BILLING_STAR_LOW', 1),
        'medium' => (int) env('BILLING_STAR_MEDIUM', 3),
        'high' => (int) env('BILLING_STAR_HIGH', 6),
        'ultra' => (int) env('BILLING_STAR_ULTRA', 12),
        'god' => (int) env('BILLING_STAR_GOD', 25),
    ],
    // Flat кредитни цени за не-LLM инструменти (BillableUnit / actualFor flat mapping).
    'flat_costs' => [
        'brave_search' => (int) env('BILLING_FLAT_BRAVE', 1),
        'places' => (int) env('BILLING_FLAT_PLACES', 1),
        'perplexity' => (int) env('BILLING_FLAT_PERPLEXITY', 1),
        'ocr_page' => (int) env('BILLING_FLAT_OCR_PAGE', 1),
        'avatar' => (int) env('BILLING_FLAT_AVATAR', 5),       // един ComfyUI портрет
        'embedding' => (int) env('BILLING_FLAT_EMBEDDING', 0), // локален bge-m3 ≈ безплатен
    ],
    'credit_markup' => (float) env('BILLING_CREDIT_MARKUP', 3.0),   // надценка над реален inference (fallback за непознат flat)
    'work_per_token' => (float) env('BILLING_WORK_PER_KTOKEN', 1.0), // кредити / 1k predict tokens
    'overage_enabled' => (bool) env('BILLING_OVERAGE', true),

    // Изчислителни константи (бяха твърдо кодирани в BillableUnit/CreditMeterService).
    'token_divisor' => (int) env('BILLING_TOKEN_DIVISOR', 1000),
    'min_reserve_credits' => (int) env('BILLING_MIN_RESERVE_CREDITS', 1),
    'min_estimate_credits' => (int) env('BILLING_MIN_ESTIMATE_CREDITS', 1),

    // Реална вътрешна USD цена за локален ComfyUI портрет (0 = безплатно).
    'avatar_cost_usd' => (float) env('COMFYUI_AVATAR_COST_USD', 0),
    // Версия на тарифата — пази се в credit_reservations.billing_meta за обяснимост.
    'pricing_version' => (string) env('BILLING_PRICING_VERSION', '2026-06'),

    // Груби оценки на predict tokens (в хиляди) ПРЕДИ старт — резервацията е
    // консервативна; settle винаги реконсилира срещу реалните токени (§0.5.2).
    'estimate_ktokens' => [
        'task_run' => (int) env('BILLING_EST_TASK_RUN', 10),
        'generation' => (int) env('BILLING_EST_GENERATION', 12),
        'org_planning' => (int) env('BILLING_EST_ORG_PLANNING', 15),
        'interview' => (int) env('BILLING_EST_INTERVIEW', 3),
        'research' => (int) env('BILLING_EST_RESEARCH', 8),
        'member_chat' => (int) env('BILLING_EST_MEMBER_CHAT', 2),
        'director_tick' => (int) env('BILLING_EST_DIRECTOR_TICK', 6),
        'org_digest' => (int) env('BILLING_EST_ORG_DIGEST', 4),
        'text_assist' => (int) env('BILLING_EST_TEXT_ASSIST', 1),
        'avatar' => (int) env('BILLING_EST_AVATAR', 1),
        'assistant' => (int) env('BILLING_EST_ASSISTANT', 3),       // Builder Copilot
        'client_wizard' => (int) env('BILLING_EST_CLIENT_WIZARD', 3),
        'knowledge_chat' => (int) env('BILLING_EST_KNOWLEDGE_CHAT', 2),
        'knowledge_ingest' => (int) env('BILLING_EST_KNOWLEDGE_INGEST', 8),
    ],

    // Ниво на таксуване по услуга (когато резервацията няма явно подадено model_level).
    // org контекстите наследяват ORG_MANAGER_LEVEL през levelForReservation().
    'context_levels' => [
        'text_assist' => env('BILLING_LEVEL_TEXT_ASSIST', 'medium'),
        'avatar' => env('BILLING_LEVEL_AVATAR', 'medium'),
        'assistant' => env('BILLING_LEVEL_ASSISTANT', 'medium'),
        'client_wizard' => env('BILLING_LEVEL_CLIENT_WIZARD', 'medium'),
        'knowledge_chat' => env('BILLING_LEVEL_KNOWLEDGE_CHAT', 'medium'),
        'knowledge_ingest' => env('BILLING_LEVEL_KNOWLEDGE_INGEST', 'medium'),
    ],

    // Hard-gate политика по (context_type, origin): 'hard' блокира при липса на кредити,
    // 'soft' = best-effort (продължава, атрибутира, без резервация). Call site-овете подават
    // explicit hardGate; този конфиг дава default по двойката (BillingGatePolicy).
    'gate' => [
        'autonomous' => env('BILLING_GATE_AUTONOMOUS', 'hard'),         // всеки origin=autonomous
        'task_run' => env('BILLING_GATE_TASK_RUN', 'hard'),
        'generation_manual' => env('BILLING_GATE_GENERATION_MANUAL', 'soft'),
        'generation_org' => env('BILLING_GATE_GENERATION_ORG', 'hard'),
        'text_assist' => env('BILLING_GATE_TEXT_ASSIST', 'soft'),
        'avatar' => env('BILLING_GATE_AVATAR', 'soft'),
        'assistant' => env('BILLING_GATE_COPILOT', 'soft'),
        'client_wizard' => env('BILLING_GATE_CLIENT_WIZARD', 'soft'),
        'research' => env('BILLING_GATE_RESEARCH', 'soft'),
        'interview' => env('BILLING_GATE_INTERVIEW', 'soft'),
        'knowledge_chat' => env('BILLING_GATE_KNOWLEDGE_CHAT', 'soft'),
        'knowledge_ingest' => env('BILLING_GATE_KNOWLEDGE_INGEST', 'soft'),
    ],

    // Месечни кредити по план (PlanSeeder ги чете оттук).
    'plans' => [
        'free' => (int) env('BILLING_PLAN_FREE_MONTHLY', 100),
        'starter' => (int) env('BILLING_PLAN_STARTER_MONTHLY', 1000),
        'professional' => (int) env('BILLING_PLAN_PRO_MONTHLY', 5000),
        'business' => (int) env('BILLING_PLAN_BUSINESS_MONTHLY', 20000),
        'enterprise' => (int) env('BILLING_PLAN_ENTERPRISE_MONTHLY', 100000),
    ],

    // Stripe — ПО-КЪСНА фаза (Фаза 6); зад PaymentProvider. Сега зареждането е
    // админ-симулирано (AdminSimulatedPaymentProvider), тези остават празни.
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],
];
