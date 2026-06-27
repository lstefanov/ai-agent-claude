<?php

namespace App\Services\Org;

use App\Models\Assistant;
use App\Models\AssistantTask;
use App\Models\Company;
use App\Models\Director;
use App\Models\OrgMember;
use App\Models\OrgVersion;
use App\Models\User;
use App\Services\GeneratorService;
use App\Support\ModelLevel;
use App\Support\PromptData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Мета-планерът (Управителят): проектира организация върху бизнес профила. ПЛАНЕРЪТ
 * ПРЕДЛАГА, КОДЪТ ГАРАНТИРА — LLM дизайнът (персони + приоритети) се закотвя върху доказан
 * blueprint скелет и минава през детерминистична хардинг + by-id материализация.
 */
class OrgPlannerService
{
    /** Резервни български имена по пол (когато LLM не даде). */
    private const NAMES_MALE = ['Мартин', 'Петър', 'Иван', 'Георги', 'Николай', 'Стоян', 'Калоян'];

    private const NAMES_FEMALE = ['Невена', 'Мария', 'Елена', 'Ива', 'Радост', 'Виктория', 'Десислава'];

    public function __construct(
        private OrgBlueprintLibraryService $library,
        private PersonaService $personas,
        private GeneratorService $generator,
    ) {}

    /**
     * Три-фазно (като FlowPlannerService::plan): прайър от библиотеката → дизайн на
     * персони/приоритети (LLM, закотвен на скелета) → нормализиран изход. Връща
     * {directors, assistants, tasks, skill_tree, priorities, blueprint_key}.
     */
    public function proposeOrganization(Company $company, ?callable $onProgress = null, ?string $logToken = null): array
    {
        $onProgress && $onProgress('Избирам прайър от библиотеката…');
        $blueprint = $this->library->bestMatch($company, 1)->first();
        $vertical = (array) ($blueprint?->structure ?? ['directors' => [], 'assistants' => [], 'tasks' => []]);

        $profile = $company->businessProfile;
        $managerPersona = $company->manager?->persona;

        // §smart-composition: маркираните проблеми определят КОИ отдели/директори съществуват
        // (не само персоните). LLM предлага набора, кодът дедуплицира + capва.
        $focusAreas = $profile ? $profile->focusAreas() : [];
        $onProgress && $onProgress('Подбирам отделите според проблемите…');
        $structure = $this->composeStructure($vertical, $focusAreas, $company);

        $onProgress && $onProgress('Управителят композира екипа…');
        $design = $this->designPersonas($structure, $company, $profile, $managerPersona);

        $result = $this->assemble($structure, $design, $profile, $blueprint?->vertical);

        // §3-part understanding: предложените възможности стават приоритети на екипа;
        // проблеми/нужди/възможности пътуват в payload-а за ревю екрана (над екипа).
        foreach ((array) $profile?->opportunities as $opp) {
            $opp = trim((string) $opp);
            if ($opp !== '') {
                $result['priorities'][] = ['title' => $opp, 'rationale' => 'Възможност за растеж'];
            }
        }

        return $result + [
            'blueprint_key' => $blueprint?->vertical,
            'problems' => (array) $profile?->problems,
            'needs' => (array) $profile?->needs,
            'opportunities' => (array) $profile?->opportunities,
        ];
    }

