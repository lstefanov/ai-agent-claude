<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Jobs\Org\OrgReviewJob;
use App\Models\Company;
use App\Services\Org\OrgGraphService;
use Illuminate\Http\JsonResponse;

/**
 * „Един граф, няколко лещи" (§6): едни и същи director/assistant/task записи, три
 * изгледа (Roster / Skill Tree / Карта) над общ JSON.
 */
class OrgGraphController extends Controller
{
    public function __construct(private OrgGraphService $graph) {}

    public function graph(): JsonResponse
    {
        return response()->json($this->graph->build($this->company()));
    }

    public function roster()
    {
        $company = $this->company();

        return view('client.org.roster', ['graph' => $this->graph->build($company), 'company' => $company]);
    }

    public function skillTree()
    {
        $company = $this->company();
        $graph = $this->graph->build($company);

        return view('client.org.skill-tree', [
            'graph' => $graph,
            'company' => $company,
            'lens' => $this->graph->skillLens($graph),
        ]);
    }

    /** Текущ поток (Леща 3, §6) — кои асистенти имат активни runs. */
    public function live()
    {
        $company = $this->company();

        return view('client.org.live', ['graph' => $this->graph->build($company), 'company' => $company]);
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
    public function reviewNow(AutonomousBudgetService $budget): JsonResponse
    {
        $company = $this->company();

        if (! $company->active_org_version_id) {
            return response()->json([
                'ok' => false,
                'message' => 'Няма активна организация — завърши онбординга преди ревю.',
            ], 422);
        }

        if (! $budget->allows($company, 'org_review')) {
            return response()->json([
                'ok' => false,
                'message' => 'Дневният лимит за автономни действия е достигнат.',
            ], 429);
        }

        if (! OrgReviewLock::acquire($company->id)) {
            return response()->json([
                'ok' => false,
                'message' => 'Ревю вече тече или е пуснато наскоро — изчакай малко.',
            ], 409);
        }

        OrgReviewJob::dispatch($company->id)->onQueue('org');

        return response()->json([
            'ok' => true,
            'message' => 'Ревюто е пуснато — предложенията ще се появят в Кутията (обикновено за 1–3 мин).',
        ]);
    }

    private function company(): Company
    {
        return Company::findOrFail((int) session('client_company_id'));
    }
}
