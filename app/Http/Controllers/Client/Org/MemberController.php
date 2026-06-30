<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Jobs\Org\DirectorTickJob;
use App\Jobs\Org\GenerateMemberAvatarJob;
use App\Models\OrgMember;
use App\Support\FlowRunStats;
use App\Support\ModelLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Карта на героя (§2.4) + контроли: регенерирай аватар, ниво на члена (повишение/
 * понижение, реценообразува задачите без override), повиши целия отдел (само директор).
 */
class MemberController extends Controller
{
    public function show(OrgMember $member)
    {
        $this->authorizeMember($member);

        $member->load('persona', 'tasks.knowledgeRequirements');

        // Run-статус per задача (последен run + броячи) за секцията „Задачи".
        $taskRuns = FlowRunStats::forFlows(
            $member->tasks->pluck('flow_id')->filter()->unique()->values()->all()
        );

        return view('client.org.member', [
            'member' => $member,
            'placement' => $member->currentPlacement(),
            'taskRuns' => $taskRuns,
        ]);
    }

    /** Пълна персона за inline редактора в roster-а (memberCard не носи gender/ethnicity/skills/archetype). */
    public function persona(OrgMember $member): JsonResponse
    {
        $this->authorizeMember($member);
        $p = $member->persona;

        return response()->json([
            'persona' => [
                'id' => $p?->id,
                'name' => $p?->name ?? $member->display_name,
                'age' => $p?->age,
                'gender' => $p?->gender,
                'ethnicity' => $p?->ethnicity,
                'background' => $p?->background,
                'education' => $p?->education,
                'tone' => $p?->tone,
                'bio' => $p?->bio,
                'traits' => (array) ($p?->traits ?? []),
                'skills' => (array) ($p?->skills ?? []),
                'archetype_key' => $p?->archetype_key,
                'color' => $member->functionColor(),
            ],
        ]);
    }

    /** Ръчно „Регенерирай аватар" → нулира pending + диспечира job. */
    public function regenerateAvatar(OrgMember $member): JsonResponse
    {
        $this->authorizeMember($member);
        $persona = $member->persona;
        if (! $persona) {
            return response()->json(['ok' => false, 'error' => 'Няма персона.'], 422);
        }

        $meta = is_array($persona->avatar_meta) ? $persona->avatar_meta : [];
        $meta['regen_salt'] = ((int) ($meta['regen_salt'] ?? 0)) + 1;

        $persona->update([
            'avatar_status' => 'pending',
            'avatar_meta' => $meta,
        ]);
        GenerateMemberAvatarJob::dispatch($persona->id)->onQueue('org');

        return response()->json(['ok' => true]);
    }

    /** Повишение/понижение на члена → реценообразува задачите му без явен override. */
    public function setTier(Request $request, OrgMember $member): JsonResponse
    {
        $this->authorizeMember($member);
        $tier = ModelLevel::tryFrom((string) $request->input('tier'));
        if (! $tier) {
            return response()->json(['ok' => false, 'error' => 'Невалидно ниво.'], 422);
        }

        $member->setDefaultStarTier($tier);

        return response()->json(['ok' => true, 'tier' => $tier->value]);
    }

    /** Само директор: „повиши целия отдел" → асистентите без override + задачите им. */
    public function promoteDepartment(Request $request, OrgMember $member): JsonResponse
    {
        $this->authorizeMember($member);
        if ($member->kind !== 'director') {
            return response()->json(['ok' => false, 'error' => 'Само за директор.'], 422);
        }
        $tier = ModelLevel::tryFrom((string) $request->input('tier'));
        if (! $tier) {
            return response()->json(['ok' => false, 'error' => 'Невалидно ниво.'], 422);
        }

        $member->applyTierToDepartment($tier);

        return response()->json(['ok' => true, 'tier' => $tier->value]);
    }

    /** Ръчен директорски tick (§4.1) — диспечира DirectorTickJob за плейсмънта му. */
    public function tick(OrgMember $member): JsonResponse
    {
        $this->authorizeMember($member);
        if ($member->kind !== 'director') {
            return response()->json(['ok' => false, 'error' => 'Само за директор.'], 422);
        }

        $placement = $member->currentPlacement();
        if (! $placement) {
            return response()->json(['ok' => false, 'error' => 'Директорът няма активен плейсмънт.'], 422);
        }

        DirectorTickJob::dispatch($placement->id, 'manual')->onQueue('org');

        return response()->json(['ok' => true, 'message' => 'Тикът е пуснат — отчетът ще се появи в събитията.']);
    }

    private function authorizeMember(OrgMember $member): void
    {
        abort_unless($member->company_id === (int) session('client_company_id'), 403);
    }
}
