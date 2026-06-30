<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Models\AssistantTask;
use App\Models\Company;
use App\Models\CreditReservation;
use App\Models\CreditWallet;
use App\Models\FlowRun;
use App\Services\Org\DecisionBoxService;
use Illuminate\Http\JsonResponse;

/**
 * Табло (§4) — първият екран при активна организация: текущ поток (живо), чакащи решения,
 * кредити. Live секцията се обновява през state() БЕЗ reload (§4.2).
 */
class OrgDashboardController extends Controller
{
    private const ACTIVE_STATUSES = ['pending', 'running', 'waiting_approval'];

    public function __construct(private TaskRunService $tasks) {}

    public function index(DecisionBoxService $box)
    {
        $company = $this->company();
        $decisionsPreview = $box->preview($company, 4);

        return view('client.org.dashboard', [
            'company' => $company,
            'decisionsPreview' => $decisionsPreview,
            'counts' => $this->taskCounts($company),
            'credits' => $this->credits($company),
            'digest' => $company->orgEvents()->where('type', 'daily_digest')->latest('id')->first(),
            'state' => $this->buildState($company),
        ]);
    }

    /** JSON dashboard-state за поллинг (§4.2) — само променливите части. */
    public function state(): JsonResponse
    {
        return response()->json($this->buildState($this->company()));
    }

    private function buildState(Company $company): array
    {
        $runs = FlowRun::whereHas('flow', fn ($q) => $q->where('company_id', $company->id))
            ->with('flow')
            ->latest('id')->take(40)->get();

        $active = $runs->whereIn('status', self::ACTIVE_STATUSES)->values();
        $recent = $runs->whereIn('status', ['completed', 'failed'])->take(6)->values();

        return [
            'active_runs' => $active->map(fn (FlowRun $r) => $this->runSummary($r))->all(),
            'recent_runs' => $recent->map(fn (FlowRun $r) => $this->runSummary($r))->all(),
            'task_counts' => $this->taskCounts($company),
            'credits' => $this->credits($company),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /** Компактно резюме на run за live секцията. */
    private function runSummary(FlowRun $run): array
    {
        $task = AssistantTask::with('orgMember.persona')
            ->find($run->context['assistant_task_id'] ?? null);

        $totalNodes = $run->flowVersion
            ? $run->flowVersion->nodes()->where('is_active', true)->where('type', '!=', 'qa_verifier')->count()
            : 0;
        $done = $run->nodeRuns()->whereIn('status', ['completed', 'skipped'])->distinct('node_key')->count('node_key');
        $percent = $totalNodes > 0 ? min(100, (int) round($done / $totalNodes * 100)) : null;

        $member = $task?->orgMember;

        return [
            'id' => $run->id,
            'flow' => $run->flow?->name,
            'task_title' => $task?->title,
            'member' => $member ? ['name' => $member->fullName(), 'role' => $member->roleTitle(), 'color' => $member->functionColor()] : null,
            'status' => $run->status,
            'percent' => $percent,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'result_url' => route('client.runs.result', $run->id),
        ];
    }

    private function taskCounts(Company $company): array
    {
        $base = $this->tasks->tasksForActiveAssistants($company);
        $byStatus = (clone $base)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        // „Изпълнени" се извежда от runs (като таб „Изпълнени"), не е task статус.
        $completed = (clone $base)
            ->whereHas('flow.flowRuns', fn ($q) => $q->where('status', 'completed'))->count();

        // Задачи, чакащи знание (§2-етапни задачи) — изискват въвеждане от Управителя преди старт.
        $needsKnowledge = (clone $base)
            ->where('knowledge_status', 'needs_knowledge')
            ->whereIn('status', ['ready', 'proposed', 'failed'])->count();

        return [
            'ready' => (int) ($byStatus['ready'] ?? 0),
            'generating' => (int) ($byStatus['generating'] ?? 0),
            'pending_approval' => (int) ($byStatus['pending_approval'] ?? 0),
            'completed' => $completed,
            'rejected' => (int) ($byStatus['rejected'] ?? 0),
            'needs_knowledge' => $needsKnowledge,
        ];
    }

    private function credits(Company $company): array
    {
        $wallet = $company->creditWallet ?? CreditWallet::firstOrCreate(['company_id' => $company->id]);
        $reserved = (int) CreditReservation::where('company_id', $company->id)
            ->where('status', 'reserved')->sum('estimated_credits');

        return ['available' => (int) $wallet->balance, 'reserved' => $reserved];
    }

    private function company(): Company
    {
        return Company::findOrFail((int) session('client_company_id'));
    }
}
