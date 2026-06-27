<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Jobs\Org\SynthesizeProfileJob;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Анализ екран (§3-part understanding): показва проблеми/нужди/възможности след интервюто,
 * преди дизайна на екипа. Синтезът върви на `org` queue (token-poll) — огледало на DesignController.
 */
class AnalysisController extends Controller
{
    /** Анализ екран — показва (или синтезира) проблеми/нужди/възможности. */
    public function show()
    {
        $company = $this->company();
        if (! $company->manager) {
            return redirect()->route('client.org.casting');
        }

        $profile = $company->businessProfile;
        if (! $profile || ! in_array($profile->status, ['interviewing', 'ready'], true)) {
            return redirect()->route('client.org.research');
        }

        return view('client.org.analysis', ['profile' => $profile]);
    }

    /** Стартира синтеза (или връща готовите изводи, ако вече е направен). */
    public function run(): JsonResponse
    {
        $company = $this->company();
        $profile = $company->businessProfile;
        if (! $profile) {
            return response()->json(['error' => 'Няма профил.'], 422);
        }

        if ($profile->synthesis_completed_at) {
            return response()->json([
                'done' => true,
                'problems' => (array) $profile->problems,
                'needs' => (array) $profile->needs,
                'opportunities' => (array) $profile->opportunities,
            ]);
        }

        $token = (string) Str::uuid();
        Cache::put("org_synthesis_{$token}", ['status' => 'pending', 'stage' => 'Стартиране…', 'updated_at' => now()->timestamp], now()->addMinutes(15));
        SynthesizeProfileJob::dispatch($profile->id, $token)->onQueue('org');

        return response()->json(['token' => $token]);
    }

    public function status(string $token): JsonResponse
    {
        $result = Cache::get("org_synthesis_{$token}");
        if (! $result) {
            return response()->json(['status' => 'expired', 'error' => 'Токенът изтече. Опитай пак.'], 404);
        }

        return response()->json($result);
    }

    private function company(): Company
    {
        return Company::findOrFail((int) session('client_company_id'));
    }
}
