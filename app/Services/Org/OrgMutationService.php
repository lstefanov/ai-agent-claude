<?php

namespace App\Services\Org;

use App\Models\Assistant;
use App\Models\Company;
use App\Models\Director;
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

    /** Пенсионира член (изважда го от дизайна → materialize го маркира retired). */
    public function fireMember(Company $company, int $memberId, ?User $approver): ?OrgVersion
    {
        $design = $this->snapshotDesign($company);
        $design['directors'] = array_values(array_filter($design['directors'], fn ($d) => ($d['id'] ?? null) !== $memberId));
        $design['assistants'] = array_values(array_filter($design['assistants'], fn ($a) => ($a['id'] ?? null) !== $memberId));

        return $this->materialize($company, $design, $approver);
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

    private function materialize(Company $company, array $design, ?User $approver): OrgVersion
    {
        $approver ??= $company->owner;

        return $this->planner->materialize(
            $company,
            $this->planner->finalizeOrganization($design, $company->activeOrgVersion),
            $approver,
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
