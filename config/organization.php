<?php

return [
    // Управителят/Директорите — нивото и планер-провайдърът по подразбиране.
    'manager' => [
        'level' => env('ORG_MANAGER_LEVEL', 'ultra'),   // ModelLevel за Управителя/Директорите
        'provider' => env('ORG_PLANNER_PROVIDER', ''),     // празно → planner default
        'model' => env('ORG_PLANNER_MODEL', ''),
        'max_questions' => (int) env('ORG_INTERVIEW_MAX_QUESTIONS', 12),
        'min_questions' => (int) env('ORG_INTERVIEW_MIN_QUESTIONS', 3),   // под този праг „ready" без въпрос е забранено
    ],
    'director' => ['default_level' => env('ORG_DIRECTOR_LEVEL', 'high')],
    'persona' => ['portraits' => (bool) env('ORG_PERSONA_PORTRAITS', true)],
    // act (write конектори) са HARD-DISABLED под preview ClientAuth (draft-first),
    // докато няма реален auth — §B2 / Фаза 5. false → act задачите дават „чернова
    // на действието" без реален страничен ефект; реалният auth е предусловие за true.
    'act' => ['enabled' => (bool) env('ORG_ACT_ENABLED', false)],
    'seed_verticals' => ['fitness', 'restaurant', 'services'],   // §11 — 3 seed вертикали

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

    // Композиция на екипа според маркираните проблеми (§smart-composition). Управителят
    // ПРЕДЛАГА набор от отдели, който покрива фокус-областите; КОДЪТ дедуплицира по домейн,
    // гарантира ядро и налага таван. Повече проблеми → по-голям, но смислен екип (не сляпо 1:1).
    'composition' => [
        'max_directors' => (int) env('ORG_MAX_DIRECTORS', 6),
        'max_assistants_per_director' => (int) env('ORG_MAX_ASSISTANTS_PER_DIRECTOR', 2),
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