    /**
     * Гаранциите: персони през deriveKnobs, разумен default_star_tier per член (cap по
     * плана), валиден директор→асистент граф (без сираци), dedupe. Код-притежавани ключове
     * (новите се алокират в materialize; реферираните пазят id).
     */
    public function finalizeOrganization(array $proposed, ?OrgVersion $current = null): array
    {
        $cap = null; // плановият таван се прилага в materialize/effectiveStarTier

        $directorKeys = [];
        foreach ($proposed['directors'] as &$d) {
            $d['default_star_tier'] = $this->legalTier($d['default_star_tier'] ?? 'high');
            $d['persona'] = $this->hardenPersona($d['persona'] ?? [], 'director');
            $directorKeys[$d['key']] = true;
        }
        unset($d);

        // Асистент без валиден директор → закача се към първия директор (без сираци).
        $firstDirector = $proposed['directors'][0]['key'] ?? null;
        foreach ($proposed['assistants'] as &$a) {
            if (empty($a['director']) || ! isset($directorKeys[$a['director']])) {
                $a['director'] = $firstDirector;
            }
            $a['default_star_tier'] = $this->legalTier($a['default_star_tier'] ?? 'medium');
            $a['persona'] = $this->hardenPersona($a['persona'] ?? [], 'assistant');
        }
        unset($a);

        // Задача без валиден асистент → закача се към първия асистент.
        $assistantKeys = array_column($proposed['assistants'], 'key');
        $firstAssistant = $assistantKeys[0] ?? null;
        foreach ($proposed['tasks'] as &$t) {
            if (empty($t['assistant']) || ! in_array($t['assistant'], $assistantKeys, true)) {
                $t['assistant'] = $firstAssistant;
            }
            $t['act_mode'] = in_array($t['act_mode'] ?? 'draft', ['draft', 'act', 'mixed'], true) ? $t['act_mode'] : 'draft';
            $t['trigger'] = in_array($t['trigger'] ?? 'manual', ['manual', 'scheduled', 'event'], true) ? $t['trigger'] : 'manual';
            // star_tier само ако умишлено се различава — иначе null (= наследява).
            $t['star_tier'] = isset($t['star_tier']) ? $this->legalTier($t['star_tier']) : null;
        }
        unset($t);

        return $proposed;
    }

    /**
     * Материализира одобрения дизайн (хибридът в действие): идентичностна реконсилация по
     * immutable id, нов OrgVersion + плейсмънти, персони upsert-нати на члена (→ avatar jobs),
     * active_org_version_id, org_events. НЕ генерира flows (Фаза 3).
     */
    public function materialize(Company $company, array $finalized, User $approver): OrgVersion
    {
        return DB::transaction(function () use ($company, $finalized, $approver) {
            $version = OrgVersion::create([
                'company_id' => $company->id,
                'version' => (int) $company->orgVersions()->max('version') + 1,
                'status' => 'approved',
                'summary' => 'Одобрен дизайн на екипа',
                'blueprint_key' => $finalized['blueprint_key'] ?? null,
                'approved_at' => now(),
                'created_by' => $approver->id,
            ]);

            $planCap = $company->subscription?->plan?->maxStarTier();
            $directorMembers = [];

            // Директори → членове + плейсмънти.
            foreach ($finalized['directors'] as $d) {
                $member = $this->upsertMember($company, 'director', $d, $planCap);
                $directorMembers[$d['key']] = $member;

                Director::create([
                    'org_version_id' => $version->id,
                    'org_member_id' => $member->id,
                    // title = РОЛЯ (длъжност), НИКОГА персона името (§9.1) — името живее на persona.
                    'title' => $d['title'] ?? 'Директор',
                    'domain' => $d['domain'] ?? 'operations',
                    'mandate' => $d['mandate'] ?? '',
                ]);
            }

            // Асистенти → членове + плейсмънти (под директора си).
            $assistantMembers = [];
            foreach ($finalized['assistants'] as $a) {
                $member = $this->upsertMember($company, 'assistant', $a, $planCap);
                $assistantMembers[$a['key']] = $member;
                $directorMember = $directorMembers[$a['director']] ?? null;
                $directorPlacement = $directorMember
                    ? Director::where('org_version_id', $version->id)->where('org_member_id', $directorMember->id)->first()
                    : null;

                Assistant::create([
                    'org_version_id' => $version->id,
                    'org_member_id' => $member->id,
                    'director_id' => $directorPlacement?->id ?? Director::where('org_version_id', $version->id)->value('id'),
                    'title' => $a['title'] ?? 'Асистент',
                    'mandate' => $a['mandate'] ?? '',
                ]);
            }

            // Задачи → на асистент-члена (status=proposed, без flow; Фаза 3 генерира).
            foreach ($finalized['tasks'] as $t) {
                $member = $assistantMembers[$t['assistant']] ?? null;
                if (! $member) {
                    continue;
                }
                $policy = $this->personas->runtimePolicy($member);
                AssistantTask::firstOrCreate(
                    ['org_member_id' => $member->id, 'title' => $t['title']],
                    [
                        'description' => $t['description'] ?? $t['title'],
                        'act_mode' => $t['act_mode'] ?? 'draft',
                        'trigger' => $t['trigger'] ?? 'manual',
                        'approval_policy' => (string) ($policy['approval_policy'] ?? 'approve_each'),
                        'star_tier' => $t['star_tier'] ?? null,
                        'status' => 'proposed',
                    ],
                );
            }

            // Пенсионирай членове от предишната активна версия, които ги няма сега.
            $this->retireMissing($company, array_merge(array_values($directorMembers), array_values($assistantMembers)), $version);

            $company->update(['active_org_version_id' => $version->id]);

            return $version;
        });
    }

