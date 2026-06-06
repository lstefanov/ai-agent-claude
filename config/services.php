<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'ollama' => [
        'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        'fallback_model' => env('OLLAMA_DEFAULT_FALLBACK', 'llama3.1:8b'),
        // Local model for FREE planning (GENERATOR_PROVIDER=ollama). Needs solid
        // structured-JSON + reasoning: qwen2.5:14b е най-добрият от каталога.
        'planner_model' => env('OLLAMA_PLANNER_MODEL', 'qwen2.5:14b'),
        // Auto-pull missing agent models before a flow run. Disabled in tests.
        'auto_pull' => env('OLLAMA_AUTO_PULL', true),
        // Strong model used by FinalComposerService to assemble the final result
        // from the individual agent outputs (posts + titles + hashtags).
        'composer_model' => env('OLLAMA_COMPOSER_MODEL', 'gemma3:12b'),
    ],

    // Which LLM provider plans the agents for a Flow: openai | anthropic | ollama.
    // Cloud (openai/anthropic) дава най-добрите планове; ollama е БЕЗПЛАТНОТО
    // локално планиране (structured outputs на OLLAMA_PLANNER_MODEL) — същият
    // тристепенен planner, но качеството зависи от локалния модел.
    'generator' => [
        'provider' => env('GENERATOR_PROVIDER', 'openai'),
    ],

    // FlowPlannerService tuning (the "agent that creates agents").
    'planner' => [
        // Phase C: a second LLM pass that reviews + repairs the generated plan.
        'critique' => env('PLANNER_CRITIQUE', true),
        // Hard cap on how many agents in one plan may run on a PAID provider
        // (openai/* + anthropic/* combined) at runtime.
        'max_paid_agents' => env('PLANNER_MAX_PAID_AGENTS', 2),
        // Фаза 2: proven plans injected as few-shot examples at design time.
        'few_shots' => env('PLANNER_FEW_SHOTS', 2),
        // Фаза 3: revise failing agents mid-run (QA fail / degenerate output).
        'adaptive' => env('PLANNER_ADAPTIVE', true),
        // Фаза 3: paid provider used when a revision escalates a failing step
        // away from a weak local model: openai | anthropic.
        'escalation_provider' => env('PLANNER_ESCALATION_PROVIDER', 'openai'),
        // Plan-library retrieval switches to embedding cosine similarity once
        // the proven entries reach this count (below it: structural scoring).
        'vector_threshold' => env('PLANNER_VECTOR_THRESHOLD', 100),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_GENERATOR_MODEL', 'claude-sonnet-4-6'),
        // Default model for agents the planner pins to Anthropic at runtime
        // (their node model becomes "anthropic/<runtime_model>").
        'runtime_model' => env('ANTHROPIC_RUNTIME_MODEL', 'claude-haiku-4-5'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        // USD per 1M tokens (in = prompt, out = completion) — for cost tracking.
        'pricing' => [
            'claude-sonnet-4-6' => ['in' => 3.00, 'out' => 15.00],
            'claude-haiku-4-5' => ['in' => 1.00, 'out' => 5.00],
        ],
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_GENERATOR_MODEL', 'gpt-4o'),
        // Default model for agents the planner pins to OpenAI at runtime
        // (their node model becomes "openai/<runtime_model>").
        'runtime_model' => env('OPENAI_RUNTIME_MODEL', 'gpt-4o-mini'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
        // Embeddings model for plan-library vector retrieval.
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        // USD per 1M tokens (in = prompt, out = completion) — for cost tracking.
        // Prefix match applies: "gpt-4o-2024-11-20" picks the "gpt-4o" row.
        'pricing' => [
            'gpt-4o-mini' => ['in' => 0.15, 'out' => 0.60],
            'gpt-4o' => ['in' => 2.50, 'out' => 10.00],
            'text-embedding-3-small' => ['in' => 0.02, 'out' => 0],
        ],
    ],

    'brave' => [
        'api_key' => env('BRAVE_SEARCH_API_KEY'),
        'results_count' => env('BRAVE_RESULTS_COUNT', 10),
    ],

    'google_places' => [
        // Google Places API (New) key — used for business reviews/rating.
        // Enable "Places API (New)" in Google Cloud Console for the key.
        'api_key' => env('GOOGLE_PLACES_API_KEY'),
    ],

    'comfyui' => [
        'url' => env('COMFYUI_URL', 'http://localhost:8188'),
        'enabled' => env('COMFYUI_ENABLED', true),
        'checkpoint' => env('COMFYUI_CHECKPOINT', 'sd_xl_base_1.0.safetensors'),
        'negative_prompt' => env('COMFYUI_NEGATIVE_PROMPT', 'ugly, deformed, noisy, blurry, distorted, low quality, watermark, text, signature'),
    ],

    'crawl' => [
        'url' => env('CRAWL_SERVICE_URL', 'http://localhost:8189'),
        'enabled' => env('CRAWL_SERVICE_ENABLED', true),
        'timeout' => env('CRAWL_SERVICE_TIMEOUT', 35),
        'max_pages' => env('CRAWL_MAX_PAGES', 20),
    ],

];
