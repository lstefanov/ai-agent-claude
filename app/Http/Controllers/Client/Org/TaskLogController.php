<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Models\AssistantTask;
use App\Models\Company;
use App\Models\OrgProposal;

/**
 * Дневник на задачите (§5) — клиентският изглед към AssistantTask + Flow, по lifecycle лещи:
 * За изпълнение (ready + generating) · Чака преглед на flow (pending_approval) · Изпълнени.
 */
class TaskLogController extends Controller
{
    public function index()
    {
        $company = Company::findOrFail((int) session('client_company_id'));

        $assistantIds = $company->members()
            ->where('kind', 'assistant')->where('status', 'active')->pluck('id');

        $base = fn () => AssistantTask::whereIn('org_member_id', $assistantIds)
            ->with('orgMember.persona', 'flow.latestRun', 'knowledgeRequirements');

        $ready = $base()->where('status', 'ready')->latest()->get();
        $generating = $base()->where('status', 'generating')->latest()->get();
        $proposed = $base()->where('status', 'pending_approval')->latest()->get();

        // Изпълнени: имат поне един завършен FlowRun (flow-ът е ексклузивен за задачата).
        $executed = $base()
            ->whereNotNull('flow_id')
            ->whereHas('flow.flowRuns', fn ($q) => $q->where('status', 'completed'))
            ->latest()->get();

        // Странични: отхвърлени/изключени (филтър, не главен таб).
        $rejected = $base()->whereIn('status', ['rejected', 'disabled'])->latest()->get();

        // Чакащи предложения в Кутията за решения — за да обясним празното състояние тук
        // (директорите предлагат там; задачите се появяват след одобрение).
        $pendingProposals = OrgProposal::where('company_id', $company->id)->pending()->count();

        return view('client.org.tasks', [
            'company' => $company,
            'ready' => $ready,
            'generating' => $generating,
            'proposed' => $proposed,
            'executed' => $executed,
            'rejected' => $rejected,
            'pendingProposals' => $pendingProposals,
            'initialTab' => in_array(request('tab'), ['ready', 'proposed', 'executed'], true)
                ? request('tab')
                : 'ready',
        ]);
    }
}
