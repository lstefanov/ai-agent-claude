<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Jobs\Org\OrgReviewJob;
use App\Models\Company;
use App\Models\OrgMember;
use App\Support\FlowRunStats;
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

    /** Текущ поток (Леща 3, §6) — кои асистенти имат активни runs. */
    public function live()
    {
        $company = $this->company();

        return view('client.org.live', ['graph' => $this->buildGraph($company), 'company' => $company]);
    }

    /** Хроника на фирмата (§7.4) — org_events хронологично. */
    public function chronicle()
    {
        $company = $this->company();

        return view('client.org.chronicle', [
            'company' => $company,
            'events' => $company->orgEvents()->latest('id')->take(100)->get(),
        ]);
    }

    /** Ръчно „Пусни ревю сега" (§7.1) → OrgReviewJob. */
    public function reviewNow(): JsonResponse
    {
        $company = $this->company();
        OrgReviewJob::dispatch($company->id)->onQueue('org');

        return response()->json(['ok' => true, 'message' => 'Ревюто е пуснато — предложенията ще се появят в Кутията.']);
    }

    /** Сглобява org графа от активната версия. */
    private function buildGraph(Company $company): array
    {
        $version = $company->activeOrgVersion;
        $manager = $this->memberCard($company->manager);

        if (! $version) {
            return ['manager' => $manager, 'directors' => [], 'assistants' => [], 'version' => null];
        }

        $placements = $version->assistants()->with(['orgMember.persona', 'orgMember.tasks', 'director'])->get();

        // Run-статистики за всички flow-ове на задачите наведнъж (без N+1).
        $flowIds = $placements
            ->flatMap(fn ($a) => $a->orgMember->tasks->pluck('flow_id'))
            ->filter()->unique()->values()->all();
        $flowStats = FlowRunStats::forFlows($flowIds);

        $assistants = $placements->map(function ($a) use ($flowStats) {
            $tasks = $a->orgMember->tasks;
            $taskFlowIds = $tasks->pluck('flow_id')->filter();

            $active = $completed = $failed = 0;
            $lastRunAt = null;
            foreach ($taskFlowIds as $fid) {
                $s = $flowStats[$fid] ?? null;
                if (! $s) {
                    continue;
                }
                $active += $s['active'];
                $completed += $s['completed'];
                $failed += $s['failed'];
                if ($s['last_run_at'] && (! $lastRunAt || $s['last_run_at']->gt($lastRunAt))) {
                    $lastRunAt = $s['last_run_at'];
                }
            }

            return [
                'placement_id' => $a->id,
                'director_id' => $a->director_id,
                'title' => $a->title,
                'mandate' => $a->mandate,
                'member' => $this->memberCard($a->orgMember),
                'tasks' => $tasks->map(fn ($t) => [
                    'id' => $t->id,
                    'title' => $t->title,
                    'star_tier' => $t->effectiveStarTier()->value,
                    'stars' => $t->effectiveStarTier()->rank() + 1,
                    'inherits' => $t->inheritsTier(),
                    'status' => $t->status,
                    'act_mode' => $t->act_mode,
                    'run' => $t->flow_id ? ($flowStats[$t->flow_id]['latest'] ?? null) : null,
                ])->values(),
                'stats' => [
                    'tasks_total' => $tasks->count(),
                    'flows_total' => $taskFlowIds->count(),
                    'active' => $active,
                    'completed' => $completed,
                    'failed' => $failed,
                    'last_run_at' => $lastRunAt,
                ],
            ];
        })->values();

        $directors = $version->directors()->with('orgMember.persona')->get()->map(function ($d) use ($assistants) {
            $own = $assistants->where('director_id', $d->id);

            return [
                'placement_id' => $d->id,
                'title' => $d->title,
                'domain' => $d->domain,
                'mandate' => $d->mandate,
                'member' => $this->memberCard($d->orgMember),
                'stats' => [
                    'assistants_count' => $own->count(),
                    'flows_total' => (int) $own->sum(fn ($a) => $a['stats']['flows_total']),
                    'active' => (int) $own->sum(fn ($a) => $a['stats']['active']),
                ],
            ];
        })->values();

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
            'name' => $member->fullName(),          // две имена (персона), не роля
            'role' => $member->roleTitle(),         // ролята отделно (§9.1)
            'color' => $member->functionColor(),    // цвят = функция/домейн (§10.1)
            'age' => $persona->age ?? null,
            'tone' => $persona->tone ?? null,
            'tier' => $member->default_star_tier,
            'stars' => $tier->rank() + 1,
            'traits' => (array) ($persona->traits ?? []),
            'skills' => (array) ($persona->skills ?? []),
            'avatar_url' => ($persona && $persona->hasReadyAvatar()) ? $persona->avatar_url : null,
            'avatar_status' => $persona->avatar_status ?? 'pending',
            'initial' => mb_strtoupper(mb_substr($member->fullName(), 0, 1)),
        ];
    }

    private function company(): Company
    {
        return Company::findOrFail((int) session('client_company_id'));
    }
}
