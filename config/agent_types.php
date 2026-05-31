<?php

return [

    // --- Researchers / Data Gatherers ---
    'researcher' => ['output_role' => 'hidden',    'label' => 'Изследовател',               'description' => 'Събира контекст, тенденции, актуални новини, данни за конкуренти'],
    'deep_researcher' => ['output_role' => 'hidden',    'label' => 'Дълбок изследовател',        'description' => 'Търси с Brave + scrape-ва пълното съдържание на намерените страници (цени, услуги)'],
    'trend_researcher' => ['output_role' => 'hidden',    'label' => 'Изследовател на тенденции',  'description' => 'Търси тенденции и вирусни теми в нишата за контент идеи'],
    'competitor_profiler' => ['output_role' => 'hidden',    'label' => 'Профилиращ конкуренти',      'description' => 'Изгражда пълен профил на конкурент (услуги, цени, позициониране, слабости)'],
    'review_analyzer' => ['output_role' => 'hidden',    'label' => 'Анализатор на ревюта',       'description' => 'Scrape-ва и анализира ревюта — открива recurring patterns и sentiment'],
    'keyword_extractor' => ['output_role' => 'hidden',    'label' => 'Екстрактор на ключови думи', 'description' => 'Открива SEO ключови думи от SERP анализ с тип на намерение'],
    'image_describer' => ['output_role' => 'hidden',    'label' => 'Описател на изображения',    'description' => 'Описва изображение с текст (изисква vision-capable Ollama модел като llava)'],
    'scraper' => ['output_role' => 'hidden',    'label' => 'Скрейпър',                   'description' => 'Извлича суров текст и данни от уебстраници'],

    // --- Analyzers / Processors ---
    'analyzer' => ['output_role' => 'hidden',    'label' => 'Анализатор',                 'description' => 'Анализира входа, извлича ключови инсайти, идентифицира възможности'],
    'swot_builder' => ['output_role' => 'hidden',    'label' => 'SWOT Анализатор',            'description' => 'Генерира SWOT анализ (силни/слаби страни, възможности, заплахи) от данни'],
    'data_extractor' => ['output_role' => 'hidden',    'label' => 'Екстрактор на данни',        'description' => 'Извлича структурирани данни (таблици, цени, списъци) от суров текст'],
    'classifier' => ['output_role' => 'hidden',    'label' => 'Класификатор',               'description' => 'Категоризира съдържание по зададени категории'],
    'sentiment_analyzer' => ['output_role' => 'hidden',    'label' => 'Анализатор на тон',          'description' => 'Анализира тон (позитивен/неутрален/негативен) с числена оценка'],
    'summarizer' => ['output_role' => 'hidden',    'label' => 'Резюматор',                  'description' => 'Кондензира дълго съдържание в ключови точки'],
    'decision' => ['output_role' => 'hidden',    'label' => 'Разпределител',              'description' => 'Взима routing/условни решения въз основа на входните данни'],

    // --- Content Writers (body) ---
    'content_bg' => ['output_role' => 'body',      'label' => 'Автор (BG)',                 'description' => 'Пише текстово съдържание на български език'],
    'content_en' => ['output_role' => 'body',      'label' => 'Автор (EN)',                 'description' => 'Пише текстово съдържание на английски език'],
    'writer' => ['output_role' => 'body',      'label' => 'Автор',                      'description' => 'Общ текстов агент — пише съдържание по зададена тема и формат'],
    'caption_writer' => ['output_role' => 'body',      'label' => 'Автор на постове',           'description' => 'Сглобява финалния пост от всички части (текст + хаштагове + CTA)'],
    'hook_writer' => ['output_role' => 'body',      'label' => 'Автор на hook изречения',    'description' => 'Пише attention-grabbing opening изречение за постове'],
    'ad_copywriter' => ['output_role' => 'body',      'label' => 'Рекламен копирайтър',        'description' => 'Рекламни текстове: headline + body + CTA за Meta Ads или Google Ads'],
    'report_writer' => ['output_role' => 'body',      'label' => 'Автор на доклади',           'description' => 'Оформя formal доклад с executive summary, методология и изводи'],
    'newsletter_writer' => ['output_role' => 'body',      'label' => 'Автор на newsletter',        'description' => 'Съставя email newsletter секции с тема, тяло, CTA и subject line'],
    'email_composer' => ['output_role' => 'body',      'label' => 'Автор на имейли',            'description' => 'Пише конкретен имейл (outreach, follow-up, оферта) — НЕ го изпраща'],
    'seo_writer' => ['output_role' => 'body',      'label' => 'SEO автор',                  'description' => 'SEO-оптимизирана статия с keywords, headers H2/H3 и вътрешни линкове'],
    'offer_builder' => ['output_role' => 'body',      'label' => 'Автор на оферти',            'description' => 'Съставя промоционална оферта с цена, бонуси, deadline и USP'],
    'translator' => ['output_role' => 'body',      'label' => 'Преводач',                   'description' => 'Превежда съдържание между езици, запазвайки тон и стил'],
    'publisher' => ['output_role' => 'body',      'label' => 'Публикатор',                 'description' => 'Форматира изхода за конкретни платформи (FB, IG, LinkedIn и др.)'],
    'report_composer' => ['output_role' => 'body',      'label' => 'Композитор на доклади',      'description' => 'Сглобява финален доклад хибридно: верифицирани таблици + LLM препоръки'],
    'bg_text_corrector' => ['output_role' => 'body',      'label' => 'Български коректор',        'description' => 'Коригира правопис, лексика и стил на финалния български текст без да променя смисъла'],
    'formatter' => ['output_role' => 'hidden',    'label' => 'Форматиращ агент',           'description' => 'Форматира изход в JSON, CSV, Markdown или HTML по нужда'],

    // --- Appendix Generators ---
    'hashtag' => ['output_role' => 'appendix',  'label' => 'Хаштаг генератор',           'description' => 'Генерира релевантни хаштагове (локални + международни)'],
    'hashtags' => ['output_role' => 'appendix',  'label' => 'Хаштаг генератор (мн.)',     'description' => 'Генерира релевантни хаштагове (алтернативен тип)'],
    'hashtag_generator' => ['output_role' => 'appendix',  'label' => 'Хаштаг генератор (разш.)',   'description' => 'Генерира САМО хаштагове (#тагове), оптимизирани по платформа и нише'],
    'tags' => ['output_role' => 'appendix',  'label' => 'Генератор на тагове',        'description' => 'Генерира тагове/ключови думи за категоризация'],
    'seo' => ['output_role' => 'appendix',  'label' => 'SEO генератор',              'description' => 'Генерира SEO мета данни и ключови думи'],
    'faq_generator' => ['output_role' => 'appendix',  'label' => 'FAQ генератор',              'description' => 'Генерира FAQ секция от продуктово описание или research данни'],
    'meta_generator' => ['output_role' => 'appendix',  'label' => 'Meta генератор',             'description' => 'Генерира SEO meta title + description + OG tags за уеб страница'],
    'email' => ['output_role' => 'appendix',  'label' => 'Имейл (appendix)',            'description' => 'Генерира email съдържание като appendix към основния изход'],
    'image_prompt' => ['output_role' => 'appendix',  'label' => 'Промпт за изображения',      'description' => 'Пише детайлни промпти за генериране на изображения с ComfyUI/Stable Diffusion'],

    // --- Integration / Webhooks ---
    'webhook_sender' => ['output_role' => 'hidden',    'label' => 'Webhook изпращач',           'description' => 'Изпраща резултатите към external URL (CRM, Zapier, n8n, Make) — изисква config.webhook_url'],
    'slack_notifier' => ['output_role' => 'hidden',    'label' => 'Slack нотификатор',          'description' => 'Изпраща summary нотификация в Slack канал — изисква config.webhook_url'],
    'google_sheets_writer' => ['output_role' => 'hidden',    'label' => 'Google Sheets писач',        'description' => 'Форматира данни като CSV таблица готова за import в Google Sheets'],

    // --- Quality ---
    'qa_verifier' => ['output_role' => 'quality',   'label' => 'Верификатор за качество',    'description' => 'Преглежда качеството на финалния изход, оценява 0-100 — трябва да е последен агент'],
    'verifier' => ['output_role' => 'quality',   'label' => 'Верификатор',                'description' => 'Верифицира изхода на предишен агент — алтернативен тип'],

    // --- Special ---
    'orchestrator' => ['output_role' => 'hidden',    'label' => 'Оркестратор',               'description' => 'Координира множество агенти и управлява flow логиката'],

    // --- Code ---
    'code' => ['output_role' => 'hidden',    'label' => 'Кодиращ агент',              'description' => 'Генерира, рефакторира и дебъгва програмен код (Python, PHP, JS и 300+ езика)'],

    // --- Vision ---
    'vision' => ['output_role' => 'hidden',    'label' => 'Визуален анализатор',        'description' => 'Анализира изображения, OCR и document understanding — изисква vision-capable модел'],

];
