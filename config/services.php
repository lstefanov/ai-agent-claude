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
        // structured-JSON + reasoning: qwen3:14b е най-добрият от каталога
        // (на 8 GB VRAM ползвай qwen3:8b — 14b прелива към CPU).
        'planner_model' => env('OLLAMA_PLANNER_MODEL', 'qwen3:14b'),
        // Auto-pull missing agent models before a flow run. Disabled in tests.
        'auto_pull' => env('OLLAMA_AUTO_PULL', true),
        // Колко ЛОКАЛНИ нода могат да генерират едновременно (глобално, за
        // всички run-ове — те делят един GPU). 1 при 8–16 GB VRAM; вдигни при
        // по-голяма карта. Cloud нодовете (openai/*, anthropic/*) не се броят.
        'max_concurrent' => env('OLLAMA_MAX_CONCURRENT', 1),
        // Контекст за локалния planner (design промптът лесно надхвърля 8K токена).
        'planner_num_ctx' => env('OLLAMA_PLANNER_NUM_CTX', 16384),
        // Thinking режим на локалния planner. По подразбиране (null) се изключва
        // автоматично САМО за thinking фамилии (qwen3/deepseek-r1) — Ollama
        // връща грешка, ако "think" се прати на модел без тази способност.
        'planner_think' => env('OLLAMA_PLANNER_THINK'),
        // Strong model used by FinalComposerService to assemble the final result
        // from the individual agent outputs (posts + titles + hashtags).
        'composer_model' => env('OLLAMA_COMPOSER_MODEL', 'gemma4:12b'),
    ],

    // Which LLM provider plans the agents for a Flow: openai | anthropic | ollama.
    // Cloud (openai/anthropic) дава най-добрите планове; ollama е БЕЗПЛАТНОТО
    // локално планиране (structured outputs на OLLAMA_PLANNER_MODEL) — същият
    // тристепенен planner, но качеството зависи от локалния модел.
    'generator' => [
        'provider' => env('GENERATOR_PROVIDER', 'openai'),
    ],

    // Provider + model for the lightweight AI-assist buttons ("Подобри с AI",
    // "Генерирай с AI"). Independent from GENERATOR_PROVIDER. Defaults to local
    // Ollama with a Bulgarian-tuned model.
    // provider: openai | anthropic | deepseek | gemini | xai | qwen | ollama
    'assist' => [
        'provider' => env('ASSIST_PROVIDER', 'ollama'),
        'ollama_model' => env('ASSIST_OLLAMA_MODEL', 'todorov/bggpt:Gemma-3-12B-IT-Q5_K_M'),
        'openai_model' => env('ASSIST_OPENAI_MODEL', 'gpt-4o-mini'),
        'anthropic_model' => env('ASSIST_ANTHROPIC_MODEL', 'claude-haiku-4-5'),
        'deepseek_model' => env('ASSIST_DEEPSEEK_MODEL', 'deepseek-v4-flash'),
        'gemini_model' => env('ASSIST_GEMINI_MODEL', 'gemini-3.1-flash-lite'),
        'xai_model' => env('ASSIST_XAI_MODEL', 'grok-4.1-fast'),
        'qwen_model' => env('ASSIST_QWEN_MODEL', 'qwen3.5-flash'),
    ],

    // Builder Copilot — чат асистентът в графовия builder (agentic tool
    // calling, само cloud провайдъри). provider празно → planner провайдъра,
    // ако е cloud, иначе openai; model празно → generator модела на провайдъра.
    'builder_assistant' => [
        'provider' => env('BUILDER_ASSISTANT_PROVIDER'),
        'model' => env('BUILDER_ASSISTANT_MODEL'),
        'max_steps' => (int) env('BUILDER_ASSISTANT_MAX_STEPS', 8),
        'history_limit' => 20,
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
        // away from a weak local model: openai | anthropic | deepseek | xai | qwen.
        'escalation_provider' => env('PLANNER_ESCALATION_PROVIDER', 'openai'),
        // Plan-library retrieval switches to embedding cosine similarity once
        // the proven entries reach this count (below it: structural scoring).
        'vector_threshold' => env('PLANNER_VECTOR_THRESHOLD', 100),
        // ХИБРИДНО ПЛАНИРАНЕ: per-phase provider/model override. Скъп модел
        // само за дизайна (най-тежката фаза), евтин/безплатен за останалите.
        // Непопълнена фаза → GENERATOR_PROVIDER + default модела на провайдъра.
        // provider: openai | anthropic | deepseek | gemini | xai | qwen | ollama.
        'phases' => [
            'intent_analysis' => [
                'provider' => env('PLANNER_INTENT_PROVIDER'),
                'model' => env('PLANNER_INTENT_MODEL'),
            ],
            'pipeline_design' => [
                'provider' => env('PLANNER_DESIGN_PROVIDER'),
                'model' => env('PLANNER_DESIGN_MODEL'),
            ],
            'plan_critique' => [
                'provider' => env('PLANNER_CRITIQUE_PROVIDER'),
                'model' => env('PLANNER_CRITIQUE_MODEL'),
            ],
            'agent_revision' => [
                'provider' => env('PLANNER_REVISION_PROVIDER'),
                'model' => env('PLANNER_REVISION_MODEL'),
            ],
        ],
        // Именувани хибридни комбинации за A/B: flows:plan-ab {id} --variant=hybrid
        // Фази: intent | design | critique | revision; стойност: provider[:model].
        'ab_presets' => [
            // Безплатни леки фази (изисква GEMINI_API_KEY).
            'hybrid' => [
                'design' => 'anthropic',
                'intent' => 'gemini',
                'critique' => 'gemini',
                'revision' => 'openai',
            ],
            // Работи само с OpenAI + Anthropic ключове.
            'hybrid-mini' => [
                'design' => 'anthropic',
                'intent' => 'openai:gpt-4o-mini',
                'critique' => 'openai:gpt-4o-mini',
                'revision' => 'openai:gpt-4o-mini',
            ],
        ],
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
        // stars (1–3) + desc хранят UI метаданни за model dropdown-ите
        // (planner качество) — LlmUsage чете само in/out.
        'pricing' => [
            'claude-sonnet-4-6' => ['in' => 3.00, 'out' => 15.00, 'stars' => 3, 'desc' => 'Топ качество на дизайна — еталонът за планиране'],
            'claude-haiku-4-5' => ['in' => 1.00, 'out' => 5.00, 'stars' => 2, 'desc' => 'По-евтин и бърз — добър за леките фази'],
        ],
    ],

    // OpenAI-compatible providers (OpenAI, DeepSeek, Gemini) share one client
    // (OpenAiChatService::for). base_url INCLUDES the version path; the client
    // appends only /chat/completions (and /embeddings).
    // structured_output: json_schema (native Structured Outputs) | json_object
    // (JSON mode — schema travels in the prompt, required keys are validated).
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_GENERATOR_MODEL', 'gpt-4o'),
        // Default model for agents the planner pins to OpenAI at runtime
        // (their node model becomes "openai/<runtime_model>").
        'runtime_model' => env('OPENAI_RUNTIME_MODEL', 'gpt-4o-mini'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'structured_output' => 'json_schema',
        'max_tokens_param' => 'max_completion_tokens',
        // Embeddings model for plan-library vector retrieval.
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        // USD per 1M tokens (in = prompt, out = completion) — for cost tracking.
        // Prefix match applies: "gpt-4o-2024-11-20" picks the "gpt-4o" row.
        'pricing' => [
            'gpt-4o-mini' => ['in' => 0.15, 'out' => 0.60, 'stars' => 2, 'desc' => 'Евтин и бърз — идеален за леките фази'],
            'gpt-4o' => ['in' => 2.50, 'out' => 10.00, 'stars' => 3, 'desc' => 'Флагман — най-силният OpenAI дизайн'],
            'text-embedding-3-small' => ['in' => 0.02, 'out' => 0],
        ],
    ],

    // DeepSeek V4 — планер + runtime провайдър ("deepseek/<model>" pin-ва
    // възел на DeepSeek API). Почти безплатен: пълна генерация с V4-Pro е
    // ~$0.01. Няма native json_schema → json_object режим (схемата пътува в
    // промпта). ВНИМАНИЕ: старите имена deepseek-chat/deepseek-reasoner са
    // оттеглени окончателно на 2026-07-24 — ползвай deepseek-v4-pro/-flash.
    'deepseek' => [
        'api_key' => env('DEEPSEEK_API_KEY'),
        // Planner/дизайн: V4-Pro (1.6T MoE, reasoning-heavy) — най-силният.
        'model' => env('DEEPSEEK_GENERATOR_MODEL', 'deepseek-v4-pro'),
        // Runtime възли (planner pin "deepseek/<runtime_model>"): V4-Flash —
        // бърз и евтин за масови стъпки.
        'runtime_model' => env('DEEPSEEK_RUNTIME_MODEL', 'deepseek-v4-flash'),
        'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
        'structured_output' => 'json_object',
        'max_tokens_param' => 'max_tokens',
        // V4 приема до 384K output — кламп само като предпазител срещу
        // безумни num_predict стойности.
        'max_output_cap' => 65536,
        'pricing' => [
            'deepseek-v4-pro' => ['in' => 0.435, 'out' => 0.87, 'stars' => 3, 'desc' => 'Reasoning флагман — почти безплатен'],
            'deepseek-v4-flash' => ['in' => 0.14, 'out' => 0.28, 'stars' => 2, 'desc' => 'Бърз и евтин — масови/леки задачи'],
        ],
    ],

    // Google Gemini — PLANNER-only provider през OpenAI-съвместимия endpoint.
    // Free tier: ~1500 заявки/ден на flash-lite → планирането е реално безплатно.
    // Compat слоят приема json_schema, но игнорира strict; ако дадена схема
    // бъде отхвърлена — GEMINI_STRUCTURED_OUTPUT=json_object е escape hatch.
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        // flash-lite: най-голям free-tier капацитет, без thinking преамбюл.
        // Текстов "gemini-3.1-flash" НЯМА (3.1 flash вариантите са live/image/
        // tts) — текстовите Flash модели са gemini-3.5-flash и по-старият
        // gemini-3-flash-preview. Thinking преамбюлът на 3.5-flash изгаря
        // num_predict бюджета — внимавай при structured планиране.
        'model' => env('GEMINI_GENERATOR_MODEL', 'gemini-3.1-flash-lite'),
        // Runtime възли ("gemini/<model>" pin): flash-lite — free tier, без
        // thinking преамбюл, който да изгаря num_predict бюджета.
        'runtime_model' => env('GEMINI_RUNTIME_MODEL', 'gemini-3.1-flash-lite'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta/openai'),
        'structured_output' => env('GEMINI_STRUCTURED_OUTPUT', 'json_schema'),
        'max_tokens_param' => 'max_tokens',
        'pricing' => [
            // FREE tier модели → $0 (платен tier: flash-lite $0.25/$1.50,
            // 3.5-flash $1.50/$9.00, 3-flash-preview $0.50/$3.00).
            'gemini-3.1-flash-lite' => ['in' => 0.0, 'out' => 0.0, 'stars' => 2, 'desc' => 'Free tier с най-голям дневен капацитет'],
            'gemini-3.5-flash' => ['in' => 0.0, 'out' => 0.0, 'stars' => 2, 'desc' => 'Free tier — внимание: thinking преамбюл'],
            'gemini-3-flash-preview' => ['in' => 0.0, 'out' => 0.0, 'stars' => 1, 'desc' => 'По-стар preview — само като резерва'],
            // Pro е САМО платен (без free tier; изисква billing в AI Studio) —
            // реални цени за ≤200K контекст.
            'gemini-3.1-pro-preview' => ['in' => 2.00, 'out' => 12.00, 'stars' => 3, 'desc' => 'Най-силният Gemini — само платен tier'],
            // Catch-all prefix за неизброени gemini-* (free tier).
            'gemini' => ['in' => 0.0, 'out' => 0.0],
        ],
    ],

    // xAI Grok — планер + runtime провайдър ("xai/<model>" pin-ва възел на
    // xAI API). OpenAI-съвместим endpoint с native Structured Outputs.
    // Топ-3 мултиезично качество (вкл. български). Безплатни кредити: $25
    // при регистрация + до $150/мес. през data-sharing програмата (console.x.ai).
    'xai' => [
        'api_key' => env('XAI_API_KEY'),
        // Дизайн фазата: grok-4.3 е актуалният флагман (1M контекст).
        'model' => env('XAI_GENERATOR_MODEL', 'grok-4.3'),
        // Runtime възли (planner pin "xai/<runtime_model>"): grok-4.1-fast —
        // 2M контекст, почти без пари. Prefix match покрива и
        // -reasoning/-non-reasoning вариантите.
        'runtime_model' => env('XAI_RUNTIME_MODEL', 'grok-4.1-fast'),
        'base_url' => env('XAI_BASE_URL', 'https://api.x.ai/v1'),
        'structured_output' => env('XAI_STRUCTURED_OUTPUT', 'json_schema'),
        'max_tokens_param' => 'max_tokens',
        'pricing' => [
            'grok-4.3' => ['in' => 1.25, 'out' => 2.50, 'stars' => 3, 'desc' => 'Флагман — топ мултиезичност, 1M контекст'],
            'grok-4.1-fast' => ['in' => 0.20, 'out' => 0.50, 'stars' => 2, 'desc' => 'Почти безплатен — 2M контекст'],
        ],
    ],

    // Alibaba Qwen (Model Studio international, Singapore) — планер + runtime
    // ("qwen/<model>"). Изрична поддръжка на български (119 езика). Compat
    // endpoint-ът дава json_object (схемата пътува в промпта, като deepseek).
    // Free tier: 1M input + 1M output токена за нови акаунти. Цените са tiered
    // по input размер — таблицата държи базовия tier (< 128K входни токени).
    'qwen' => [
        'api_key' => env('QWEN_API_KEY'),
        // Дизайн фазата: qwen3.7-plus — multimodal флагман, 1M контекст.
        // Същата цена като qwen3.5-plus ($0.40/M in) но по-евтин изход ($1.60 vs $2.40).
        'model' => env('QWEN_GENERATOR_MODEL', 'qwen3.7-plus'),
        // Runtime възли (planner pin "qwen/<runtime_model>"): qwen3.6-flash —
        // cost-optimized vision-language, 1M контекст ($0.25/$1.50).
        // qwen3.5-flash ($0.10/$0.40) остава в pricing таблицата като ultra-cheap резерва.
        'runtime_model' => env('QWEN_RUNTIME_MODEL', 'qwen3.6-flash'),
        'base_url' => env('QWEN_BASE_URL', 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1'),
        'structured_output' => env('QWEN_STRUCTURED_OUTPUT', 'json_object'),
        'max_tokens_param' => 'max_tokens',
        'max_output_cap' => 32768,
        'pricing' => [
            'qwen3.7-plus' => ['in' => 0.40, 'out' => 1.60, 'stars' => 3, 'desc' => 'Флагман — multimodal, 1M контекст'],
            'qwen3.7-max' => ['in' => 2.50, 'out' => 7.50, 'stars' => 3, 'desc' => 'Reasoning агент флагман — text-only, 1M контекст'],
            'qwen3.6-flash' => ['in' => 0.25, 'out' => 1.50, 'stars' => 2, 'desc' => 'Бърз и евтин — vision-language, 1M контекст'],
            'qwen3.5-flash' => ['in' => 0.10, 'out' => 0.40, 'stars' => 1, 'desc' => 'Най-евтиният — ultra-cheap лека фаза или runtime'],
        ],
    ],

    // Perplexity Search API — premium web/people search tools. This is not a
    // chat/runtime provider; Brave remains the default web_search backend.
    'perplexity' => [
        'api_key' => env('PERPLEXITY_API_KEY'),
        'search_url' => env('PERPLEXITY_SEARCH_URL', 'https://api.perplexity.ai/search'),
        'max_results' => env('PERPLEXITY_SEARCH_MAX_RESULTS', 10),
        'country' => env('PERPLEXITY_SEARCH_COUNTRY', 'BG'),
        // Flat Search API cost: $5 / 1K requests (web and people search).
        'request_cost_usd' => env('PERPLEXITY_REQUEST_COST_USD', 0.005),
    ],

    // Mistral OCR only. Chat models are intentionally not registered as a
    // planner/runtime provider because local Ollama + existing cloud providers
    // already cover that path.
    'mistral' => [
        'api_key' => env('MISTRAL_API_KEY'),
        'ocr_url' => env('MISTRAL_OCR_URL', 'https://api.mistral.ai/v1/ocr'),
        'ocr_model' => env('MISTRAL_OCR_MODEL', 'mistral-ocr-latest'),
        'ocr_timeout' => env('MISTRAL_OCR_TIMEOUT', 120),
        // OCR 3 pricing: $2 / 1K pages.
        'ocr_page_cost_usd' => env('MISTRAL_OCR_PAGE_COST_USD', 0.002),
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
