<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Jobs\Org\ResearchBusinessJob;
use App\Models\Company;
use App\Models\CreditWallet;
use App\Models\OrgMember;
use App\Services\Org\PersonaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Онбординг на организацията (§1.4): casting на Управителя → проучване. Клиентският
 * portal, preview auth (session('client_company_id')).
 */
class OnboardingController extends Controller
{
    public function __construct(private PersonaService $personas) {}

    /** Входна точка — пренасочва към текущата стъпка. */
    public function start()
    {
        $company = $this->company();

        if (! $company->manager) {
            return redirect()->route('client.org.casting');
        }

        $profile = $company->businessProfile;
        if (! $profile || ! in_array($profile->status, ['interviewing', 'ready'], true)) {
            return redirect()->route('client.org.research');
        }

        // Има активна организация → екипа; иначе → дизайн/ревю (Фаза 2).
        if ($company->active_org_version_id) {
            return redirect()->route('client.org.roster');
        }

        return redirect()->route($profile->status === 'ready' ? 'client.org.design.review' : 'client.org.interview');
    }

    /** Casting на Управителя — кандидати-архетипи + „създай свой". */
    public function casting()
    {
        $company = $this->company();
        $vertical = $this->vertical($company);

        return view('client.org.casting', [
            'archetypes' => $this->personas->archetypes($vertical, 'manager'),
            'vertical' => $vertical,
            'manager' => $company->manager,
        ]);
    }

    /** Наема Управител: upsert OrgMember(kind=manager) + Persona през PersonaService::attachTo. */
    public function hireManager(Request $request)
    {
        $company = $this->company();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'age' => ['nullable', 'integer', 'min:18', 'max:90'],
            'gender' => ['nullable', 'string', 'max:30'],
            'ethnicity' => ['nullable', 'string', 'max:40'],
            'background' => ['nullable', 'string', 'max:120'],
            'tone' => ['nullable', 'string', 'max:80'],
            'bio' => ['nullable', 'string', 'max:600'],
            'archetype_key' => ['nullable', 'string', 'max:80'],
            'traits' => ['nullable', 'array'],
            'traits.*' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        // Стабилна идентичност (един Управител на фирма) — ключът е код-алокиран, не LLM.
        $manager = OrgMember::firstOrCreate(
            ['company_id' => $company->id, 'kind' => 'manager'],
            ['key' => OrgMember::allocateKey($company, 'manager', $data['name']), 'display_name' => 'Управител', 'default_star_tier' => config('organization.manager.level', 'ultra')],
        );

        $this->personas->attachTo($manager, $data);

        // Wallet гарантирано съществува (онбордингът е best-effort безплатен; runs са hard-gated).
        CreditWallet::firstOrCreate(['company_id' => $company->id]);

        return redirect()->route('client.org.research')
            ->with('status', 'Управителят е нает. Да проучим бизнеса.');
    }

    /** Страница на проучването (прогрес + старт). */
    public function research()
    {
        $company = $this->company();
        if (! $company->manager) {
            return redirect()->route('client.org.casting');
        }

        return view('client.org.research', [
            'manager' => $company->manager,
            'profile' => $company->businessProfile,
        ]);
    }

    /** Стартира фоновото проучване → token за поллинг. */
    public function startResearch(): JsonResponse
    {
        $company = $this->company();
        $token = (string) Str::uuid();

        Cache::put("org_research_{$token}", ['status' => 'pending', 'stage' => 'Стартиране…', 'updated_at' => now()->timestamp], now()->addMinutes(20));
        ResearchBusinessJob::dispatch($company->id, $token)->onQueue('org');

        return response()->json(['token' => $token]);
    }

    public function researchStatus(string $token): JsonResponse
    {
        $result = Cache::get("org_research_{$token}");
        if (! $result) {
            return response()->json(['status' => 'expired', 'error' => 'Токенът изтече. Стартирай отново.'], 404);
        }

        return response()->json($result);
    }

    /** Текущата фирма (preview сесия). */
    private function company(): Company
    {
        return Company::findOrFail((int) session('client_company_id'));
    }

    /** Маппинг на бранша към seed вертикал (за архетипи/blueprints). */
    private function vertical(Company $company): string
    {
        $industry = mb_strtolower((string) $company->industry);

        return match (true) {
            str_contains($industry, 'фитнес') || str_contains($industry, 'спорт') || str_contains($industry, 'fitness') => 'fitness',
            str_contains($industry, 'ресторант') || str_contains($industry, 'кафе') || str_contains($industry, 'restaurant') || str_contains($industry, 'food') => 'restaurant',
            default => 'services',
        };
    }
}
