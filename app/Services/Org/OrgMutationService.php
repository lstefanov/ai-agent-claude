<?php

namespace App\Services\Org;

use App\Models\Assistant;
use App\Models\AssistantTask;
use App\Models\Company;
use App\Models\Director;
use App\Models\OrgProposal;
use App\Models\OrgVersion;
use App\Models\Persona;
use App\Models\User;
use App\Support\ModelLevel;
use Illuminate\Support\Str;

/**
 * Динамично наемане/уволнение/преназначаване (§7.3). Снапшотва активната версия в „дизайн"
 * (членовете по immutable id + персони), прилага промяната и материализира нова версия през
 * OrgPlannerService — by-id реконсилацията пази персона/чат/памет/задачи на оцелелите членове.
 */
class OrgMutationService
{
    public function __construct(private OrgPlannerService $planner) {}

    /** Наема нов асистент под първия директор от текущата версия. */
    public function hireFromProposal(Company $company, array $payload, ?User $approver): ?OrgVersion
    {
        $design = $this->snapshotDesign($company);
        if (empty($design['directors'])) {
            return null;
        }

        $title = (string) ($payload['title'] ?? 'Нов асистент');
        $mandate = (string) ($payload['description'] ?? $title);
        $bio = "Грижи се задачите в направление {$title} да бъдат свършени качествено и навреме.";
        if ($mandate !== '' && $mandate !== $title) {
            $bio .= ' '.$mandate;
        }
        $design['assistants'][] = [
            // без 'id' → materialize създава нов член (hire event) + код-алокиран key
            'key' => 'new_'.Str::slug($title).'_'.count($design['assistants']),
            'title' => $title,
            'mandate' => $mandate,
            'director' => $design['directors'][0]['key'],
            'default_star_tier' => 'medium',
            // Пълна персона, не тънка — опит/тон/био да не са 2–3 думи.
            'persona' => [
                'name' => $title,
                'background' => "Практически опит в направление: {$title}. Фокус върху ежедневното изпълнение и качеството на работата.",
                'tone' => 'отзивчив, изпълнителен, прецизен, дружелюбен',
                'bio' => $bio,
            ],
        ];

        return $this->materialize($company, $design, $approver);
    }

    /** Пенсионира член (изважда го от дизайна → materialize го маркира retired) + изчиства висящото. */
    public function fireMember(Company $company, int $memberId, ?User $approver): ?OrgVersion
    {
        $design = $this->snapshotDesign($company);
        $design['directors'] = array_values(array_filter($design['directors'], fn ($d) => ($d['id'] ?? null) !== $memberId));
        $design['assistants'] = array_values(array_filter($design['assistants'], fn ($a) => ($a['id'] ?? null) !== $memberId));

        $version = $this->materialize($company, $design, $approver);
        if ($version !== null) {
            $this->cleanupAfterFire($company, $memberId);
        }

        return $version;
    }

    /**
     * След материализирано съкращение — изчисти висящото на пенсионирания член, за да не остане
     * „осиротяло" в Кутията: висящите му предложения → superseded; нетерминалните му задачи → canceled.
     */
    private function cleanupAfterFire(Company $company, int $memberId): void
    {
        OrgProposal::where('company_id', $company->id)
            ->where('status', 'pending')
            ->get()
            ->filter(fn (OrgProposal $p) => (int) (($p->payload['target_member_id'] ?? $p->payload['org_member_id'] ?? 0)) === $memberId)
            ->each(fn (OrgProposal $p) => $p->update(['status' => 'superseded']));

        AssistantTask::where('org_member_id', $memberId)
            ->whereIn('status', ['proposed', 'generating', 'pending_approval', 'ready'])
            ->update(['status' => 'canceled']);
    }

    /**
     * Сменя мандата на член (директор/асистент) в активната версия — in-place на плейсмънта
     * + одит. Леко (без нова версия): мандатът е поле на плейсмънта, четено при следващото
     * мислене/генерация. Прави одобрените директорски mandate-предложения реални (§Codex).
     */
    public function changeMandate(Company $company, int $memberId, string $mandate, ?User $approver = null): bool
    {
        $version = $company->activeOrgVersion;
        $member = $company->members()->find($memberId);
        $mandate = trim($mandate);
        if (! $version || ! $member || $mandate === '') {
            return false;
        }

        $placement = Director::where('org_version_id', $version->id)->where('org_member_id', $memberId)->first()
            ?? Assistant::where('org_version_id', $version->id)->where('org_member_id', $memberId)->first();
        if (! $placement) {
            return false;
        }
        $placement->update(['mandate' => $mandate]);

        $company->orgEvents()->create([
            'type' => 'mandate_change',
            'org_version_id' => $version->id,
            'org_member_id' => $memberId,
            'summary' => 'Нов мандат: '.$member->display_name,
            'actor' => 'human',
        ]);

        return true;
    }

