<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Jobs\Org\GenerateOrgAdditionJob;
use App\Jobs\Org\IgniteOrganizationJob;
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

        // Запалване (§ignition): фоново генериране на seed задачите → Кутията за решения.
        // НЕ е в materialize() (транзакция) — диспечираме след commit, за да види job-ът данните.
        IgniteOrganizationJob::dispatch($version->id)->onQueue('org');

        return response()->json([
            'ok' => true,
            'version' => $version->version,
            'redirect' => route('client.org.roster'),
        ]);
    }

    /**
     * Ревю екранът: AI-генерира ЕДИН нов асистент за отдел. АСИНХРОННО (org queue) — LLM call-ът
     * надхвърля уеб max_execution_time. Връща token; фронтендът поллва addStatus.
     */
    public function generateAssistant(Request $request): JsonResponse
    {
        $company = $this->company();
        $data = $request->validate([
            'department' => ['required', 'array'],
            'department.key' => ['nullable', 'string', 'max:120'],
            'department.domain' => ['nullable', 'string', 'max:120'],
            'department.title' => ['nullable', 'string', 'max:160'],
            'department.mandate' => ['nullable', 'string', 'max:1000'],
            'existing' => ['array'],
            'existing.*.key' => ['nullable', 'string', 'max:120'],
            'existing.*.title' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->dispatchAddition($company->id, 'assistant', [
            'department' => $data['department'],
            'existing' => $data['existing'] ?? [],
        ]);
    }

    /**
     * Ревю екранът: AI-генерира ЕДИН нов отдел (директор + ≥2 асистента). АСИНХРОННО.
     * Празно name → авто (Управителят решава); подадено name+description → ръчен отдел.
     */
    public function generateDepartment(Request $request): JsonResponse
    {
        $company = $this->company();
        $data = $request->validate([
            'existing_domains' => ['array'],
            'existing_domains.*' => ['string', 'max:120'],
            'name' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $custom = filled($data['name'] ?? null)
            ? ['name' => $data['name'], 'description' => $data['description'] ?? '']
            : [];

        return $this->dispatchAddition($company->id, 'department', [
            'existing_domains' => $data['existing_domains'] ?? [],
            'custom' => $custom,
        ]);
    }

    /** Поллинг на фоновата AI-генерация (асистент/отдел). */
    public function addStatus(string $token): JsonResponse
    {
        $result = Cache::get("org_add_{$token}");
        if (! $result) {
            return response()->json(['status' => 'expired', 'error' => 'Изтече. Опитай пак.'], 404);
        }

        return response()->json($result);
    }

    /** Стартира GenerateOrgAdditionJob на `org` queue и връща token. */
    private function dispatchAddition(int $companyId, string $kind, array $payload): JsonResponse
    {
        $token = (string) Str::uuid();
        Cache::put("org_add_{$token}", ['status' => 'pending', 'updated_at' => now()->timestamp], now()->addMinutes(20));
        GenerateOrgAdditionJob::dispatch($companyId, $kind, $payload, $token)->onQueue('org');

        return response()->json(['token' => $token]);
    }

    private function company(): Company
    {
        return Company::findOrFail((int) session('client_company_id'));
    }
}
