<?php

// Model Router — task-aware избор на cloud провайдър per агент.
// ModelRouterService профилира задачата на всеки агент по ФАСЕТИ (0–10 тежест)
// и я сравнява с capability матрицата по-долу (0–10 колко е силен провайдърът
// в този фасет). Скорингът добавя цена (по services.*.pricing), spread decay,
// гласа на планера и историческото представяне (node_runs.qa_score).
return [

    // smart = детерминистичен профил + LLM обогатяване (безплатният рутер
    // провайдър "оглежда" ролята/промпта на всеки агент); matrix = само
    // детерминистичният профил. Smart пада тихо към matrix при грешка.
    'mode' => env('MODEL_ROUTING', 'smart'),

    // Кой LLM профилира задачите в smart режим (OpenAI-compatible провайдър).
    'router_provider' => env('MODEL_ROUTER_PROVIDER', 'gemini'),
    'router_model' => env('MODEL_ROUTER_MODEL'), // празно → generator модела на провайдъра

    // ── Скоринг knobs ────────────────────────────────────────────────────
    // Цената (USD за примерен нод: ~6K in / ~3K out) се изважда от score-а,
    // умножена по 1000 × cost_penalty за нивото. На "low" безплатното почти
    // винаги печели; на "ultra" цената не тежи.
    'cost_penalty' => ['low' => 2.0, 'medium' => 1.0, 'high' => 0.4, 'ultra' => 0.0],

    // Мек анти-монопол: всеки следващ нод върху същия провайдър губи толкова
    // точки — пази free-tier квотите и разпределя натоварването.
    'spread_decay' => 5.0,

    // Бонус, когато планерът е посочил същия провайдър за този агент.
    'planner_vote_bonus' => 8.0,

    // Историческо учене: bonus = clamp(avg_qa(provider,type) − avg_qa(type), ±15) × weight
    // − fail_rate × fail_penalty. Изисква натрупани node_runs.qa_score.
    'history_weight' => 0.5,
    'history_fail_penalty' => 10.0,
    'history_days' => 30,

    // ── Capability матрица ──────────────────────────────────────────────
    // ctx = контекстен прозорец в токени (hard filter: est. input ≤ 75% от него).
    // Фасети: research (обработка на tool/уеб резултати), extraction
    // (структурирани данни от суров текст), analysis (reasoning), synthesis
    // (fan-in, дълга форма), json_strict, bg_language, long_context, creative,
    // speed. Premium провайдърите участват само в openai/anthropic слотовете
    // на нивата high/ultra.
    'providers' => [
        'gemini' => [
            'ctx' => 1_000_000,
            'facets' => [
                'research' => 8, 'extraction' => 9, 'analysis' => 6,
                'synthesis' => 5, 'json_strict' => 9, 'bg_language' => 5,
                'long_context' => 8, 'creative' => 6, 'speed' => 9,
            ],
        ],
        'deepseek' => [
            'ctx' => 128_000,
            'facets' => [
                'research' => 7, 'extraction' => 8, 'analysis' => 9,
                'synthesis' => 8, 'json_strict' => 7, 'bg_language' => 4,
                'long_context' => 5, 'creative' => 7, 'speed' => 6,
            ],
        ],
        'qwen' => [
            'ctx' => 1_000_000,
            'facets' => [
                'research' => 7, 'extraction' => 7, 'analysis' => 7,
                'synthesis' => 7, 'json_strict' => 8, 'bg_language' => 8,
                'long_context' => 9, 'creative' => 6, 'speed' => 8,
            ],
        ],
        'xai' => [
            'ctx' => 1_000_000,
            'facets' => [
                'research' => 8, 'extraction' => 7, 'analysis' => 8,
                'synthesis' => 9, 'json_strict' => 7, 'bg_language' => 6,
                'long_context' => 9, 'creative' => 8, 'speed' => 10,
            ],
        ],
        'openai' => [
            'ctx' => 128_000,
            'facets' => [
                'research' => 8, 'extraction' => 8, 'analysis' => 8,
                'synthesis' => 8, 'json_strict' => 9, 'bg_language' => 7,
                'long_context' => 6, 'creative' => 8, 'speed' => 8,
            ],
        ],
        'anthropic' => [
            'ctx' => 200_000,
            'facets' => [
                'research' => 8, 'extraction' => 8, 'analysis' => 9,
                'synthesis' => 9, 'json_strict' => 8, 'bg_language' => 7,
                'long_context' => 7, 'creative' => 9, 'speed' => 7,
            ],
        ],
    ],
];