    /**
     * Повишава/понижава нивото на член — реценообразува задачите му (setDefaultStarTier, без
     * нова версия) + одит. Прави одобрените tier_change-предложения реални (растеж на служителя).
     */
    public function changeTier(Company $company, int $memberId, string $tier, ?User $approver = null): bool
    {
        $level = ModelLevel::tryFrom($tier);
        $member = $company->members()->find($memberId);
        if (! $level || ! $member) {
            return false;
        }
        $member->setDefaultStarTier($level);

        $company->orgEvents()->create([
            'type' => 'tier_change',
            'org_version_id' => $company->active_org_version_id,
            'org_member_id' => $memberId,
            'summary' => 'Ново ниво ('.$level->value.'): '.$member->display_name,
            'actor' => 'human',
        ]);

        return true;
    }

    /**
     * Наема готов генериран асистент под ИЗБРАН директор → нова версия. $assistant = блокът от
     * OrgPlannerService::designSingleAssistant (key/title/director/default_star_tier/mandate/persona).
     * Поправя ограничението на hireFromProposal (твърдо ползва directors[0]).
     */
    public function hireIntoDepartment(Company $company, int $directorMemberId, array $assistant, ?User $approver): ?OrgVersion
    {
        $design = $this->snapshotDesign($company);
        $dir = collect($design['directors'])->firstWhere('id', $directorMemberId);
        if (! $dir) {
            return null;                                   // целевият отдел вече не съществува
        }

        unset($assistant['id']);                           // без id → materialize създава нов член (hire)
        $assistant['director'] = $dir['key'];              // насочи към ИЗБРАНИЯ директор
        $assistant['default_star_tier'] = $assistant['default_star_tier'] ?? 'medium';
        $design['assistants'][] = $assistant;

        return $this->materialize($company, $design, $approver);
    }

    /**
     * Създава нов отдел (директор + асистенти) от готов генериран блок → нова версия.
     * $department = {director:{...}, assistants:[{...}]} от OrgPlannerService::designSingleDepartment.
     * Уникалност на ключа и колизия с домейн → finalizeOrganization (_2 суфикс + пренасочване).
     */
    public function addDepartmentFromDraft(Company $company, array $department, ?User $approver): ?OrgVersion
    {
        $director = $department['director'] ?? null;
        if (! is_array($director) || empty($director)) {
            return null;
        }

        $design = $this->snapshotDesign($company);
        unset($director['id']);

        // Гарантирай уникален ключ за новия директор СПРЯМО снапшота преди merge — иначе
        // value-scoped rewrite-ът във finalizeOrganization (_2 суфикс) би пренасочил асистентите
        // на СЪЩЕСТВУВАЩ отдел със същия ключ към новия. Огледало на клиентския mergeNewDepartment.
        $taken = array_values(array_filter(array_map(fn ($d) => $d['key'] ?? null, $design['directors'])));
        $origKey = $director['key'] ?? ($director['domain'] ?? 'dept');
        $key = $origKey;
        $n = 2;
        while (in_array($key, $taken, true)) {
            $key = $origKey.'_'.$n++;
        }
        $director['key'] = $key;
        $design['directors'][] = $director;
        foreach (($department['assistants'] ?? []) as $a) {
            if (! is_array($a)) {
                continue;
            }
            unset($a['id']);
            if (($a['director'] ?? null) === $origKey) {
                $a['director'] = $key;                     // пренасочи САМО асистентите на новия отдел
            }
            $design['assistants'][] = $a;
        }

        return $this->materialize($company, $design, $approver);
    }

