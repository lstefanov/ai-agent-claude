<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Models\AssistantTask;
use App\Models\Company;

/**
 * Дневник на задачите (§5) — клиентският изглед към AssistantTask + Flow, по lifecycle лещи:
 * За изпълнение (ready) · Предложени (pending_approval, с brief) · Изпълнени (завършен run).
 */
class TaskLogController extends Controller
{
    public function index()
    {
        $company = Company::findOrFail((int) session('client_company_id'));

        $assistantIds = $company->members()
            ->where('kind', 'assistant')->where('status', 'active')->pluck('id');

        $base = fn () => AssistantTask::whereIn('org_member_id', $assistantIds)
            ->with('orgMember.persona', 'flow.latestRun');

        $ready = $base()->where('status', 'ready')->latest()->get();
        $proposed = $base()->where('status', 'pending_approval')->latest()->get();

        // Изпълнени: имат поне един завършен FlowRun (flow-ът е ексклузивен за задачата).
        $executed = $base()
            ->whereNotNull('flow_id')
            ->whereHas('flow.flowRuns', fn ($q) => $q->where('status', 'completed'))
            ->latest()->get();

        // Странични: отхвърлени/изключени (филтър, не главен таб).
        $rejected = $base()->whereIn('status', ['rejected', 'disabled'])->latest()->get();

        return view('client.org.tasks', [
            'company' => $company,
            'ready' => $ready,
            'proposed' => $proposed,
            'executed' => $executed,
            'rejected' => $rejected,
        ]);
    }
}
