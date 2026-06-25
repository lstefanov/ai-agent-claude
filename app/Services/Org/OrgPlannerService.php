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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Мета-планерът (Управителят): проектира организация върху бизнес профила. ПЛАНЕРЪТ
 * ПРЕДЛАГА, КОДЪТ ГАРАНТИРА — LLM дизайнът (персони + куестове) се закотвя върху доказан
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
     * персони/куестове (LLM, закотвен на скелета) → нормализиран изход. Връща
     * {directors, assistants, tasks, skill_tree, quests, blueprint_key}.
     */
    public function proposeOrganization(Company $company, ?callable $onProgress = null, ?string $logToken = null): array
    {
        $onProgress && $onProgress('Избирам прайър от библиотеката…');
        $blueprint = $this->library->bestMatch($company, 1)->first();
        $structure = (array) ($blueprint?->structure ?? ['directors' => [], 'assistants' => [], 'tasks' => []]);

        $profile = $company->businessProfile;
        $managerPersona = $company->manager?->persona;

        $onProgress && $onProgress('Управителят композира екипа…');
        $design = $this->designPersonas($structure, $company, $profile, $managerPersona);

        return $this->assemble($structure, $design, $profile, $blueprint?->vertical) + [
            'blueprint_key' => $blueprint?->vertical,
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
                    'title' => $d['persona']['name'] ?? $d['title'] ?? 'Директор',
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
                AssistantTask::firstOrCreate(
                    ['org_member_id' => $member->id, 'title' => $t['title']],
                    [
                        'description' => $t['description'] ?? $t['title'],
                        'act_mode' => $t['act_mode'] ?? 'draft',
                        'trigger' => $t['trigger'] ?? 'manual',
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

    /** LLM дизайн на персоните + куестове (закотвен на скелета). Грешка → []. */
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

        $system = 'Ти си Управителят — проектираш екип от персонажи. За ВСЯКА подадена роля върни '
            .'персона (име, възраст, пол, произход, бекграунд, тон, кратко био) на български. Персоните '
            .'да са разнообразни и уместни за бизнеса. Добави 2–3 куеста (приоритети), обосновани от болките. '
            .'Върни САМО валиден JSON по схемата. '.$managerBlock;
        $user = "Бизнес: {$company->name} ({$company->industry}).\nБолки: ".($pains ?: '—')
            ."\nРоли (използвай точно тези key стойности):\n".json_encode($roles, JSON_UNESCAPED_UNICODE);

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
                        ],
                    ],
                ],
                'quests' => [
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

    /** Сглобява нормализирано предложение от скелет + LLM персони/куестове + defaults. */
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

        // Куестове: LLM или производни от болките.
        $quests = (array) ($design['quests'] ?? []);
        if ($quests === []) {
            foreach (array_slice((array) ($profile->pain_points ?? []), 0, 3) as $pain) {
                $quests[] = ['title' => 'Адресирай: '.$pain, 'rationale' => 'От болките на бизнеса'];
            }
        }

        return [
            'directors' => $directors,
            'assistants' => $assistants,
            'tasks' => $tasks,
            'skill_tree' => $this->buildSkillTree($directors, $assistants, $tasks),
            'quests' => $quests,
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
        ];
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