    // ── вътрешни ──────────────────────────────────────────────────────────

    /** Upsert на член по immutable id (реферирани) или нов с код-алокиран key. */
    private function upsertMember(Company $company, string $kind, array $spec, ?ModelLevel $planCap): OrgMember
    {
        $tier = ModelLevel::from($spec['default_star_tier'] ?? 'medium');
        if ($planCap) {
            $tier = $tier->cappedAt($planCap);
        }

        $displayName = $spec['persona']['name'] ?? $spec['title'] ?? ucfirst($kind);

        if (! empty($spec['id']) && ($existing = OrgMember::where('company_id', $company->id)->find($spec['id']))) {
            // Реферира съществуващ член (keep/modify) — пази идентичност/персона/задачи.
            $existing->update(['display_name' => $displayName, 'default_star_tier' => $tier->value, 'status' => 'active']);
            $member = $existing;
            $isNew = false;
        } else {
            $member = OrgMember::create([
                'company_id' => $company->id,
                'kind' => $kind,
                'key' => OrgMember::allocateKey($company, $kind, $displayName),
                'display_name' => $displayName,
                'default_star_tier' => $tier->value,
                'status' => 'active',
            ]);
            $isNew = true;
        }

        // Персона = upsert на члена (PersonaService::attachTo → avatar при нова демография).
        $this->personas->attachTo($member, $spec['persona'] ?? ['name' => $displayName]);

        $company->orgEvents()->create([
            'type' => $isNew ? 'hire' : 'reassign',
            'org_member_id' => $member->id,
            'summary' => ($isNew ? 'Нает: ' : 'Преназначен: ').$displayName,
            'actor' => 'manager',
        ]);

        return $member;
    }

    /** Пенсионира членове от активната версия, които не са в новата. */
    private function retireMissing(Company $company, array $keptMembers, OrgVersion $newVersion): void
    {
        $keptIds = array_map(fn (OrgMember $m) => $m->id, $keptMembers);

        $company->members()
            ->whereIn('kind', ['director', 'assistant'])
            ->where('status', 'active')
            ->whereNotIn('id', $keptIds)
            ->get()
            ->each(function (OrgMember $m) use ($company) {
                $m->update(['status' => 'retired', 'retired_at' => now()]);
                $company->orgEvents()->create([
                    'type' => 'fire',
                    'org_member_id' => $m->id,
                    'summary' => 'Освободен: '.$m->display_name,
                    'actor' => 'manager',
                ]);
            });
    }

    /** Валиден ModelLevel ключ или fallback. */
    private function legalTier(string $tier): string
    {
        return ModelLevel::tryFrom($tier)?->value ?? 'medium';
    }

    /**
     * Смарт-композиция (§smart-composition + structured-sweep): маркираните области →
     * КОИ отдели съществуват. Изрично маркираните в обзора домейни стават ГАРАНТИРАНИ
     * отдели (детерминистично, от каталога); LLM-ът добавя още за фокус-области, които
     * те не покриват („Друго"/болки). КОДЪТ дедуплицира по домейн, гарантира ядро и
     * налага таван. Без никакъв сигнал → blueprint-ът (capнат). Връща {directors, assistants, tasks}.
     */
    private function composeStructure(array $vertical, array $focusAreas, Company $company): array
    {
        $maxDirectors = (int) config('organization.composition.max_directors', 6);
        $catalog = (array) config('organization.department_catalog', []);

        // Изрично маркираните области от обзора → ГАРАНТИРАНИ отдели (не разчитаме на LLM мапинг).
        $seed = [];
        foreach (($company->businessProfile?->selectedDepartmentDomains() ?? []) as $domain) {
            $spec = $catalog[$domain] ?? [];
            $seed[] = [
                'domain' => $domain,
                'title' => (string) ($spec['title'] ?? 'Директор'),
                'mandate' => (string) ($spec['mandate'] ?? ''),
                'covers' => [$domain],
                'assistants' => (array) ($spec['assistants'] ?? []),
            ];
        }

        // Никакъв сигнал (нито обзор, нито болки) → blueprint-ът както е (capнат).
        if ($seed === [] && $focusAreas === []) {
            return $this->capVerticalStructure($vertical, $maxDirectors);
        }

        // LLM добавя отдели за фокус-области, които изричните не покриват.
        $departments = array_merge($seed, $this->llmComposeDepartments($vertical, $focusAreas, $company, $maxDirectors));

        $structure = $this->hardenComposedStructure($departments, $maxDirectors);

        // Парашут: ако нищо смислено не излезе → blueprint.
        return $structure['directors'] === [] ? $this->capVerticalStructure($vertical, $maxDirectors) : $structure;
    }

