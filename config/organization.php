<?php

return [
    // Управителят/Директорите — нивото и планер-провайдърът по подразбиране.
    'manager' => [
        'level' => env('ORG_MANAGER_LEVEL', 'ultra'),   // ModelLevel за Управителя/Директорите
        'provider' => env('ORG_PLANNER_PROVIDER', ''),     // празно → planner default
        'model' => env('ORG_PLANNER_MODEL', ''),
        'max_questions' => (int) env('ORG_INTERVIEW_MAX_QUESTIONS', 8),
    ],
    'director' => ['default_level' => env('ORG_DIRECTOR_LEVEL', 'high')],
    'persona' => ['portraits' => (bool) env('ORG_PERSONA_PORTRAITS', true)],
    // act (write конектори) са HARD-DISABLED под preview ClientAuth (draft-first),
    // докато няма реален auth — §B2 / Фаза 5. false → act задачите дават „чернова
    // на действието" без реален страничен ефект; реалният auth е предусловие за true.
    'act' => ['enabled' => (bool) env('ORG_ACT_ENABLED', false)],
    'seed_verticals' => ['fitness', 'restaurant', 'services'],   // §11 — 3 seed вертикали
];
