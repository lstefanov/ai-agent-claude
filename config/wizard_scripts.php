<?php

/*
|--------------------------------------------------------------------------
| Детерминистични въпросни скриптове за разговорния създател (C2)
|--------------------------------------------------------------------------
|
| За топ-сферите въпросите идват от тук (фиксиран ред + готови опции, БЕЗ LLM).
| `dynamic: 'topic'` въпрос се пълни с 3–5 предложения от фирменото знание чрез
| ЕДИН евтин LLM call (без agentic loop). Непозната заявка / „Друго" / свободен
| текст → визардът пада на пълния LLM режим (WizardScriptService → llmTurn).
|
| description_template: попълва се с отговорите (ключ → {ключ}); {company} = името
| на фирмата. Непопълнени плейсхолдъри се чистят.
|
*/

return [

    // Провайдър/модел за генерирането на теми (евтин + бърз). Резолва се през
    // GeneratorService::chatJson(..., phase). 'eval_judge' = gemini-flash-lite.
    'topic_phase' => env('WIZARD_TOPIC_PHASE', 'eval_judge'),

    'domains' => [

        'social_post' => [
            'match' => ['пост', 'социал', 'facebook', 'инстаграм', 'instagram', 'tiktok', 'reels', 'линкедин', 'linkedin'],
            'title' => 'Пост за социална мрежа',
            'questions' => [
                [
                    'key' => 'platform', 'text' => 'За коя социална мрежа е постът?', 'input_type' => 'checkbox', 'allow_other' => true,
                    'options' => [
                        ['value' => 'Facebook', 'label' => 'Facebook'],
                        ['value' => 'Instagram', 'label' => 'Instagram'],
                        ['value' => 'TikTok', 'label' => 'TikTok'],
                        ['value' => 'LinkedIn', 'label' => 'LinkedIn'],
                    ],
                ],
                ['key' => 'topic', 'text' => 'На каква тема да бъде постът?', 'input_type' => 'radio', 'dynamic' => 'topic', 'allow_other' => true],
                [
                    'key' => 'components', 'text' => 'Какво да включим? (основният текст е по подразбиране)', 'input_type' => 'checkbox', 'allow_other' => true,
                    'options' => [
                        ['value' => 'картинка', 'label' => 'Картинка'],
                        ['value' => 'кукичка (hook)', 'label' => 'Кукичка (hook)'],
                        ['value' => 'хаштагове', 'label' => 'Хаштагове'],
                        ['value' => 'призив за действие', 'label' => 'Призив за действие (CTA)'],
                        ['value' => 'линк', 'label' => 'Линк'],
                    ],
                ],
                [
                    'key' => 'tone', 'text' => 'Какъв тон да има?', 'input_type' => 'radio', 'allow_other' => true,
                    'options' => [
                        ['value' => 'енергичен', 'label' => 'Енергичен'],
                        ['value' => 'професионален', 'label' => 'Професионален'],
                        ['value' => 'приятелски', 'label' => 'Приятелски'],
                    ],
                ],
            ],
            'description_template' => 'Автоматизиран flow за създаване на пост за {platform} за «{company}». Тема: {topic}. Да включва: {components}. Тон: {tone}. Език: български.',
        ],

        'business_audit' => [
            'match' => ['одит', 'audit', 'преглед на бизнес', 'анализ на бизнес'],
            'title' => 'Одит на бизнес',
            'questions' => [
                [
                    'key' => 'audit_type', 'text' => 'Какво да одитираме?', 'input_type' => 'checkbox', 'allow_other' => true,
                    'options' => [
                        ['value' => 'онлайн присъствие', 'label' => 'Онлайн присъствие'],
                        ['value' => 'репутация и отзиви', 'label' => 'Репутация и отзиви'],
                        ['value' => 'SEO', 'label' => 'SEO'],
                        ['value' => 'съдържание', 'label' => 'Съдържание'],
                        ['value' => 'социални канали', 'label' => 'Социални канали'],
                    ],
                ],
                [
                    'key' => 'channels', 'text' => 'Кои канали/източници да обхванем?', 'input_type' => 'checkbox', 'allow_other' => true,
                    'options' => [
                        ['value' => 'уебсайтът ни', 'label' => 'Уебсайтът ни'],
                        ['value' => 'Google Business / отзиви', 'label' => 'Google Business / отзиви'],
                        ['value' => 'Facebook', 'label' => 'Facebook'],
                        ['value' => 'Instagram', 'label' => 'Instagram'],
                    ],
                ],
                [
                    'key' => 'format', 'text' => 'Какъв формат на доклада?', 'input_type' => 'radio', 'allow_other' => true,
                    'options' => [
                        ['value' => 'кратко резюме', 'label' => 'Кратко резюме'],
                        ['value' => 'подробен доклад с препоръки', 'label' => 'Подробен доклад с препоръки'],
                    ],
                ],
            ],
            'description_template' => 'Автоматизиран flow за одит на «{company}»: {audit_type}. Канали/източници: {channels}. Резултат: {format}. Език: български.',
        ],

        'competition' => [
            'match' => ['конкуренц', 'конкурент', 'competition', 'competitor', 'сравнение с'],
            'title' => 'Анализ на конкуренцията',
            'questions' => [
                [
                    'key' => 'competitors', 'text' => 'Кои конкуренти да анализираме?', 'input_type' => 'radio', 'allow_other' => true,
                    'options' => [
                        ['value' => 'намери ги автоматично', 'label' => 'Намери ги автоматично'],
                    ],
                ],
                [
                    'key' => 'aspects', 'text' => 'Какво да сравняваме?', 'input_type' => 'checkbox', 'allow_other' => true,
                    'options' => [
                        ['value' => 'цени', 'label' => 'Цени'],
                        ['value' => 'услуги', 'label' => 'Услуги'],
                        ['value' => 'отзиви', 'label' => 'Отзиви'],
                        ['value' => 'съдържание', 'label' => 'Съдържание'],
                        ['value' => 'SEO', 'label' => 'SEO'],
                    ],
                ],
                [
                    'key' => 'format', 'text' => 'Какъв формат на изхода?', 'input_type' => 'radio', 'allow_other' => true,
                    'options' => [
                        ['value' => 'сравнителна таблица', 'label' => 'Сравнителна таблица'],
                        ['value' => 'подробен доклад', 'label' => 'Подробен доклад'],
                    ],
                ],
            ],
            'description_template' => 'Автоматизиран flow за анализ на конкуренцията на «{company}». Конкуренти: {competitors}. Сравняваме: {aspects}. Формат: {format}. Език: български.',
        ],

        'seo' => [
            'match' => ['seo', 'оптимизация', 'ключови думи', 'класиране в google'],
            'title' => 'SEO оптимизация',
            'questions' => [
                [
                    'key' => 'scope', 'text' => 'Какво оптимизираме?', 'input_type' => 'radio', 'allow_other' => true,
                    'options' => [
                        ['value' => 'целия сайт', 'label' => 'Целия сайт'],
                        ['value' => 'конкретна страница', 'label' => 'Конкретна страница'],
                        ['value' => 'нов текст за публикуване', 'label' => 'Нов текст за публикуване'],
                    ],
                ],
                [
                    'key' => 'focus', 'text' => 'Върху какво да наблегнем?', 'input_type' => 'checkbox', 'allow_other' => true,
                    'options' => [
                        ['value' => 'ключови думи', 'label' => 'Ключови думи'],
                        ['value' => 'мета описания и заглавия', 'label' => 'Мета описания и заглавия'],
                        ['value' => 'съдържание', 'label' => 'Съдържание'],
                        ['value' => 'технически SEO', 'label' => 'Технически SEO'],
                    ],
                ],
                [
                    'key' => 'format', 'text' => 'Какъв формат на изхода?', 'input_type' => 'radio', 'allow_other' => true,
                    'options' => [
                        ['value' => 'списък с препоръки', 'label' => 'Списък с препоръки'],
                        ['value' => 'готов оптимизиран текст', 'label' => 'Готов оптимизиран текст'],
                    ],
                ],
            ],
            'description_template' => 'Автоматизиран flow за SEO оптимизация на «{company}»: {scope}. Фокус: {focus}. Резултат: {format}. Език: български.',
        ],

    ],
];
