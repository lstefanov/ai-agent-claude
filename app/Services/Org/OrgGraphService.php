<?php

namespace App\Services\Org;

use App\Models\Company;
use App\Models\OrgMember;
use App\Models\OrgProposal;
use App\Support\FlowRunStats;
use App\Support\ModelLevel;
use Illuminate\Support\Str;

/**
 * „Един граф, няколко лещи" (§6): общ builder за Roster / Skill Tree / Live / Табло.
 */
class OrgGraphService
{
    /** Сглобява org графа от активната версия. */
    public function build(Company $company): array
    {
        $version = $company->activeOrgVersion;
        $manager = $this->memberCard($company->manager);

        if (! $version) {
            return ['manager' => $manager, 'directors' => [], 'assistants' => [], 'version' => null,
                'igniting' => 0, 'decisions' => ['pending_tasks' => 0, 'open_proposals' => 0, 'total' => 0]];
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
                'priorities' => (array) ($d->priorities ?? []),
                'color' => $d->color,   // суров override (NULL=авто) за пикера; member.color е ефективният
                'member' => $this->memberCard($d->orgMember),
                'stats' => [
                    'assistants_count' => $own->count(),
                    'flows_total' => (int) $own->sum(fn ($a) => $a['stats']['flows_total']),
                    'active' => (int) $own->sum(fn ($a) => $a['stats']['active']),
                ],
            ];
        })->values();

        // Видимост (§F): запалване в ход + чакащи решения → банери на roster-а.
        $allTasks = $placements->flatMap(fn ($a) => $a->orgMember?->tasks ?? collect());
        $pendingApproval = $allTasks->where('status', 'pending_approval')->count();
        $igniting = $allTasks->whereIn('status', ['proposed', 'generating'])->count();
        $openProposals = OrgProposal::where('company_id', $company->id)->pending()->count();

        return ['manager' => $manager, 'directors' => $directors, 'assistants' => $assistants, 'version' => $version->version,
            'igniting' => $igniting,
            'decisions' => ['pending_tasks' => $pendingApproval, 'open_proposals' => $openProposals, 'total' => $pendingApproval + $openProposals]];
    }

    /**
     * Леща „Карта на уменията" (§6) — чиста агрегация върху изхода на build():
     * индекс умение→хора, обзорна статистика и skills-forward изглед по отдели.
     * Без нови заявки — преизползва вече заредените member карти.
     */
    public function skillLens(array $graph): array
    {
        $directors = collect($graph['directors'] ?? []);
        $assistants = collect($graph['assistants'] ?? []);
        $dirById = $directors->keyBy('placement_id');

        $slugOf = fn (string $name): string => Str::slug($name) ?: mb_strtolower($name);

        // ── Индекс умение→хора (директори + асистенти; Управителят се пропуска) ──
        $skills = [];          // slug => агрегат
        $membersTotal = 0;
        $membersWithSkills = 0;
        $skillInstances = 0;

        $register = function (?array $member, string $deptTitle) use (&$skills, &$skillInstances, $slugOf) {
            if (! $member) {
                return;
            }
            $seen = [];
            foreach ((array) ($member['skills'] ?? []) as $raw) {
                $name = trim((string) $raw);
                if ($name === '') {
                    continue;
                }
                $slug = $slugOf($name);
                if (isset($seen[$slug])) {
                    continue;   // де-дублиране в рамките на един член
                }
                $seen[$slug] = true;
                $skillInstances++;

                if (! isset($skills[$slug])) {
                    $skills[$slug] = ['name' => $name, 'slug' => $slug, 'count' => 0,
                        'members' => [], 'departments' => [], 'max_stars' => 0];
                }
                $stars = (int) ($member['stars'] ?? 1);
                $skills[$slug]['count']++;
                $skills[$slug]['max_stars'] = max($skills[$slug]['max_stars'], $stars);
                $skills[$slug]['members'][] = [
                    'id' => $member['id'], 'name' => $member['name'], 'color' => $member['color'] ?? 'blue',
                    'stars' => $stars, 'tier' => $member['tier'] ?? null, 'initial' => $member['initial'] ?? null,
                    'avatar_url' => $member['avatar_url'] ?? null, 'role' => $member['role'] ?? '', 'dept' => $deptTitle,
                ];
                if ($deptTitle !== '' && ! in_array($deptTitle, $skills[$slug]['departments'], true)) {
                    $skills[$slug]['departments'][] = $deptTitle;
                }
            }
        };

        foreach ($directors as $d) {
            $membersTotal++;
            if (! empty($d['member']['skills'])) {
                $membersWithSkills++;
            }
            $register($d['member'] ?? null, (string) ($d['title'] ?? ''));
        }
        foreach ($assistants as $a) {
            $membersTotal++;
            if (! empty($a['member']['skills'])) {
                $membersWithSkills++;
            }
            $deptTitle = (string) ($dirById->get($a['director_id'])['title'] ?? '');
            $register($a['member'] ?? null, $deptTitle);
        }

        // Сортиране: най-покрити първо, после по име; + единствен носител и vis payload.
        $skillList = array_values($skills);
        usort($skillList, fn ($x, $y) => ($y['count'] <=> $x['count']) ?: strnatcasecmp($x['name'], $y['name']));
        foreach ($skillList as &$s) {
            $s['single_point'] = $s['count'] === 1;
            $names = array_map(fn ($m) => $m['name'], $s['members']);
            $s['vis'] = ['slug' => $s['slug'], 'max_stars' => $s['max_stars'],
                'search' => mb_strtolower($s['name'].' '.implode(' ', $names))];
        }
        unset($s);

        $summary = [
            'distinct_skills' => count($skillList),
            'members_with_skills' => $membersWithSkills,
            'members_total' => $membersTotal,
            'coverage_pct' => $membersTotal > 0 ? (int) round($membersWithSkills / $membersTotal * 100) : 0,
            'single_point_count' => count(array_filter($skillList, fn ($s) => $s['single_point'])),
            'avg_skills_per_member' => $membersWithSkills > 0 ? round($skillInstances / $membersWithSkills, 1) : 0,
            'top_skills' => array_map(fn ($s) => ['name' => $s['name'], 'slug' => $s['slug'],
                'count' => $s['count'], 'color' => $s['members'][0]['color'] ?? 'blue'], array_slice($skillList, 0, 5)),
        ];

        // ── Изглед „По отдели": хистограма умения + skills-forward възли ──
        $byDepartment = $directors->map(function ($d) use ($assistants, $slugOf) {
            $pid = $d['placement_id'];
            $color = $d['member']['color'] ?? 'blue';
            $own = $assistants->where('director_id', $pid)->values();

            // Хистограма умение→брой (директор + асистенти на отдела).
            $hist = [];
            $addHist = function (?array $member) use (&$hist, $color, $slugOf) {
                $seen = [];
                foreach ((array) ($member['skills'] ?? []) as $raw) {
                    $name = trim((string) $raw);
                    if ($name === '') {
                        continue;
                    }
                    $slug = $slugOf($name);
                    if (isset($seen[$slug])) {
                        continue;
                    }
                    $seen[$slug] = true;
                    $hist[$slug] ??= ['name' => $name, 'slug' => $slug, 'count' => 0, 'color' => $color];
                    $hist[$slug]['count']++;
                }
            };
            $addHist($d['member'] ?? null);
            foreach ($own as $a) {
                $addHist($a['member'] ?? null);
            }
            $histList = array_values($hist);
            usort($histList, fn ($x, $y) => ($y['count'] <=> $x['count']) ?: strnatcasecmp($x['name'], $y['name']));

            $nodes = $own->map(function ($a) use ($d, $slugOf) {
                $m = $a['member'];
                $slugs = [];
                foreach ((array) ($m['skills'] ?? []) as $raw) {
                    $name = trim((string) $raw);
                    if ($name !== '') {
                        $slugs[] = $slugOf($name);
                    }
                }

                return [
                    'member' => $m,
                    'title' => $a['title'],
                    'stats' => $a['stats'],
                    'vis' => [
                        'name' => $m['name'] ?? '',
                        'role' => $a['title'] ?? ($m['role'] ?? ''),
                        'dept' => $d['title'] ?? '',
                        'stars' => (int) ($m['stars'] ?? 1),
                        'skills' => array_values(array_unique($slugs)),
                        'search' => mb_strtolower(($m['name'] ?? '').' '.($a['title'] ?? '').' '
                            .($d['title'] ?? '').' '.implode(' ', (array) ($m['skills'] ?? []))),
                    ],
                ];
            })->values()->all();

            return [
                'placement_id' => $pid,
                'title' => $d['title'],
                'domain' => $d['domain'],
                'color' => $color,
                'director_stars' => (int) ($d['member']['stars'] ?? 1),
                'skill_tags' => $histList,
                'members' => $nodes,
            ];
        })->values()->all();

        return ['skills' => $skillList, 'summary' => $summary, 'by_department' => $byDepartment];
    }

    /** Лека карта на член за графа/roster. */
    public function memberCard(?OrgMember $member): ?array
    {
        if (! $member) {
            return null;
        }

        $persona = $member->persona;
        $tier = ModelLevel::tryFrom($member->default_star_tier) ?? ModelLevel::Medium;

        return [
            'id' => $member->id,
            'kind' => $member->kind,
            'name' => $member->fullName(),
            'role' => $member->roleTitle(),
            'color' => $member->functionColor(),
            'age' => $persona->age ?? null,
            'background' => $persona->background ?? null,
            'tone' => $persona->tone ?? null,
            'bio' => $persona->bio ?? null,
            'tier' => $member->default_star_tier,
            'stars' => $tier->rank() + 1,
            'traits' => (array) ($persona->traits ?? []),
            'skills' => (array) ($persona->skills ?? []),
            'avatar_url' => ($persona && $persona->hasReadyAvatar()) ? $persona->avatar_url : null,
            'avatar_status' => $persona->avatar_status ?? 'pending',
            'initial' => mb_strtoupper(mb_substr($member->fullName(), 0, 1)),
        ];
    }
}