    /** LLM предлага отдели, покриващи фокус-областите (към изричните). Празно/грешка → []. */
    private function llmComposeDepartments(array $vertical, array $focusAreas, Company $company, int $maxDirectors): array
    {
        if ($focusAreas === []) {
            return [];
        }

        $menu = $this->candidateMenu($vertical);

        $system = 'Ти си Управителят — съставяш екип от отдели за конкретен бизнес. Дадени са болките/'
            .'фокус-областите на бизнеса и МЕНЮ от възможни отдели. Избери МИНИМАЛНИЯ съгласуван набор '
            .'от отдели (директори), който покрива ВСИЧКИ фокус-области. Един отдел може да покрива няколко '
            .'свързани области — НЕ създавай дублиращи се отдели за един и същ домейн. За всеки отдел дай '
            .'1–2 асистента. Предпочитай каноничните `domain` стойности от менюто; добави нов отдел само '
            .'ако нито един не покрива дадена област. Максимум '.$maxDirectors.' директора. Върни САМО валиден JSON. '
            .PromptData::NO_TECH_TERMS;

        $user = "Бизнес: {$company->name} ({$company->industry}).\n"
            ."Фокус-области (покрий ги всичките):\n".implode("\n", array_map(fn ($f) => '- '.$f, $focusAreas))
            ."\n\nМеню от възможни отдели (domain → роля):\n".json_encode($menu, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        try {
            $raw = $this->generator->chatJson($system, $user, 'org_compose', $this->compositionSchema(), [
                'temperature' => 0.3, 'num_predict' => 2000,
            ]);

            return (array) ($raw['departments'] ?? []);
        } catch (\Throwable $e) {
            Log::info('[OrgPlanner] compose LLM failed: '.$e->getMessage());

            return [];
        }
    }

    /** Меню от възможни отдели: blueprint на бранша + кросвертикален каталог, дедуп по домейн. */
    private function candidateMenu(array $vertical): array
    {
        $menu = [];
        foreach (($vertical['directors'] ?? []) as $d) {
            $domain = (string) ($d['domain'] ?? $d['key'] ?? '');
            if ($domain === '') {
                continue;
            }
            $menu[$domain] = ['domain' => $domain, 'role' => (string) ($d['title'] ?? ''), 'mandate' => (string) ($d['mandate'] ?? '')];
        }
        foreach ((array) config('organization.department_catalog', []) as $domain => $spec) {
            $menu[$domain] ??= ['domain' => $domain, 'role' => (string) ($spec['title'] ?? ''), 'mandate' => (string) ($spec['mandate'] ?? '')];
        }

        return array_values($menu);
    }

    /**
     * Кодът ГАРАНТИРА върху LLM предложението: канонизира домейна, дедуплицира по домейн,
     * гарантира ядро (core_domains), таван на директорите (приоритет: ядро, после повече
     * покрити области), таван на асистентите/директор (≥1). Връща {directors, assistants, tasks}.
     */
    private function hardenComposedStructure(array $departments, int $maxDirectors): array
    {
        $catalog = (array) config('organization.department_catalog', []);
        $maxAssist = (int) config('organization.composition.max_assistants_per_director', 2);
        $coreDomains = (array) config('organization.composition.core_domains', ['operations']);

        // 1) Нормализирай + дедуп по домейн (запази записа с най-много покрити области).
        $byDomain = [];
        foreach ($departments as $dep) {
            $domain = $this->canonicalDomain((string) ($dep['domain'] ?? ''), $catalog);
            if ($domain === '') {
                continue;
            }
            $covers = count((array) ($dep['covers'] ?? []));
            if (isset($byDomain[$domain]) && ($byDomain[$domain]['_covers'] ?? 0) >= $covers) {
                continue;
            }
            $byDomain[$domain] = [
                'domain' => $domain,
                'title' => trim((string) ($dep['title'] ?? '')) ?: (string) ($catalog[$domain]['title'] ?? 'Директор'),
                'mandate' => trim((string) ($dep['mandate'] ?? '')) ?: (string) ($catalog[$domain]['mandate'] ?? ''),
                'assistants' => (array) ($dep['assistants'] ?? []),
                '_covers' => $covers,
            ];
        }

        // 2) Гарантирай ядро (винаги жизнеспособен екип).
        foreach ($coreDomains as $core) {
            if (! isset($byDomain[$core])) {
                $spec = $catalog[$core] ?? ['title' => 'Директор Операции', 'mandate' => '', 'assistants' => []];
                $byDomain[$core] = ['domain' => $core, 'title' => $spec['title'], 'mandate' => $spec['mandate'], 'assistants' => $spec['assistants'] ?? [], '_covers' => 0];
            }
        }

        // 3) Подреди: ядро първо, после по брой покрити области; cap на директорите.
        $ordered = array_values($byDomain);
        usort($ordered, function ($a, $b) use ($coreDomains) {
            $ac = in_array($a['domain'], $coreDomains, true) ? 1 : 0;
            $bc = in_array($b['domain'], $coreDomains, true) ? 1 : 0;

            return $bc <=> $ac ?: ($b['_covers'] <=> $a['_covers']);
        });
        $ordered = array_slice($ordered, 0, max(1, $maxDirectors));

        // 4) Изгради blueprint-форма (директор key = domain; асистент key = domain_N).
        $directors = [];
        $assistants = [];
        foreach ($ordered as $dep) {
            $domain = $dep['domain'];
            $directors[] = [
                'key' => $domain,
                'title' => $dep['title'],
                'domain' => $domain,
                'default_star_tier' => 'high',
                'mandate' => $dep['mandate'],
            ];

            $aSpecs = $dep['assistants'] !== []
                ? $dep['assistants']
                : (array) ($catalog[$domain]['assistants'] ?? [['title' => 'Асистент '.$dep['title'], 'mandate' => $dep['mandate']]]);

            $i = 0;
            foreach (array_slice($aSpecs, 0, max(1, $maxAssist)) as $a) {
                $title = is_array($a) ? trim((string) ($a['title'] ?? '')) : trim((string) $a);
                if ($title === '') {
                    continue;
                }
                $assistants[] = [
                    'key' => $domain.'_'.(++$i),
                    'title' => $title,
                    'director' => $domain,
                    'default_star_tier' => 'medium',
                    'mandate' => is_array($a) ? (string) ($a['mandate'] ?? '') : '',
                ];
            }
            // Гарантирай поне един асистент на директор.
            if ($i === 0) {
                $assistants[] = ['key' => $domain.'_1', 'title' => 'Асистент '.$dep['title'], 'director' => $domain, 'default_star_tier' => 'medium', 'mandate' => $dep['mandate']];
            }
        }

        return ['directors' => $directors, 'assistants' => $assistants, 'tasks' => []];
    }

    /** Канонизира домейн към каталожен ключ (точно или по подниз), иначе slug на върнатото. */
    private function canonicalDomain(string $domain, array $catalog): string
    {
        $domain = mb_strtolower(trim($domain));
        if ($domain === '') {
            return '';
        }
        if (isset($catalog[$domain])) {
            return $domain;
        }
        foreach (array_keys($catalog) as $key) {
            if (str_contains($domain, (string) $key) || str_contains((string) $key, $domain)) {
                return (string) $key;
            }
        }

        // Непознат домейн — задръж го суров (lowercase), за да останат различните отдели
        // различни (без колапс към един ключ); цветът пада към default_function_color.
        return $domain;
    }

    /** Fallback: blueprint-структурата, capната до maxDirectors (+ техните асистенти/задачи). */
    private function capVerticalStructure(array $vertical, int $maxDirectors): array
    {
        $directors = array_slice((array) ($vertical['directors'] ?? []), 0, max(1, $maxDirectors));
        $keepKeys = array_column($directors, 'key');
        $assistants = array_values(array_filter(
            (array) ($vertical['assistants'] ?? []),
            fn ($a) => in_array($a['director'] ?? null, $keepKeys, true),
        ));
        $assistantKeys = array_column($assistants, 'key');
        $tasks = array_values(array_filter(
            (array) ($vertical['tasks'] ?? []),
            fn ($t) => ($t['assistant'] ?? null) === null || in_array($t['assistant'], $assistantKeys, true),
        ));

        return ['directors' => $directors, 'assistants' => $assistants, 'tasks' => $tasks];
    }

    private function compositionSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'departments' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'domain' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'mandate' => ['type' => 'string'],
                            // Кои фокус-области покрива този отдел (за дедуп/приоритет при cap).
                            'covers' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'assistants' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'title' => ['type' => 'string'],
                                        'mandate' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                        ],
                        'required' => ['domain', 'title'],
                    ],
                ],
            ],
            'required' => ['departments'],
        ];
    }

    /** LLM дизайн на персоните + приоритети (закотвен на скелета). Грешка → []. */
    private function designPersonas(array $structure, Company $company, $profile, $managerPersona): array
    {
        $roles = [];
        foreach (($structure['directors'] ?? []) as $d) {
            $roles[] = ['key' => $d['key'], 'role' => 'Директор '.($d['title'] ?? $d['domain'] ?? '')];
        }
        foreach (($structure['assistants'] ?? []) as $a) {
            $roles[] = ['key' => $a['key'], 'role' => 'Асистент '.($a['title'] ?? '')];
        }
        if ($roles === []) {
            return [];
        }

        $managerBlock = $managerPersona ? "Управителят е {$managerPersona->name} ({$managerPersona->tone}). Дизайнът да отразява характера му." : '';
        $pains = implode('; ', (array) ($profile->pain_points ?? []));

        $system = 'Ти си Управителят — проектираш екип от служители. За ВСЯКА подадена роля върни '
            .'персона (име, възраст, пол, произход, бекграунд, тон, кратко био) на български. Имената да са '
            .'реални човешки имена (собствено + фамилно), НИКОГА роля вместо име. Персоните да са разнообразни '
            .'и уместни за бизнеса. Добави 2–3 приоритета, обосновани от болките. '
            .'Върни САМО валиден JSON по схемата. '.PromptData::NO_TECH_TERMS.' '.$managerBlock;
        $user = "Бизнес: {$company->name} ({$company->industry}).\nБолки: ".($pains ?: '—')
            ."\nРоли (използвай точно тези key стойности):\n".json_encode(PromptData::humanize($roles), JSON_UNESCAPED_UNICODE);

        try {
            $raw = $this->generator->chatJson($system, $user, 'org_design', $this->designSchema(), [
                'temperature' => 0.6, 'num_predict' => 2500,
            ]);

            return $raw;
        } catch (\Throwable $e) {
            Log::info('[OrgPlanner] design LLM failed, using defaults: '.$e->getMessage());

            return [];
        }
    }

    private function designSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'members' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                            'age' => ['type' => 'integer'],
                            'gender' => ['type' => 'string'],
                            'ethnicity' => ['type' => 'string'],
                            'background' => ['type' => 'string'],
                            'tone' => ['type' => 'string'],
                            'bio' => ['type' => 'string'],
                            // Стабилни компетентности (умения), НЕ конкретни задачи (§10.2).
                            'skills' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
                'priorities' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'rationale' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'required' => ['members'],
        ];
    }

    /** Сглобява нормализирано предложение от скелет + LLM персони/приоритети + defaults. */
    private function assemble(array $structure, array $design, $profile, ?string $vertical): array
    {
        $byKey = [];
        foreach ((array) ($design['members'] ?? []) as $m) {
            if (filled($m['key'] ?? null)) {
                $byKey[$m['key']] = $m;
            }
        }

        $idx = 0;
        $directors = array_map(function ($d) use ($byKey, &$idx) {
            return $d + ['persona' => $this->personaFor($d, $byKey[$d['key']] ?? null, 'director', $idx++)];
        }, $structure['directors'] ?? []);

        $assistants = array_map(function ($a) use ($byKey, &$idx) {
            return $a + ['persona' => $this->personaFor($a, $byKey[$a['key']] ?? null, 'assistant', $idx++)];
        }, $structure['assistants'] ?? []);

        $tasks = $structure['tasks'] ?? [];

        // Приоритети: LLM или производни от болките.
        $priorities = (array) ($design['priorities'] ?? []);
        if ($priorities === []) {
            foreach (array_slice((array) ($profile->pain_points ?? []), 0, 3) as $pain) {
                $priorities[] = ['title' => 'Адресирай: '.$pain, 'rationale' => 'От болките на бизнеса'];
            }
        }

        return [
            'directors' => $directors,
            'assistants' => $assistants,
            'tasks' => $tasks,
            'skill_tree' => $this->buildSkillTree($directors, $assistants, $tasks),
            'priorities' => $priorities,
        ];
    }

    /** Персона за роля: LLM полета или детерминистичен default. */
    private function personaFor(array $role, ?array $llm, string $kind, int $idx): array
    {
        $gender = $llm['gender'] ?? ($idx % 2 === 0 ? 'мъж' : 'жена');
        $pool = str_contains(mb_strtolower($gender), 'жен') ? self::NAMES_FEMALE : self::NAMES_MALE;
        $name = $llm['name'] ?? $pool[$idx % count($pool)];
        $age = (int) ($llm['age'] ?? (28 + ($idx * 7) % 35));

        return [
            'name' => $name,
            'age' => $age,
            'gender' => $gender,
            'ethnicity' => $llm['ethnicity'] ?? 'българин',
            'background' => $llm['background'] ?? ($role['domain'] ?? $role['title'] ?? ''),
            'tone' => $llm['tone'] ?? ($kind === 'director' ? 'делови, стратегически' : 'отзивчив, изпълнителен'),
            'bio' => $llm['bio'] ?? ($role['mandate'] ?? ''),
            'traits' => $this->personas->seedTraitsFromDemographics($age, $gender, $role['domain'] ?? $role['title'] ?? null),
            'skills' => $this->normalizeSkills($llm['skills'] ?? null, $role, $kind),
        ];
    }

    /** Стабилни компетентности (умения ≠ задачи, §10.2): LLM или детерминистичен fallback. */
    private function normalizeSkills($llm, array $role, string $kind): array
    {
        $skills = array_values(array_filter(array_map(
            fn ($s) => trim((string) $s),
            (array) ($llm ?? [])
        )));

        if ($skills === []) {
            $domain = $role['domain'] ?? $role['title'] ?? '';
            $skills = $kind === 'director'
                ? ['Координация на отдел', 'Приоритизиране', 'Преглед на качеството']
                : array_values(array_filter(['Проучване', $domain ? 'Работа по: '.$domain : null, 'Писане на съдържание']));
        }

        return array_slice($skills, 0, 6);
    }

    /** Хардинг на една персона (имена/възраст в граници + черти). */
    private function hardenPersona(array $persona, string $kind): array
    {
        $persona['name'] = trim((string) ($persona['name'] ?? '')) ?: ucfirst($kind);
        $persona['age'] = max(18, min(90, (int) ($persona['age'] ?? 35)));
        if (empty($persona['traits'])) {
            $persona['traits'] = $this->personas->seedTraitsFromDemographics($persona['age'], $persona['gender'] ?? null, $persona['background'] ?? null);
        }

        return $persona;
    }

    /** Скил-дърво: клон = директор, листа = асистенти със звезди (star_tier). */
    private function buildSkillTree(array $directors, array $assistants, array $tasks): array
    {
        return array_map(function ($d) use ($assistants, $tasks) {
            $kids = array_values(array_filter($assistants, fn ($a) => ($a['director'] ?? null) === $d['key']));

            return [
                'key' => $d['key'],
                'title' => $d['persona']['name'] ?? $d['title'],
                'domain' => $d['domain'] ?? null,
                'star_tier' => $d['default_star_tier'] ?? 'high',
                'assistants' => array_map(fn ($a) => [
                    'key' => $a['key'],
                    'title' => $a['title'],
                    'star_tier' => $a['default_star_tier'] ?? 'medium',
                    'tasks' => array_values(array_filter($tasks, fn ($t) => ($t['assistant'] ?? null) === $a['key'])),
                ], $kids),
            ];
        }, $directors);
    }
}