    /**
     * Редактира отдела В АКТИВНАТА версия — in-place на Director-плейсмънта (title/domain/mandate/priorities/color)
     * + одит. Леко (без нова версия). Цветът е явен override (NULL = авто по домейн) и каскадира към
     * директора + асистентите през functionColor(). Огледало на changeMandate.
     */
    public function updateDepartment(Company $company, int $directorMemberId, string $title, string $domain, string $mandate, array $priorities, ?string $color = null, ?User $approver = null): bool
    {
        $version = $company->activeOrgVersion;
        if (! $version) {
            return false;
        }
        $placement = Director::where('org_version_id', $version->id)
            ->where('org_member_id', $directorMemberId)->first();
        if (! $placement) {
            return false;
        }

        $title = trim($title);
        $domain = trim($domain);
        $priorities = array_values(array_filter(
            array_map(fn ($p) => trim((string) $p), $priorities),
            fn ($p) => $p !== '',
        ));
        $placement->update([
            'title' => $title !== '' ? $title : $placement->title,
            'domain' => $domain !== '' ? $domain : $placement->domain,
            'mandate' => trim($mandate),
            'priorities' => $priorities,
            'color' => $color,   // NULL = авто (цвят по домейн)
        ]);

        $company->orgEvents()->create([
            'type' => 'mandate_change',
            'org_version_id' => $version->id,
            'org_member_id' => $directorMemberId,
            'summary' => 'Обновен отдел: '.$placement->title,
            'actor' => 'human',
        ]);

        return true;
    }

    /**
     * Премахва директор И асистентите му → нова версия (retired). Без това finalizeOrganization би
     * пренасочил осиротелите асистенти към първия оцелял директор. Пази поне един отдел.
     */
    public function fireDepartment(Company $company, int $directorMemberId, ?User $approver): ?OrgVersion
    {
        $design = $this->snapshotDesign($company);
        $dir = collect($design['directors'])->firstWhere('id', $directorMemberId);
        if (! $dir || count($design['directors']) <= 1) {
            return null;
        }

        $dirKey = $dir['key'] ?? null;
        $design['directors'] = array_values(array_filter($design['directors'], fn ($d) => ($d['id'] ?? null) !== $directorMemberId));
        $design['assistants'] = array_values(array_filter($design['assistants'], fn ($a) => ($a['director'] ?? null) !== $dirKey));

        return $this->materialize($company, $design, $approver);
    }

    private function materialize(Company $company, array $design, ?User $approver, string $actor = 'human'): OrgVersion
    {
        $approver ??= $company->owner;

        return $this->planner->materialize(
            $company,
            $this->planner->finalizeOrganization($design, $company->activeOrgVersion),
            $approver,
            $actor,
        );
    }

    /** Снапшот на активната версия като „дизайн" (членове по id + персони). */
    public function snapshotDesign(Company $company): array
    {
        $version = $company->activeOrgVersion;
        if (! $version) {
            return ['directors' => [], 'assistants' => [], 'tasks' => [], 'priorities' => []];
        }

        $directors = $version->directors()->with('orgMember.persona')->get()->map(fn ($d) => [
            'id' => $d->orgMember?->id,
            'key' => $d->orgMember?->key,
            'title' => $d->title,
            'domain' => $d->domain,
            'mandate' => $d->mandate,
            'priorities' => (array) ($d->priorities ?? []),   // иначе всяко re-materialize ги изтрива
            'color' => $d->color,                              // цвят-override-ът също трябва да преживее re-materialize
            'default_star_tier' => $d->orgMember?->default_star_tier ?? 'high',
            'persona' => $this->personaSpec($d->orgMember?->persona),
        ])->all();

        $assistants = $version->assistants()->with(['orgMember.persona', 'director.orgMember'])->get()->map(fn ($a) => [
            'id' => $a->orgMember?->id,
            'key' => $a->orgMember?->key,
            'title' => $a->title,
            'mandate' => $a->mandate,
            'director' => $a->director?->orgMember?->key,
            'default_star_tier' => $a->orgMember?->default_star_tier ?? 'medium',
            'persona' => $this->personaSpec($a->orgMember?->persona),
        ])->all();

        // tasks=[] — задачите висят на стабилния член и оцеляват; не ги пресъздаваме.
        return ['blueprint_key' => $version->blueprint_key, 'directors' => $directors, 'assistants' => $assistants, 'tasks' => [], 'priorities' => []];
    }

    /** Персона полета (същата демография → attachTo не регенерира аватара). */
    private function personaSpec(?Persona $persona): array
    {
        if (! $persona) {
            return ['name' => 'Член'];
        }

        return [
            'name' => $persona->name,
            'age' => $persona->age,
            'gender' => $persona->gender,
            'ethnicity' => $persona->ethnicity,
            'background' => $persona->background,
            'education' => $persona->education,
            'bio' => $persona->bio,
            'tone' => $persona->tone,
            'traits' => (array) $persona->traits,
            'skills' => (array) $persona->skills,
            'archetype_key' => $persona->archetype_key,
        ];
    }
}
