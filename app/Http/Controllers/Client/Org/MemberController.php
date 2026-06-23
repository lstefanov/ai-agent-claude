<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Jobs\Org\GenerateMemberAvatarJob;
use App\Models\OrgMember;
use App\Services\Org\AvatarService;
use App\Support\ModelLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Карта на героя (§2.4) + контроли: регенерирай аватар, ниво на члена (повишение/
 * понижение, реценообразува задачите без override), повиши целия отдел (само директор).
 */
class MemberController extends Controller
{
    public function show(OrgMember $member, AvatarService $avatars)
    {
        $this->authorizeMember($member);

        // Best-effort: ако аватарът виси и ComfyUI вече е наличен → регенерирай (§B4).
        if (in_array($member->persona?->avatar_status, ['pending', 'failed'], true)) {
            $avatars->redispatchPending($member->company);
        }

        return view('client.org.member', [
            'member' => $member->load('persona', 'tasks'),
            'placement' => $member->currentPlacement(),
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

        $persona->update(['avatar_status' => 'pending']);
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

    private function authorizeMember(OrgMember $member): void
    {
        abort_unless($member->company_id === (int) session('client_company_id'), 403);
    }
}
