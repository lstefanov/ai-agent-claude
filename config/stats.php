<?php

/**
 * Етикети за per-company статистиката (CompanyStatsController). Единствен източник на
 * човешки имена/цветове/икони — четен в PHP (StatsLabels) и подаван към JS през @js().
 * Цветовете са char-* токени (виж resources/css) или прости думи за чарт палитрата.
 */
return [
    // context_type (билинг) → услуга. `desc` = кратко обяснение (tooltip в таблицата).
    'services' => [
        'org_planning' => ['label' => 'Създаване/планиране на екип', 'color' => 'purple', 'icon' => 'users', 'desc' => 'Управителят/директорите проектират екипа: роли, отдели, разширения.'],
        'org_digest' => ['label' => 'Обобщения', 'color' => 'purple', 'icon' => 'document-text', 'desc' => 'Периодични обобщения на дейността на организацията.'],
        'generation' => ['label' => 'Създаване на flow/агенти', 'color' => 'blue', 'icon' => 'cpu-chip', 'desc' => 'Планерът проектира агентния pipeline (flow) от описание.'],
        'task_run' => ['label' => 'Дневни задачи', 'color' => 'teal', 'icon' => 'clipboard-document-list', 'desc' => 'Изпълнение на задачи на асистентите (реални flow runs).'],
        'director_tick' => ['label' => 'Автономни решения', 'color' => 'amber', 'icon' => 'bolt', 'desc' => 'Директорите мислят периодично и предлагат действия.'],
        'member_chat' => ['label' => 'Чатове с членове', 'color' => 'green', 'icon' => 'chat-bubble-left-right', 'desc' => 'Разговори с членове на екипа (директори/асистенти).'],
        'interview' => ['label' => 'Интервю', 'color' => 'coral', 'icon' => 'microphone', 'desc' => 'Онбординг интервю при създаване на организацията.'],
        'research' => ['label' => 'Проучване на бизнеса', 'color' => 'teal', 'icon' => 'magnifying-glass', 'desc' => 'AI проучване на бизнеса при онбординг.'],
        'text_assist' => ['label' => 'Подобри с AI', 'color' => 'pink', 'icon' => 'sparkles', 'desc' => 'Подобряване/генериране на текст за поле (Тон, Опит, Био, описание на flow).'],
        'avatar' => ['label' => 'Портрети', 'color' => 'pink', 'icon' => 'photo', 'desc' => 'Генериране на портрети (аватари) за членовете през ComfyUI.'],
        'assistant' => ['label' => 'Builder Copilot', 'color' => 'amber', 'icon' => 'command-line', 'desc' => 'AI помощник в билдъра на flows.'],
        'client_wizard' => ['label' => 'Разговорен създател', 'color' => 'sky', 'icon' => 'sparkles', 'desc' => 'Разговорно създаване на flow за клиенти.'],
        'knowledge_chat' => ['label' => 'Чат със знанията', 'color' => 'violet', 'icon' => 'chat-bubble-left-right', 'desc' => 'Въпроси към базата знания на фирмата.'],
    ],
    // purpose → услуга (за llm_requests редове без context_type)
    'purposes' => [
        'embedding' => ['label' => 'Embeddings', 'color' => 'blue'],
        'knowledge_synthesis' => ['label' => 'Синтез на знания', 'color' => 'violet'],
        'knowledge_fact_harvest' => ['label' => 'Извличане на факти', 'color' => 'violet'],
        'knowledge_chat' => ['label' => 'Чат със знанията', 'color' => 'violet'],
        'knowledge_ocr' => ['label' => 'OCR на документи', 'color' => 'amber'],
        'assistant' => ['label' => 'Builder Copilot', 'color' => 'amber'],
        'client_wizard' => ['label' => 'Разговорен създател', 'color' => 'sky'],
        'org_generation' => ['label' => 'Създаване на flow/агенти', 'color' => 'blue'],
        'runtime' => ['label' => 'Изпълнение на агенти', 'color' => 'teal'],
    ],
    // external провайдъри → име
    'external' => [
        'perplexity' => 'Perplexity',
        'brave' => 'Brave Search',
        'google_places' => 'Google Places',
    ],
    // ledger тип → българско име (за историята на кредитите)
    'ledger_types' => [
        'reserve' => 'Резервиране',
        'settle' => 'Похарчено',
        'refund' => 'Връщане',
        'overage' => 'Овърдрафт',
        'topup' => 'Зареждане',
        'grant' => 'Месечен грант',
    ],
    // origin → българско име (чип в историята)
    'origins' => [
        'manual' => 'Ръчно',
        'autonomous' => 'Автономно',
        'system' => 'Системно',
    ],
    'fallback' => ['label' => 'Друго', 'color' => 'gray', 'icon' => 'ellipsis-horizontal'],
];
