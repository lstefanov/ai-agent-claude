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

    /** Резервни фамилни имена по пол — гарантират второ име, ако LLM/fallback върне само едно. */
    private const SURNAMES_MALE = ['Иванов', 'Петров', 'Георгиев', 'Димитров', 'Стоянов', 'Колев', 'Тодоров', 'Николов'];

    private const SURNAMES_FEMALE = ['Иванова', 'Петрова', 'Георгиева', 'Димитрова', 'Стоянова', 'Колева', 'Тодорова', 'Николова'];

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
     * Ревю екранът: добавя ЕДИН нов асистент към съществуващ отдел. Един структуриран LLM
     * call на фаза org_design (значи ORG_DESIGN_MODEL). Връща блок във фронт-формата
     * {key,title,director,default_star_tier,mandate,persona}; грешка → детерминистичен fallback.
     *
     * @param  array  $department  {key,title,domain,mandate}
     * @param  array  $existing  текущите асистенти на отдела (за уникален key + не-дублираща роля)
     */
    public function designSingleAssistant(Company $company, array $department, array $existing = []): array
    {
        $domain = (string) ($department['domain'] ?? $department['key'] ?? 'operations');
        $dirKey = (string) ($department['key'] ?? $domain);
        $dirTitle = (string) ($department['title'] ?? '');
        $mandate = (string) ($department['mandate'] ?? '');
        $taken = array_values(array_filter(array_map(fn ($a) => trim((string) ($a['title'] ?? '')), $existing)));

        $system = 'Ти си Управителят — добавяш ЕДИН нов асистент към съществуващ отдел. Върни реалистична '
            .'персона на български: реално човешко име (собствено + ФАМИЛНО, две думи); възраст; пол; произход; '
            .'БЕКГРАУНД — ЦЯЛО изречение (минимум 12 думи) с конкретен опит: години, сфери и постижения, '
            .'обвързани с домейна; ТОН — точно 3–4 прилагателни, разделени със запетая; КРАТКО БИО — '
            .'2–3 пълни изречения (200–400 символа). Дай 3–5 умения. Ролята да ДОПЪЛВА (не дублира) съществуващите. '
            .'Върни САМО валиден JSON по схемата. '.PromptData::NO_TECH_TERMS;
        $user = "Бизнес: {$company->name} ({$company->industry}).\n"
            .'Отдел: '.($dirTitle ?: $domain)." (домейн: {$domain}). Мандат: ".($mandate ?: '—')."\n"
            .'Съществуващи асистенти (не повтаряй ролите им): '.($taken ? implode(', ', $taken) : '—')."\n"
            .'Дай един нов, допълващ асистент.';

        $raw = [];
        try {
            $raw = (array) $this->generator->chatJson($system, $user, 'org_design', $this->singleAssistantSchema(), [
                'temperature' => 0.7, 'num_predict' => 1800,
            ]);
        } catch (\Throwable $e) {
            Log::info('[OrgPlanner] single assistant LLM failed: '.$e->getMessage());
        }

        // Уникален key: {dirKey}_{maxN+1} (по най-високия суфикс сред съществуващите).
        $maxN = 0;
        foreach ($existing as $a) {
            if (preg_match('/_(\d+)$/', (string) ($a['key'] ?? ''), $mm)) {
                $maxN = max($maxN, (int) $mm[1]);
            }
        }

        $role = [
            'domain' => $domain,
            'title' => trim((string) ($raw['title'] ?? '')) ?: ('Асистент '.($dirTitle ?: $domain)),
            'mandate' => trim((string) ($raw['mandate'] ?? '')) ?: $mandate,
        ];
        $persona = $this->hardenPersona($this->personaFor($role, $raw ?: null, 'assistant', count($existing) + 1), 'assistant');

        return [
            'key' => $dirKey.'_'.($maxN + 1),
            'title' => $role['title'],
            'director' => $dirKey,
            'default_star_tier' => 'medium',
            'mandate' => $role['mandate'],
            'persona' => $persona,
        ];
    }

    /**
     * Ревю екранът: добавя ЕДИН нов отдел (директор + ≥min асистента). Каталог-first за познат
     * домейн (детерминизъм); иначе LLM предлага допълващ отдел. Връща {director, assistants}.
     */
    public function designSingleDepartment(Company $company, ?string $domain = null, array $excludeDomains = [], array $custom = []): array
    {
        $catalog = (array) config('organization.department_catalog', []);

        if (filled($custom['name'] ?? null)) {
            // Ръчно: клиентът зададе име+описание → LLM предлага подходящи асистент-роли по брийфа.
            $title = trim((string) $custom['name']);
            $mandate = trim((string) ($custom['description'] ?? ''));
            $dep = [
                'domain' => $this->canonicalDomain($title, $catalog) ?: 'operations',
                'title' => $title,
                'mandate' => $mandate,
                'assistants' => $this->llmComposeAssistantsFromBrief($company, $title, $mandate),
            ];
        } else {
            $canonical = $domain ? $this->canonicalDomain($domain, $catalog) : '';

            if ($canonical !== '' && isset($catalog[$canonical])) {
                $spec = $catalog[$canonical];
                $dep = ['domain' => $canonical, 'title' => (string) $spec['title'], 'mandate' => (string) ($spec['mandate'] ?? ''), 'assistants' => (array) ($spec['assistants'] ?? [])];
            } elseif ($canonical !== '') {
                $dep = ['domain' => $canonical, 'title' => 'Директор '.mb_convert_case($canonical, MB_CASE_TITLE, 'UTF-8'), 'mandate' => '', 'assistants' => []];
            } else {
                $dep = $this->llmComposeOneDepartment($company, $excludeDomains)
                    ?: $this->firstUnusedCatalogDept($catalog, $excludeDomains);
            }
        }

        $structure = $this->buildSingleDepartmentStructure($dep);

        $profile = $company->businessProfile;
        $design = $this->designPersonas($structure, $company, $profile, $company->manager?->persona);
        $assembled = $this->assemble($structure, $design, $profile, null);

        $harden = fn (array $m, string $kind) => array_merge($m, ['persona' => $this->hardenPersona($m['persona'] ?? [], $kind)]);
        $director = isset($assembled['directors'][0]) ? $harden($assembled['directors'][0], 'director') : null;
        $assistants = array_map(fn ($a) => $harden($a, 'assistant'), $assembled['assistants'] ?? []);

        return ['director' => $director, 'assistants' => array_values($assistants)];
    }

    /** LLM предлага ЕДИН нов отдел, допълващ съществуващите. Празно/грешка → []. */
    private function llmComposeOneDepartment(Company $company, array $excludeDomains): array
    {
        $blueprint = $this->library->bestMatch($company, 1)->first();
        $menu = $this->candidateMenu((array) ($blueprint?->structure ?? []));
        $exclude = implode(', ', array_filter($excludeDomains)) ?: '—';

        $system = 'Ти си Управителят — предлагаш ЕДИН НОВ отдел за бизнеса, който ДОПЪЛВА (не дублира) '
            .'съществуващите. Дай domain, заглавие на отдела, мандат и 2–4 асистента с различни роли. '
            .'Предпочитай каноничните `domain` от менюто. Върни САМО валиден JSON по схемата. '.PromptData::NO_TECH_TERMS;
        $user = "Бизнес: {$company->name} ({$company->industry}).\nСъществуващи отдели (НЕ ги повтаряй): {$exclude}.\n"
            ."Меню от възможни отдели (domain → роля):\n".json_encode($menu, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        try {
            $raw = $this->generator->chatJson($system, $user, 'org_compose', $this->compositionSchema(), [
                'temperature' => 0.4, 'num_predict' => 1200,
            ]);

            return (array) ($raw['departments'][0] ?? []);
        } catch (\Throwable $e) {
            Log::info('[OrgPlanner] one-department LLM failed: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Ръчен отдел: LLM предлага 2–max подходящи асистент-роли по въведените от клиента име+описание
     * (без да избира домейн). Връща [{title, mandate}, …]; празно/грешка → [] (buildAssistantsFor подплънява).
     */
    private function llmComposeAssistantsFromBrief(Company $company, string $title, string $mandate): array
    {
        $maxAssist = (int) config('organization.composition.max_assistants_per_director', 4);

        $system = 'Ти си Управителят — клиентът създава нов отдел и дава ИМЕ и ОПИСАНИЕ. Предложи 2–'.$maxAssist
            .' асистент-роли с РАЗЛИЧНИ, допълващи се отговорности, които покриват описанието на отдела. '
            .'За всяка роля дай кратко заглавие (длъжност, не име) и мандат (1 изречение какво върши). '
            .'Върни САМО валиден JSON по схемата. '.PromptData::NO_TECH_TERMS;
        $user = "Бизнес: {$company->name} ({$company->industry}).\nНов отдел: {$title}.\nОписание: ".($mandate !== '' ? $mandate : '—');

        try {
            $raw = $this->generator->chatJson($system, $user, 'org_compose', $this->briefAssistantsSchema(), [
                'temperature' => 0.5, 'num_predict' => 1000,
            ]);

            return array_values(array_filter(
                (array) ($raw['assistants'] ?? []),
                fn ($a) => is_array($a) && trim((string) ($a['title'] ?? '')) !== '',
            ));
        } catch (\Throwable $e) {
            Log::info('[OrgPlanner] brief assistants LLM failed: '.$e->getMessage());

            return [];
        }
    }

    private function briefAssistantsSchema(): array
    {
        return $this->strict([
            'type' => 'object',
            'properties' => [
                'assistants' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string', 'description' => 'Кратка длъжност/роля на асистента (не име).'],
                            'mandate' => ['type' => 'string', 'description' => 'Какво върши този асистент — 1 изречение.'],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /** Първият каталожен отдел, който още не е използван (fallback за нов отдел). */
    private function firstUnusedCatalogDept(array $catalog, array $excludeDomains): array
    {
        $exclude = array_map(fn ($d) => mb_strtolower(trim((string) $d)), $excludeDomains);
        foreach ($catalog as $domain => $spec) {
            if (! in_array($domain, $exclude, true)) {
                return ['domain' => $domain, 'title' => (string) ($spec['title'] ?? 'Директор'), 'mandate' => (string) ($spec['mandate'] ?? ''), 'assistants' => (array) ($spec['assistants'] ?? [])];
            }
        }

        return ['domain' => 'operations', 'title' => 'Директор Операции', 'mandate' => '', 'assistants' => []];
    }

    private function singleAssistantSchema(): array
    {
        return $this->strict([
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Кратка длъжност/роля на асистента (не име).'],
                'mandate' => ['type' => 'string', 'description' => 'Какво върши този асистент — 1 изречение.'],
                'name' => ['type' => 'string', 'description' => 'Реално българско име: собствено + ФАМИЛНО (две думи).'],
                'age' => ['type' => 'integer'],
                'gender' => ['type' => 'string', 'description' => 'мъж или жена.'],
                'ethnicity' => ['type' => 'string'],
                'background' => ['type' => 'string', 'description' => 'ЦЯЛО изречение (минимум 12 думи): конкретен опит — години, сфери и постижения, обвързани с домейна. Пример: „Над 6 години в обслужване на клиенти за онлайн магазини, с фокус върху задържане и удовлетвореност.".'],
                'tone' => ['type' => 'string', 'description' => 'Точно 3–4 прилагателни, разделени със запетая (напр. „отзивчив, изпълнителен, прецизен, дружелюбен").'],
                'bio' => ['type' => 'string', 'description' => '2–3 пълни изречения (200–400 символа) за характера и подхода.'],
                'skills' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ]);
    }

    /**
     * Гаранциите: персони през deriveKnobs, разумен default_star_tier per член (cap по
     * плана), валиден директор→асистент граф (без сираци), dedupe. Код-притежавани ключове
     * (новите се алокират в materialize; реферираните пазят id).
     */
    public function finalizeOrganization(array $proposed, ?OrgVersion $current = null): array
    {
        $cap = null; // плановият таван се прилага в materialize/effectiveStarTier

        // Кодът ГАРАНТИРА уникални директор-ключове (човек/фронтенд може да добави отдел с
        // дублиращ/празен key → иначе materialize презаписва член). Пренасочваме асистентите.
        $seenKeys = [];
        foreach ($proposed['directors'] as &$d) {
            $base = trim((string) ($d['key'] ?? $d['domain'] ?? 'dept')) ?: 'dept';
            $key = $base;
            $n = 2;
            while (isset($seenKeys[$key])) {
                $key = $base.'_'.$n++;
            }
            $seenKeys[$key] = true;
            $old = $d['key'] ?? null;
            if (is_string($old) && $old !== '' && $old !== $key) {
                foreach ($proposed['assistants'] as &$a) {
                    if (($a['director'] ?? null) === $old) {
                        $a['director'] = $key;
                    }
                }
                unset($a);
            }
            $d['key'] = $key;
        }
        unset($d);

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
            $actMode = $t['act_mode'] ?? 'draft';
            $t['act_mode'] = in_array($actMode, ['draft', 'act', 'mixed'], true) ? $actMode : 'draft';
            $trigger = $t['trigger'] ?? 'manual';
            $t['trigger'] = in_array($trigger, ['manual', 'scheduled', 'event'], true) ? $trigger : 'manual';
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
                    'priorities' => array_values(array_filter(array_map(fn ($p) => trim((string) $p), (array) ($d['priorities'] ?? [])))),
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
            .'свързани области — НЕ създавай дублиращи се отдели за един и същ домейн. Всеки отдел е '
            .'достатъчно сложен → дай 2–4 асистента (ПОНЕ 2) с РАЗЛИЧНИ, допълващи се роли. '
            .'Предпочитай каноничните `domain` стойности от менюто; добави нов отдел само '
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
                : (array) ($catalog[$domain]['assistants'] ?? []);
            foreach ($this->buildAssistantsFor($domain, $aSpecs, $dep['title'], $dep['mandate'], $catalog) as $a) {
                $assistants[] = $a;
            }
        }

        return ['directors' => $directors, 'assistants' => $assistants, 'tasks' => []];
    }

    /**
     * Изгражда асистентите за един отдел (key = domain_N): cap до max, подплънка до min —
     * първо от каталога, после с обозначени генерични роли. Споделя се от композицията и
     * от добавянето на нов отдел (designSingleDepartment).
     */
    private function buildAssistantsFor(string $domain, array $aSpecs, string $title, string $mandate, array $catalog): array
    {
        $minAssist = (int) config('organization.composition.min_assistants_per_director', 2);
        $maxAssist = (int) config('organization.composition.max_assistants_per_director', 4);

        $assistants = [];
        $i = 0;
        foreach (array_slice($aSpecs, 0, max(1, $maxAssist)) as $a) {
            $t = is_array($a) ? trim((string) ($a['title'] ?? '')) : trim((string) $a);
            if ($t === '') {
                continue;
            }
            $assistants[] = [
                'key' => $domain.'_'.(++$i),
                'title' => $t,
                'director' => $domain,
                'default_star_tier' => 'medium',
                'mandate' => is_array($a) ? (string) ($a['mandate'] ?? '') : '',
            ];
        }

        $catalogSpecs = (array) ($catalog[$domain]['assistants'] ?? []);
        while ($i < $minAssist) {
            $spec = $catalogSpecs[$i] ?? null;
            $t = is_array($spec) ? trim((string) ($spec['title'] ?? '')) : '';
            $m = is_array($spec) ? (string) ($spec['mandate'] ?? $mandate) : $mandate;
            if ($t === '') {
                $t = 'Асистент '.$title.' '.($i + 1);
            }
            $assistants[] = [
                'key' => $domain.'_'.(++$i),
                'title' => $t,
                'director' => $domain,
                'default_star_tier' => 'medium',
                'mandate' => $m,
            ];
        }

        return $assistants;
    }

    /**
     * Скелет за ЕДИН отдел (директор + ≥min асистента), БЕЗ core-гаранцията на
     * hardenComposedStructure (иначе нов не-операционен отдел би се заменил с „Операции").
     */
    private function buildSingleDepartmentStructure(array $dep): array
    {
        $catalog = (array) config('organization.department_catalog', []);
        $domain = $this->canonicalDomain((string) ($dep['domain'] ?? ''), $catalog) ?: 'operations';
        $title = trim((string) ($dep['title'] ?? '')) ?: (string) ($catalog[$domain]['title'] ?? 'Директор');
        $mandate = trim((string) ($dep['mandate'] ?? '')) ?: (string) ($catalog[$domain]['mandate'] ?? '');

        $aSpecs = ($dep['assistants'] ?? []) !== [] ? (array) $dep['assistants'] : (array) ($catalog[$domain]['assistants'] ?? []);

        return [
            'directors' => [['key' => $domain, 'title' => $title, 'domain' => $domain, 'default_star_tier' => 'high', 'mandate' => $mandate]],
            'assistants' => $this->buildAssistantsFor($domain, $aSpecs, $title, $mandate, $catalog),
            'tasks' => [],
        ];
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

    /**
     * Прави JSON Schema стриктно-съвместима с OpenAI Structured Outputs (`strict: true`):
     * рекурсивно сетва `additionalProperties: false` + `required` = ВСИЧКИ ключове на всеки
     * обект. БЕЗ това OpenAI връща 400 и org_design call-ът пада към тънки fallback-персони
     * (точно „опит/тон/био по 2–3 думи"). Идемпотентно за вече съвместими схеми.
     */
    private function strict(array $schema): array
    {
        if (($schema['type'] ?? null) === 'object' && isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $k => $prop) {
                $schema['properties'][$k] = $this->strict((array) $prop);
            }
            $schema['required'] = array_keys($schema['properties']);
            $schema['additionalProperties'] = false;
        } elseif (($schema['type'] ?? null) === 'array' && isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->strict((array) $schema['items']);
        }

        return $schema;
    }

    private function compositionSchema(): array
    {
        return $this->strict([
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
                    ],
                ],
            ],
        ]);
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
        $directorKeys = array_values(array_filter(array_map(fn ($d) => (string) ($d['key'] ?? ''), (array) ($structure['directors'] ?? []))));

        $system = 'Ти си Управителят — проектираш екип от служители. За ВСЯКА подадена роля върни '
            .'персона на български със следните полета: ИМЕ — реално човешко име (собствено + ФАМИЛНО, '
            .'две думи), НИКОГА роля вместо име; ВЪЗРАСТ; ПОЛ; ПРОИЗХОД; БЕКГРАУНД — ЦЯЛО изречение '
            .'(минимум 12 думи) с конкретен опит: години, сфери/компании и постижения, обвързани с домейна '
            .'(напр. „Над 8 години в дигитален маркетинг за e-commerce, с управление на бюджети и ръст на '
            .'продажбите."); ТОН — точно 3–4 прилагателни, разделени със запетая (напр. „делови, прецизен, '
            .'спокоен, прям"); КРАТКО БИО — 2–3 пълни изречения (200–400 символа) за характера, какво го '
            .'движи и как подхожда; 3–5 стабилни умения. '
            .'Персоните да са разнообразни и уместни за бизнеса. Добави 2–3 приоритета, обосновани от болките. '
            .'За ВСЕКИ ОТДЕЛ (директор) върни в `departments` 2–4 конкретни приоритета на ОТДЕЛА, '
            .'обвързани с болките и домейна му — какво да гони този отдел (различни за всеки отдел, '
            .'НЕ преписвай общите приоритети). Ползвай точния `key` на директора. '
            .'Върни САМО валиден JSON по схемата. '.PromptData::NO_TECH_TERMS.' '.$managerBlock;
        $user = "Бизнес: {$company->name} ({$company->industry}).\nБолки: ".($pains ?: '—')
            ."\nРоли (използвай точно тези key стойности):\n".json_encode(PromptData::humanize($roles), JSON_UNESCAPED_UNICODE)
            ."\nДиректорски keys за `departments` (приоритети по отдел):\n".json_encode($directorKeys, JSON_UNESCAPED_UNICODE);

        try {
            $raw = $this->generator->chatJson($system, $user, 'org_design', $this->designSchema(), [
                'temperature' => 0.6, 'num_predict' => 4000,
            ]);

            return $raw;
        } catch (\Throwable $e) {
            Log::info('[OrgPlanner] design LLM failed, using defaults: '.$e->getMessage());

            return [];
        }
    }

    private function designSchema(): array
    {
        return $this->strict([
            'type' => 'object',
            'properties' => [
                'members' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'name' => ['type' => 'string', 'description' => 'Реално човешко име: собствено + ФАМИЛНО (две думи). Никога роля.'],
                            'age' => ['type' => 'integer'],
                            'gender' => ['type' => 'string'],
                            'ethnicity' => ['type' => 'string'],
                            'background' => ['type' => 'string', 'description' => 'ЦЯЛО изречение (минимум 12 думи): конкретен опит — години, сфери/компании и постижения, обвързани с домейна. Пример: „Над 8 години в дигитален маркетинг за e-commerce, с управление на бюджети и ръст на продажбите.".'],
                            'tone' => ['type' => 'string', 'description' => 'Точно 3–4 прилагателни, разделени със запетая (напр. „делови, прецизен, спокоен, прям").'],
                            'bio' => ['type' => 'string', 'description' => '2–3 пълни изречения (200–400 символа) за характера, какво го движи и как подхожда.'],
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
                // Приоритети ПО ОТДЕЛ (какви приоритети има отделът) — отделно от org-level
                // priorities. key = точният key на директора, за да ги закачим в assemble().
                'departments' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string', 'description' => 'Точният key на директора/отдела от подадените роли.'],
                            'priorities' => [
                                'type' => 'array',
                                'items' => ['type' => 'string', 'description' => 'Кратък бизнес-приоритет на отдела (3–7 думи).'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
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

        // Приоритети по отдел (key = директорски key) от LLM-а; в assemble ги закачаме на директора.
        $depByKey = [];
        foreach ((array) ($design['departments'] ?? []) as $dep) {
            if (filled($dep['key'] ?? null)) {
                $depByKey[$dep['key']] = array_values(array_filter(array_map(fn ($p) => trim((string) $p), (array) ($dep['priorities'] ?? []))));
            }
        }

        // hardenPersona (→ ensureFullName) и тук, не само в finalizeOrganization — за да са
        // имената ≥2 думи още на ПРЕГЛЕД-екрана, дори когато LLM-дизайнът падне към fallback.
        $idx = 0;
        $directors = array_map(function ($d) use ($byKey, $depByKey, $profile, &$idx) {
            return $d + [
                'persona' => $this->hardenPersona($this->personaFor($d, $byKey[$d['key']] ?? null, 'director', $idx++), 'director'),
                'priorities' => $this->departmentPriorities($d, $depByKey, $profile),
            ];
        }, $structure['directors'] ?? []);

        $assistants = array_map(function ($a) use ($byKey, &$idx) {
            return $a + ['persona' => $this->hardenPersona($this->personaFor($a, $byKey[$a['key']] ?? null, 'assistant', $idx++), 'assistant')];
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

    /**
     * Приоритети на отдела (какви приоритети има): LLM списъка ако е непразен, иначе
     * детерминистичен fallback от mandate (първото изречение) + до 2 болки; cap 4, никога празно.
     */
    private function departmentPriorities(array $d, array $depByKey, $profile): array
    {
        $priorities = $depByKey[(string) ($d['key'] ?? '')] ?? [];

        if ($priorities === []) {
            $mandate = trim((string) ($d['mandate'] ?? ''));
            if ($mandate !== '') {
                $first = trim((string) (preg_split('/(?<=[.!?])\s+/u', $mandate)[0] ?? $mandate));
                if ($first !== '') {
                    $priorities[] = $first;
                }
            }
            foreach (array_slice((array) ($profile->pain_points ?? []), 0, 2) as $pain) {
                $pain = trim((string) $pain);
                if ($pain !== '') {
                    $priorities[] = $pain;
                }
            }
            if ($priorities === []) {
                $priorities[] = 'Подобри ключовите резултати на отдела';
            }
        }

        return array_values(array_slice(array_unique($priorities), 0, 4));
    }

    /** Персона за роля: LLM полета или детерминистичен default. */
    private function personaFor(array $role, ?array $llm, string $kind, int $idx): array
    {
        $gender = $llm['gender'] ?? ($idx % 2 === 0 ? 'мъж' : 'жена');
        $pool = str_contains(mb_strtolower($gender), 'жен') ? self::NAMES_FEMALE : self::NAMES_MALE;
        $name = $llm['name'] ?? $pool[$idx % count($pool)];
        $age = (int) ($llm['age'] ?? (28 + ($idx * 7) % 35));

        // Резервни стойности (само при пълен LLM провал) — пълни изречения, НЕ голи 2–3 думи.
        $label = trim((string) ($role['title'] ?? $role['domain'] ?? ''))
            ?: ($kind === 'director' ? 'управление на отдела' : 'възложените задачи');
        $mandate = trim((string) ($role['mandate'] ?? ''));
        $bgFallback = $kind === 'director'
            ? "Дългогодишен управленски опит в направление: {$label}. Водене на екип и отговорност за резултатите на отдела."
            : "Практически опит в направление: {$label}. Фокус върху ежедневното изпълнение и качеството на работата.";
        $bioFallback = $kind === 'director'
            ? trim("{$name} отговаря за отдела и неговите приоритети (направление: {$label}). ".($mandate ?: 'Подхожда стратегически и държи екипа фокусиран върху важното.'))
            : trim("{$name} изпълнява задачите в направление: {$label} — качествено и навреме. ".($mandate ?: 'Подхожда отговорно, организирано и довежда нещата докрай.'));

        return [
            'name' => $name,
            'age' => $age,
            'gender' => $gender,
            'ethnicity' => $llm['ethnicity'] ?? 'българин',
            'background' => $llm['background'] ?? $bgFallback,
            'tone' => $llm['tone'] ?? ($kind === 'director'
                ? 'делови, стратегически, решителен, спокоен'
                : 'отзивчив, изпълнителен, прецизен, дружелюбен'),
            'bio' => $llm['bio'] ?? $bioFallback,
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
        $persona = $this->ensureFullName($persona);
        $persona['name'] = trim((string) ($persona['name'] ?? '')) ?: ucfirst($kind);
        $persona['age'] = max(18, min(90, (int) ($persona['age'] ?? 35)));
        if (empty($persona['traits'])) {
            $persona['traits'] = $this->personas->seedTraitsFromDemographics($persona['age'], $persona['gender'] ?? null, $persona['background'] ?? null);
        }

        return $persona;
    }

    /**
     * Гарантира собствено + фамилно име. Ако LLM/fallback върне само едно (или нищо),
     * детерминистично добавя склонено по пол фамилно (избор по crc32 на името — стабилно
     * при повторен finalize). Покрива главния път и новодобавените членове (през hardenPersona).
     */
    private function ensureFullName(array $persona): array
    {
        $name = trim((string) ($persona['name'] ?? ''));
        if ($name !== '' && count(preg_split('/\s+/u', $name)) >= 2) {
            return $persona;   // вече е поне две думи
        }

        $female = str_contains(mb_strtolower((string) ($persona['gender'] ?? '')), 'жен');
        if ($name === '') {
            $name = $female ? self::NAMES_FEMALE[0] : self::NAMES_MALE[0];
        }
        $pool = $female ? self::SURNAMES_FEMALE : self::SURNAMES_MALE;
        $surname = $pool[abs(crc32($name)) % count($pool)];
        $persona['name'] = $name.' '.$surname;

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
