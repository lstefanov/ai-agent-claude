<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\OrgMember;
use App\Support\ModelLevel;
use Illuminate\Http\JsonResponse;

/**
 * „Един граф, няколко лещи" (§6): едни и същи director/assistant/task записи, три
 * изгледа (Roster / Skill Tree / Карта) над общ JSON.
 */
class OrgGraphController extends Controller
{
    public function graph(): JsonResponse
    {
        return response()->json($this->buildGraph($this->company()));
    }

    public function roster()
    {
        $company = $this->company();

        return view('client.org.roster', ['graph' => $this->buildGraph($company), 'company' => $company]);
    }

    public function skillTree()
    {
        $company = $this->company();

        return view('client.org.skill-tree', ['graph' => $this->buildGraph($company), 'company' => $company]);
    }

    /** Сглобява org графа от активната версия. */
    private function buildGraph(Company $company): array
    {
        $version = $company->activeOrgVersion;
        $manager = $this->memberCard($company->manager);

        if (! $version) {
            return ['manager' => $manager, 'directors' => [], 'assistants' => [], 'version' => null];
        }

        $directors = $version->directors()->with('orgMember.persona')->get()->map(fn ($d) => [
            'placement_id' => $d->id,
            'title' => $d->title,
            'domain' => $d->domain,
            'mandate' => $d->mandate,
            'member' => $this->memberCard($d->orgMember),
        ])->values();

        $assistants = $version->assistants()->with(['orgMember.persona', 'orgMember.tasks', 'director'])->get()->map(fn ($a) => [
            'placement_id' => $a->id,
            'director_id' => $a->director_id,
            'title' => $a->title,
            'mandate' => $a->mandate,
            'member' => $this->memberCard($a->orgMember),
            'tasks' => $a->orgMember->tasks->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'star_tier' => $t->effectiveStarTier()->value,
                'stars' => $t->effectiveStarTier()->rank() + 1,
                'inherits' => $t->inheritsTier(),
                'status' => $t->status,
                'act_mode' => $t->act_mode,
            ])->values(),
        ])->values();

        return ['manager' => $manager, 'directors' => $directors, 'assistants' => $assistants, 'version' => $version->version];
    }

    /** Лека карта на член за графа/roster. */
    private function memberCard(?OrgMember $member): ?array
    {
        if (! $member) {
            return null;
        }

        $persona = $member->persona;
        $tier = ModelLevel::tryFrom($member->default_star_tier) ?? ModelLevel::Medium;

        return [
            'id' => $member->id,
            'kind' => $member->kind,
            'name' => $persona->name ?? $member->display_name,
            'role' => $member->display_name,
            'age' => $persona->age ?? null,
            'tone' => $persona->tone ?? null,
            'tier' => $member->default_star_tier,
            'stars' => $tier->rank() + 1,
            'traits' => (array) ($persona->traits ?? []),
            'avatar_url' => ($persona && $persona->hasReadyAvatar()) ? $persona->avatar_url : null,
            'avatar_status' => $persona->avatar_status ?? 'pending',
            'initial' => mb_substr($persona->name ?? $member->display_name, 0, 1),
        ];
    }

    private function company(): Company
    {
        return Company::findOrFail((int) session('client_company_id'));
    }
}
