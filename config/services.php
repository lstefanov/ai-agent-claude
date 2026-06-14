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
        // OAuth (MCP конектор) — глобален FlowAI Slack app.
        'oauth' => [
            'client_id' => env('SLACK_OAUTH_CLIENT_ID'),
            'client_secret' => env('SLACK_OAUTH_CLIENT_SECRET'),
            'redirect' => env('SLACK_OAUTH_REDIRECT_URI'),
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
        // Local embeddings model (знания + памет) — pull it on the Ollama
        // host first: `ollama pull bge-m3`. bge-m3 е мултиезичен (вкл. BG)
        // с 8k контекст; nomic-embed-text е по-лек, но по-слаб на кирилица.
        'embedding_model' => env('OLLAMA_EMBEDDING_MODEL', 'bge-m3'),
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
        'xai_model' => env('ASSIST_XAI_MODEL', 'grok-4.3'),
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

    // Памет на flow-а: какво е произвеждал flow-ът в предишни run-ове (дедуп
    // на съдържание) + поуки per агент от QA/replan събития.
    'memory' => [
        // Глобален ключ; всеки flow има и собствен toggle (settings.memory.enabled).
        'enabled' => env('FLOW_MEMORY_ENABLED', true),
        // Кой смята embeddings за сходството: ollama (безплатно локално,
        // default) | gemini (безплатен tier) | openai (платен). Виж
        // EmbeddingService за детайли по опциите.
        'embedding_provider' => env('MEMORY_EMBEDDING_PROVIDER', 'ollama'),
        // null → per-provider default (ollama: OLLAMA_EMBEDDING_MODEL).
        'embedding_model' => env('MEMORY_EMBEDDING_MODEL'),
        // Cosine ≥ прага = "твърде подобно" → retry с feedback. Евристично
        // съответствие на правилото "до 30-40% припокриване на съдържанието".
        'similarity_threshold' => env('MEMORY_SIMILARITY_THRESHOLD', 0.80),
        // Колко пъти нод се пренаписва заради дубликат, преди да бъде приет с флаг.
        'dedup_max_retries' => env('MEMORY_DEDUP_MAX_RETRIES', 2),
        // Пазени 'output' записа per flow (най-старите се изтриват).
        'max_output_entries' => env('MEMORY_MAX_OUTPUT_ENTRIES', 200),
        // Пазени 'lesson' записа per нод.
        'max_lessons_per_node' => env('MEMORY_MAX_LESSONS_PER_NODE', 5),
        // Колко дайджеста влизат в "ПАМЕТ" блока на prompt-а.
        'prompt_entries' => env('MEMORY_PROMPT_ENTRIES', 10),
    ],

    // База знания на фирмата (RAG v2, NotebookLM стил): ресурси (url, файл,
    // снимка, бележка) → страници → чанкове + факти (натрупващият се профил).
    // Памет = "какво сме произвели"; знание = "какво е вярно за фирмата".
    'knowledge' => [
        // Глобален ключ; всяка фирма (settings.knowledge.enabled) и всеки flow
        // (flow settings.knowledge.enabled) имат собствен toggle.
        'enabled' => env('KNOWLEDGE_ENABLED', true),
        // Embeddings: ollama (безплатно локално, default) | gemini (безплатен
        // tier) | openai (платен). null → MEMORY_EMBEDDING_PROVIDER. Смяната
        // изисква re-ingest (виж EmbeddingService).
        'embedding_provider' => env('KNOWLEDGE_EMBEDDING_PROVIDER'),
        // null → per-provider default (ollama: bge-m3).
        'embedding_model' => env('KNOWLEDGE_EMBEDDING_MODEL'),
        // СИНТЕЗ (digest на страница/документ + извличане на факти): най-
        // тежката LLM работа при ingest → евтин/безплатен cloud по default
        // (gemini flash-lite е free tier). Провайдъри: gemini | deepseek |
        // openai | xai | qwen. model null → runtime_model на провайдъра.
        'synth_provider' => env('KNOWLEDGE_SYNTH_PROVIDER', 'gemini'),
        'synth_model' => env('KNOWLEDGE_SYNTH_MODEL'),
        // Чатът "Тествай знанията": локален по default (безплатни въпроси).
        // ollama + празен model → ModelSelector избира BG модела (BgGPT).
        'chat_provider' => env('KNOWLEDGE_CHAT_PROVIDER', 'ollama'),
        'chat_model' => env('KNOWLEDGE_CHAT_MODEL'),
        'chat_history_limit' => 20,
        'chunk_size' => (int) env('KNOWLEDGE_CHUNK_SIZE', 1200),
        'chunk_overlap' => (int) env('KNOWLEDGE_CHUNK_OVERLAP', 150),
        // Колко чанка връща търсенето / влизат в "ЗНАНИЕ" блока.
        'top_k' => (int) env('KNOWLEDGE_TOP_K', 5),
        // Диверсификация: максимум чанкове от ЕДНА страница/ресурс в topK
        // (фактите не се броят) — иначе една страница запълва целия резултат.
        'max_per_source' => (int) env('KNOWLEDGE_MAX_PER_SOURCE', 2),
        // Тегло на keyword канала в RRF сливането (векторният е 1.0) — чист
        // TF match на честа дума не бива да бие семантично уцелен чанк.
        'rrf_keyword_weight' => (float) env('KNOWLEDGE_RRF_KEYWORD_WEIGHT', 0.75),
        'block_max_chars' => (int) env('KNOWLEDGE_BLOCK_MAX_CHARS', 2500),
        // Под този cosine score чанкът не влиза в "ЗНАНИЕ" блока.
        'min_score' => (float) env('KNOWLEDGE_MIN_SCORE', 0.25),
        // Под този best score grounding търсенето се логва като "пропуск"
        // (агент е търсил нещо, за което няма покритие в базата).
        'gap_threshold' => (float) env('KNOWLEDGE_GAP_THRESHOLD', 0.55),
        // Cosine ≥ прага → нов чанк/факт "запълва" отворен пропуск (готов).
        'gap_resolve_threshold' => (float) env('KNOWLEDGE_GAP_RESOLVE_THRESHOLD', 0.62),
        'max_gaps_per_company' => (int) env('KNOWLEDGE_MAX_GAPS', 200),
        'max_file_mb' => (int) env('KNOWLEDGE_MAX_FILE_MB', 20),
        // Предпазители срещу огромни файлове: редове per Excel лист и общ
        // таван на извлечения текст преди chunking.
        'xlsx_max_rows' => (int) env('KNOWLEDGE_XLSX_MAX_ROWS', 2000),
        'max_extract_chars' => (int) env('KNOWLEDGE_MAX_EXTRACT_CHARS', 600000),
        // Таван на сканираните чанкове при едно търсене (cursor scan).
        'max_scan_chunks' => (int) env('KNOWLEDGE_MAX_SCAN_CHUNKS', 8000),
        // Таван на BFS обхождането на един url ресурс (уникални страници).
        'site_max_pages' => (int) env('KNOWLEDGE_SITE_MAX_PAGES', 200),
        // Факт-дедупликация: cosine ≥ прага (същата категория) → новата
        // стойност supersede-ва стария факт вместо да добави дубликат.
        'fact_similarity_threshold' => (float) env('KNOWLEDGE_FACT_SIMILARITY', 0.86),
        // Откриване на КОНФЛИКТИ: два активни факта (същата категория+локация),
        // чиито имена споделят достатъчно СЪДЪРЖАТЕЛНИ думи (Jaccard ≥ прага,
        // след премахване на генеричните думи по-долу), но с РАЗЛИЧНА стойност
        // = конфликт за ръчен преглед. Lexical (не embedding), защото embedding-ите
        // не разделят зоните: „…подмишници мъже" (0.88) ≈ „…цяло тяло мъже" (0.876).
        'conflict_overlap' => (float) env('KNOWLEDGE_CONFLICT_OVERLAP', 0.7),
        // Генерични думи в имената, които НЕ носят идентичност — премахват се,
        // за да не слепят различни зони („цена лазерна епилация ПОДМИШНИЦИ" vs
        // „… ЦЯЛО ТЯЛО"). Останалите думи (зона + пол) определят „едно и също нещо".
        'conflict_stopwords' => [
            'цена', 'цени', 'лазерна', 'епилация', 'единична', 'процедура',
            'процедури', 'пакет', 'зона', 'броя', 'брой',
        ],
        // Факти с по-нисък confidence от извличащия LLM се пропускат.
        'fact_min_confidence' => (float) env('KNOWLEDGE_FACT_MIN_CONFIDENCE', 0.5),
    ],

    // Inline step-QA gate (StepQaGate / QaVerifierAgent) tuning.
    'qa' => [
        // Override the QA verifier model. Empty → the best installed local QA
        // model via ModelSelectorService (qwen3:4b), which is noisy/harsh on long
        // research output. Point this at a stronger judge — a cheap cloud model
        // (e.g. gemini/gemini-3.1-flash-lite) or a larger local one. Routed like
        // any paid-prefixed model through OllamaService::chat().
        'model' => env('QA_VERIFIER_MODEL'),
        // Per-node QA-retry cost ceiling (USD). Once a node's accumulated cost
        // exceeds this, the loop stops re-running and accepts the best output it
        // has (flagged), instead of burning money on more full re-runs. 0 = off.
        'max_retry_cost_usd' => (float) env('QA_MAX_RETRY_COST_USD', 0.50),
    ],

    // FlowPlannerService tuning (the "agent that creates agents").
    'planner' => [
        // Phase C: a second LLM pass that reviews + repairs the generated plan.
        'critique' => env('PLANNER_CRITIQUE', true),
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
            // Eval Suite LLM-as-judge. Евтин/бърз по подразбиране (gemini),
            // независимо от GENERATOR_PROVIDER; може openai/anthropic за по-строг
            // judge. Резолвва се през GeneratorService::chatJson(..., 'eval_judge').
            'eval_judge' => [
                'provider' => env('EVAL_JUDGE_PROVIDER', 'gemini'),
                'model' => env('EVAL_JUDGE_MODEL', 'gemini-3.1-flash-lite'),
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

    // Eval Suite — колко eval FlowRun-а текат едновременно. Локалният GPU има
    // ОДИН слот (OLLAMA_MAX_CONCURRENT=1), затова десетки паралелни eval flow-а
    // гладуват и удрят node timeout-а. RunFlowEvalJob дроселира до тази бройка.
    'eval' => [
        'max_concurrent' => (int) env('EVAL_MAX_CONCURRENT', 3),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_GENERATOR_MODEL', 'claude-sonnet-4-6'),
        // Default model for agents the planner pins to Anthropic at runtime
        // (their node model becomes "anthropic/<runtime_model>").
        'runtime_model' => env('ANTHROPIC_RUNTIME_MODEL', 'claude-haiku-4-5'),
        // The most expensive flagship — used ONLY by the GOD level (PaidModel::pinTop).
        'flagship_model' => env('ANTHROPIC_FLAGSHIP_MODEL', 'claude-sonnet-4-6'),
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
        // The most expensive flagship — used ONLY by the GOD level (PaidModel::pinTop).
        'flagship_model' => env('OPENAI_FLAGSHIP_MODEL', 'gpt-4o'),
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
        // Embeddings през OpenAI-compatible /embeddings (безплатен tier).
        'embedding_model' => env('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-001'),
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
        // Runtime възли (planner pin "xai/<runtime_model>"): grok-4.3 — xAI
        // обедини линийката, флагманът е и най-бързият модел (няма вече отделен
        // евтин "fast" вариант).
        'runtime_model' => env('XAI_RUNTIME_MODEL', 'grok-4.3'),
        'base_url' => env('XAI_BASE_URL', 'https://api.x.ai/v1'),
        'structured_output' => env('XAI_STRUCTURED_OUTPUT', 'json_schema'),
        'max_tokens_param' => 'max_tokens',
        'pricing' => [
            'grok-4.3' => ['in' => 1.25, 'out' => 2.50, 'stars' => 3, 'desc' => 'Флагман — най-умен и най-бърз, 1M контекст'],
            'grok-4.20-0309-non-reasoning' => ['in' => 1.25, 'out' => 2.50, 'stars' => 2, 'desc' => 'Без reasoning — по-малко токени, 1M контекст'],
            'grok-build-0.1' => ['in' => 1.00, 'out' => 2.00, 'stars' => 1, 'desc' => 'Най-евтин — 256K контекст'],
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
        // Cap for locally uploaded files sent as base64 data: URIs.
        'ocr_max_file_mb' => (int) env('MISTRAL_OCR_MAX_FILE_MB', 25),
    ],

    'brave' => [
        'api_key' => env('BRAVE_SEARCH_API_KEY'),
        'results_count' => env('BRAVE_RESULTS_COUNT', 10),
        // Flat cost per query for the admin cost audit. Free tier = 0; paid plans
        // run ~$3–5 / 1K queries — set this to your contract's per-query price.
        'request_cost_usd' => env('BRAVE_REQUEST_COST_USD', 0.005),
    ],

    'google_places' => [
        // Google Places API (New) key — used for business reviews/rating.
        // Enable "Places API (New)" in Google Cloud Console for the key.
        'api_key' => env('GOOGLE_PLACES_API_KEY'),
        // Flat cost per reviewsFor() lookup for the admin cost audit. One lookup
        // bills Text Search (~$32/1K) + Place Details (~$17/1K) ≈ $0.049.
        'request_cost_usd' => env('GOOGLE_PLACES_REQUEST_COST_USD', 0.05),
    ],

    // Google OAuth (Laravel Socialite) — глобален FlowAI app за MCP конекторите
    // Gmail/Sheets/Drive. Активирай нужните APIs в Google Cloud Console и сложи
    // redirect URI-то, което сочи към oauth.google.callback route-а.
    'google' => [
        'client_id' => env('GOOGLE_OAUTH_CLIENT_ID'),
        'client_secret' => env('GOOGLE_OAUTH_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_OAUTH_REDIRECT_URI'),
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
        // Таван страници за агентските crawl_site/discover_urls tools (BFS).
        // Ingest-ът на знанията ползва отделния KNOWLEDGE_SITE_MAX_PAGES.
        'max_pages' => env('CRAWL_MAX_PAGES', 30),
        // Кои страници BFS-ът прескача: точни path сегменти + regex. Правните
        // страници (условия/политики) се ПАЗЯТ — носят условия на бизнеса.
        // Override-ни ги тук при нужда (виж CrawlService::isSkippedPage).
        // 'skip_segments' => [...], 'skip_pattern' => '/.../',
        // Глобален markdown кеш на скрейпнати страници (web_page_cache):
        // в TTL прозореца страницата се връща без HTTP; след него fetch +
        // content-hash сравнение. Покрива и digest кеша на deep_researcher.
        'cache_enabled' => env('CRAWL_CACHE_ENABLED', true),
        'cache_ttl_hours' => (int) env('CRAWL_CACHE_TTL_HOURS', 24),
        // Неизползвани записи по-стари от това се чистят от knowledge:prune-web-cache.
        'cache_retention_days' => (int) env('CRAWL_CACHE_RETENTION_DAYS', 90),
    ],

];
