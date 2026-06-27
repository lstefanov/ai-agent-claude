<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Jobs\Org\ProposeOrganizationJob;
use App\Models\Company;
use App\Models\User;
use App\Services\Org\OrgPlannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Дизайн на екипа (§2.5): Управителят предлага → човек ревюира/одобрява. Одобрението
 * материализира нов org_version. Кодът ГАРАНТИРА — финализира повторно дори човек-редактиран дизайн.
 */
class DesignController extends Controller
{
    /** Ревю екран — предлага и показва екипа за одобрение. */
    public function review()
    {
        $company = $this->company();
        if (! $company->manager) {
            return redirect()->route('client.org.casting');
        }

        return view('client.org.design-review', [
            'manager' => $company->manager,
            'hasActive' => (bool) $company->active_org_version_id,
        ]);
    }

    /** Стартира фоновия дизайн → token за поллинг. */
    public function propose(): JsonResponse
    {
        $company = $this->company();
        $token = (string) Str::uuid();

        Cache::put("org_design_{$token}", ['status' => 'pending', 'stage' => 'Стартиране…', 'updated_at' => now()->timestamp], now()->addMinutes(20));
        ProposeOrganizationJob::dispatch($company->id, $token)->onQueue('org');

        return response()->json(['token' => $token]);
    }

    public function status(string $token): JsonResponse
    {
        $result = Cache::get("org_design_{$token}");
        if (! $result) {
            return response()->json(['status' => 'expired', 'error' => 'Токенът изтече. Стартирай отново.'], 404);
        }

        return response()->json($result);
    }

    /** Одобрение → материализация на нов org_version (по immutable id). */
    public function approve(Request $request, OrgPlannerService $planner): JsonResponse
    {
        $company = $this->company();
        $design = (array) $request->input('design', []);
        if (empty($design['directors'])) {
            return response()->json(['ok' => false, 'error' => 'Празен дизайн.'], 422);
        }

        // Кодът гарантира — финализирай повторно (дори върху редактиран от човек дизайн).
        $finalized = $planner->finalizeOrganization($design, $company->activeOrgVersion);
        $approver = User::find(session('client_user_id')) ?? $company->owner;

        $version = $planner->materialize($company, $finalized, $approver);

        return response()->json([
            'ok' => true,
            'version' => $version->version,
            'redirect' => route('client.org.roster'),
        ]);
    }

    private function company(): Company
    {
        return Company::findOrFail((int) session('client_company_id'));
    }
}
