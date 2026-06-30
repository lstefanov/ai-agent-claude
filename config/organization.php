<?php

return [
    // Управителят/Директорите — нивото и планер-провайдърът по подразбиране.
    'manager' => [
        'level' => env('ORG_MANAGER_LEVEL', 'ultra'),   // ModelLevel за Управителя/Директорите
        'provider' => env('ORG_PLANNER_PROVIDER', ''),     // празно → planner default
        'model' => env('ORG_PLANNER_MODEL', ''),
        'max_questions' => (int) env('ORG_INTERVIEW_MAX_QUESTIONS', 12),
        'max_followup_questions' => (int) env('ORG_INTERVIEW_MAX_FOLLOWUP_QUESTIONS', 3),
        'min_questions' => (int) env('ORG_INTERVIEW_MIN_QUESTIONS', 3),   // под този праг „ready" без въпрос е забранено
    ],
    'director' => ['default_level' => env('ORG_DIRECTOR_LEVEL', 'high')],
    'persona' => ['portraits' => (bool) env('ORG_PERSONA_PORTRAITS', true)],
    // act (write конектори) са HARD-DISABLED под preview ClientAuth (draft-first),
    // докато няма реален auth — §B2 / Фаза 5. false → act задачите дават „чернова
    // на действието" без реален страничен ефект; реалният auth е предусловие за true.
    'act' => ['enabled' => (bool) env('ORG_ACT_ENABLED', false)],   // глобален master; per-company act_enabled + active конектор са допълнителните условия (OrgActPolicy)

    // Дневен таван + cadence за АВТОНОМНАТА работа (директорски ticks, ревюта, scheduled,
    // ignition). Брои само origin=autonomous → ръчните runs никога не се ограничават.
    'autonomous' => [
        'caps' => [
            'daily_credits' => (int) env('ORG_AUTON_DAILY_CREDITS', 0),            // 0 = изключено
            'daily_percent_of_balance' => (int) env('ORG_AUTON_DAILY_PCT', 0),     // 0 = изключено
            'ignition_exempt' => (bool) env('ORG_AUTON_IGNITION_EXEMPT', true),    // запалването не се таванира
        ],
        'director' => [
            'propose_cooldown_hours' => (int) env('ORG_DIRECTOR_PROPOSE_COOLDOWN_H', 24),
            'max_open_proposals' => (int) env('ORG_DIRECTOR_MAX_OPEN_PROPOSALS', 7),
            'propose_limit' => (int) env('ORG_DIRECTOR_PROPOSE_LIMIT', 2),
        ],
    ],

    // Стартови кредити при одобрение на нов екип (след ресет или първо създаване).
    'ignition' => [
        'startup_credits' => (int) env('ORG_IGNITION_STARTUP_CREDITS', 1000),
    ],

    'seed_verticals' => ['fitness', 'restaurant', 'services'],   // §11 — 3 seed вертикали

    // Пълна палитра за отдели (18 char-* токена) — един източник на истината за пикера,
    // validation и DepartmentColorService::assignUnique().
    'department_colors' => [
        'crimson' => 'тъмночервено',
        'coral' => 'коралово',
        'amber' => 'кехлибарено',
        'yellow' => 'жълто',
        'chartreuse' => 'шартрьоз',
        'green' => 'зелено',
        'spring' => 'пролетно зелено',
        'teal' => 'тюркоазено',
        'mint' => 'мента',
        'sky' => 'небесно синьо',
        'blue' => 'синьо',
        'navy' => 'тъмно синьо',
        'purple' => 'лилаво',
        'violet' => 'виолетово',
        'magenta' => 'магента',
        'pink' => 'розово',
        'bronze' => 'бронзово',
        'slate' => 'сивосиньо',
    ],

    // Hue° (0–360) за assignUnique hue-distance — sync с CSS токените.
    'department_color_hues' => [
        'crimson' => 350, 'coral' => 17, 'amber' => 38, 'yellow' => 48,
        'chartreuse' => 82, 'green' => 85, 'spring' => 142, 'teal' => 162,
        'mint' => 174, 'sky' => 199, 'blue' => 215, 'navy' => 225,
        'purple' => 252, 'violet' => 271, 'magenta' => 292, 'pink' => 340,
        'bronze' => 25, 'slate' => 215,
    ],

    // Цвят = ФУНКЦИЯ/домейн (§10.1), стабилно и централно — не member.id % 7. Ключовете
    // се match-ват като подниз срещу домейна на члена (директор: свой; асистент: на директора му).
    // Стойностите са char-* токени (resources/css). Един домейн = един цвят навсякъде.
    'function_colors' => [
        'маркетинг' => 'coral',
        'съдържание' => 'coral',
        'контент' => 'coral',
        'продажб' => 'blue',
        'клиент' => 'blue',
        'данни' => 'teal',
        'анализ' => 'teal',
        'операци' => 'amber',
        'логистик' => 'amber',
        'поддръжка' => 'green',
        'обслужване' => 'green',
        'финанс' => 'purple',
        'админ' => 'purple',
        'творч' => 'pink',
        'бранд' => 'pink',
        'дизайн' => 'pink',
        // Английски domain ключове (blueprint-ите и department_catalog ползват английски
        // домейни) → същите char-* цветове, за да е колоритен и blueprint, и композиран екип.
        'operation' => 'amber', 'logistic' => 'amber', 'booking' => 'amber', 'reservation' => 'amber',
        'field' => 'amber', 'kitchen' => 'amber', 'training' => 'amber',
        'marketing' => 'coral', 'content' => 'coral',
        'web' => 'pink', 'site' => 'pink', 'brand' => 'pink', 'design' => 'pink',
        'sales' => 'blue', 'customer' => 'blue', 'client' => 'blue',
        'competitor' => 'teal', 'data' => 'teal', 'analytic' => 'teal', 'research' => 'teal',
        'pricing' => 'purple', 'finance' => 'purple', 'admin' => 'purple',
        'review' => 'green', 'reputation' => 'green', 'support' => 'green', 'retention' => 'green',
    ],
    'default_function_color' => 'blue',

    // Type-метаданни за Кутията за решения (§decisions). Всеки тип/kind получава
    // СВОЙ цвят (char токен → bg-char-{c}-soft / text-char-{c}-strong, safelist-нати),
    // българско име, категория (за групиращия chip) и Heroicon (outline). Един източник
    // на истина — изгледът не помни цветове/имена. Ключът е proposal.type или синтетичния
    // kind (assistant_task/run_approval). Непознат тип → proposal_type_fallback.
    'proposal_types' => [
        'task' => ['label' => 'Задача',            'category' => 'Задача',     'color' => 'blue',   'icon' => 'clipboard-document-list'],
        'hire' => ['label' => 'Наемане',           'category' => 'Структурно', 'color' => 'green',  'icon' => 'user-plus'],
        'fire' => ['label' => 'Съкращение',        'category' => 'Структурно', 'color' => 'coral',  'icon' => 'user-minus'],
        'mandate' => ['label' => 'Промяна на мандат', 'category' => 'Структурно', 'color' => 'amber',  'icon' => 'identification'],
        'tier_change' => ['label' => 'Промяна на ниво',   'category' => 'Структурно', 'color' => 'purple', 'icon' => 'arrow-trending-up'],
        'assistant_task' => ['label' => 'Предложена задача', 'category' => 'Задача',     'color' => 'teal', 'icon' => 'sparkles'],
        'assistant_task_knowledge' => ['label' => 'Чака знания', 'category' => 'Задача', 'color' => 'amber', 'icon' => 'book-open'],
        'run_approval' => ['label' => 'Изпълнение',         'category' => 'Изпълнение', 'color' => 'pink', 'icon' => 'play-circle'],
    ],
    'proposal_type_fallback' => ['label' => 'Предложение', 'category' => 'Структурно', 'color' => 'blue', 'icon' => 'document'],

    // Гейт по знание (§2-етапни задачи): задача не стига до FlowRun, ако изисква фирмено
    // знание, което липсва. enabled=false → гейтът е no-op (fail open). satisfied_threshold е
    // долната граница за best_score (coverage judge-ът е реалното решение, не само резултатът).
    'knowledge_gate' => [
        'enabled' => (bool) env('ORG_KNOWLEDGE_GATE', true),
        'satisfied_threshold' => (float) env('ORG_KNOWLEDGE_GATE_THRESHOLD', 0.55),
    ],

    // Композиция на екипа според маркираните проблеми (§smart-composition). Управителят
    // ПРЕДЛАГА набор от отдели, който покрива фокус-областите; КОДЪТ дедуплицира по домейн,
    // гарантира ядро и налага таван. Повече проблеми → по-голям, но смислен екип (не сляпо 1:1).
    'composition' => [
        'max_directors' => (int) env('ORG_MAX_DIRECTORS', 6),
        // Всеки отдел е достатъчно сложен → поне 2 асистента, за да се разпределят задачите.
        'min_assistants_per_director' => (int) env('ORG_MIN_ASSISTANTS_PER_DIRECTOR', 2),
        'max_assistants_per_director' => (int) env('ORG_MAX_ASSISTANTS_PER_DIRECTOR', 4),
        'core_domains' => ['operations'],   // винаги поне това — жизнеспособен екип дори при 1 проблем
    ],

    // Подредените области за „обзорния" въпрос на интервюто (§structured-sweep). Всяка
    // област = домейн от department_catalog → маркирана област създава съответния отдел.
    // operations е ядро (винаги се добавя), но го показваме и тук, за да може бизнесът
    // да изрази операционни нужди.
    'interview_areas' => [
        'marketing', 'web', 'bookings', 'sales', 'pricing',
        'customer', 'reviews', 'competitor', 'operations', 'finance', 'data',
    ],

    // Кросвертикален каталог от отдели (меню за композицията + обзорния въпрос). Домейните
    // match-ват function_colors. `interview_label` е бизнес-четимото име на областта в
    // обзорния въпрос. Управителят подбира оттук + от blueprint-а; добавя нов отдел само
    // ако никой не покрива дадена фокус-област.
    'department_catalog' => [
        'operations' => [
            'title' => 'Директор Операции', 'interview_label' => 'Ежедневни операции и организация',
            'mandate' => 'Гладко ежедневие — процеси, графици, координация, доставки/изпълнение.',
            'assistants' => [['title' => 'Асистент Координация', 'mandate' => 'Разпределя задачи и поддържа графика.']],
        ],
        'marketing' => [
            'title' => 'Директор Маркетинг', 'interview_label' => 'Маркетинг и привличане на нови клиенти',
            'mandate' => 'Привличане и задържане през съдържание, кампании и присъствие.',
            'assistants' => [
                ['title' => 'Асистент Съдържание', 'mandate' => 'Постове и съобщения в тона на бранда.'],
                ['title' => 'Асистент Социални мрежи', 'mandate' => 'Планиране и публикуване по канали.'],
            ],
        ],
        'web' => [
            'title' => 'Директор Онлайн присъствие', 'interview_label' => 'Уебсайт и онлайн присъствие',
            'mandate' => 'Уебсайт, видимост в търсачките и онлайн представяне за нови клиенти.',
            'assistants' => [['title' => 'Асистент Уеб съдържание', 'mandate' => 'Текстове за сайта и SEO страници.']],
        ],
        'competitor' => [
            'title' => 'Директор Конкурентен анализ', 'interview_label' => 'Поглед над конкурентите',
            'mandate' => 'Наблюдава конкурентите, цени и позициониране; дава предимства.',
            'assistants' => [['title' => 'Асистент Пазарно проучване', 'mandate' => 'Събира и обобщава данни за конкуренти.']],
        ],
        'bookings' => [
            'title' => 'Директор Резервации', 'interview_label' => 'Онлайн резервации и записвания',
            'mandate' => 'Онлайн резервации/записвания, напомняния, заетост.',
            'assistants' => [['title' => 'Асистент Резервации', 'mandate' => 'Управление на записвания и напомняния.']],
        ],
        'pricing' => [
            'title' => 'Директор Ценообразуване', 'interview_label' => 'Ценообразуване, пакети и оферти',
            'mandate' => 'Цени, пакети и оферти — ясна и конкурентна стойност.',
            'assistants' => [['title' => 'Асистент Оферти', 'mandate' => 'Подготвя оферти и ценови предложения.']],
        ],
        'customer' => [
            'title' => 'Директор Клиентско', 'interview_label' => 'Задържане на клиенти и обратна връзка',
            'mandate' => 'Задържане, обратна връзка и лоялност.',
            'assistants' => [['title' => 'Асистент Задържане', 'mandate' => 'Кампании за връщане на неактивни клиенти.']],
        ],
        'reviews' => [
            'title' => 'Директор Репутация', 'interview_label' => 'Репутация и онлайн ревюта',
            'mandate' => 'Ревюта, отзиви и онлайн репутация.',
            'assistants' => [['title' => 'Асистент Ревюта', 'mandate' => 'Мониторинг и отговор на отзиви.']],
        ],
        'sales' => [
            'title' => 'Директор Продажби', 'interview_label' => 'Продажби и проследяване на запитвания',
            'mandate' => 'Запитвания, конверсия и проследяване до продажба.',
            'assistants' => [['title' => 'Асистент Последващи контакти', 'mandate' => 'Проследява запитвания и води до решение.']],
        ],
        'finance' => [
            'title' => 'Директор Финанси', 'interview_label' => 'Финанси, фактуриране и отчети',
            'mandate' => 'Приходи, разходи, фактуриране и отчети.',
            'assistants' => [['title' => 'Асистент Фактуриране', 'mandate' => 'Подготвя фактури и напомняния за плащане.']],
        ],
        'data' => [
            'title' => 'Директор Данни', 'interview_label' => 'Анализи и данни за решения',
            'mandate' => 'Анализи, тенденции и прогнози за решения.',
            'assistants' => [['title' => 'Асистент Отчети', 'mandate' => 'Изготвя периодични справки и обобщения.']],
        ],
    ],
];
