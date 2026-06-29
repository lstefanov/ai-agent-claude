<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Models\AssistantTask;
use App\Models\Company;
use App\Models\FlowRun;
use App\Models\OrgProposal;
use App\Models\User;
use App\Services\Org\DecisionBoxService;
use App\Services\Org\OrgMutationService;
use App\Services\Org\TaskRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Кутия за решения (§13) — едно място за всички чакащи: org предложения + паузирани
 * human_approval runs. Тънък контролер над DecisionBoxService (агрегатор/адаптер, §0.5.7).
 */
class DecisionController extends Controller
{
    public function index(DecisionBoxService $box)
    {
        $company = $this->company();

        return view('client.org.decisions', [
            'deck' => $box->deck($company),
            'company' => $company,
        ]);
    }

    public function approve(Request $request, DecisionBoxService $box): JsonResponse
    {
        $kind = (string) $request->input('kind');

        if ($kind === 'run_approval') {
            $run = $this->companyRun((int) $request->input('flow_run_id'));
            $result = $box->settleRunApproval($run, (string) $request->input('node_key'), true, $request->input('comment'));

            return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
        }

        // Предложена задача (draft flow) → активиране (+ optional „Одобри и пусни").
        if ($kind === 'assistant_task') {
            $task = $this->companyTask((int) $request->input('id'));
            $result = $box->approveTask($task, $this->user(), run: $request->boolean('run'));

            return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
        }

        $proposal = $this->companyProposal((int) $request->input('id'));
        $result = $box->approveProposal($proposal, $this->user());

        // Материализация на одобреното предложение (§7.3): задача / наемане / уволнение /
        // мандат / ниво. `run` → „Одобри и пусни" за task-идея.
        if ($result['ok'] ?? false) {
            $this->materializeProposal($proposal, $result['materialize'] ?? null, $request->boolean('run'));
        }

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function reject(Request $request, DecisionBoxService $box): JsonResponse
    {
        $kind = (string) $request->input('kind');

        if ($kind === 'run_approval') {
            $run = $this->companyRun((int) $request->input('flow_run_id'));
            $result = $box->settleRunApproval($run, (string) $request->input('node_key'), false, $request->input('comment'));

            return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
        }

        // Отказ на предложена задача — изисква причина (DecisionBoxService валидира).
        if ($kind === 'assistant_task') {
            $task = $this->companyTask((int) $request->input('id'));
            $result = $box->rejectTask($task, $this->user(), $request->input('reason') ?? $request->input('comment'));

            return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
        }

        $proposal = $this->companyProposal((int) $request->input('id'));
        $result = $box->rejectProposal($proposal, $this->user(), $request->input('comment'));

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    /** Материализира одобрено предложение според типа (§7.3 + §Codex: mandate/tier; Q1: task). */
    private function materializeProposal(OrgProposal $proposal, ?string $type, bool $run = false): void
    {
        $company = $proposal->company;
        $payload = (array) $proposal->payload;
        $mutator = app(OrgMutationService::class);

        match ($type) {
            'task' => $this->materializeTaskProposal($company, $payload, $run),
            'hire' => $mutator->hireFromProposal($company, $payload, $this->user()),
            'fire' => filled($payload['target_member_id'] ?? null)
                ? $mutator->fireMember($company, (int) $payload['target_member_id'], $this->user())
                : null,
            'mandate' => filled($payload['target_member_id'] ?? null)
                ? $mutator->changeMandate($company, (int) $payload['target_member_id'], (string) ($payload['description'] ?? $payload['title'] ?? ''), $this->user())
                : null,
            'tier_change' => filled($payload['target_member_id'] ?? null)
                ? $mutator->changeTier($company, (int) $payload['target_member_id'], (string) ($payload['tier'] ?? 'high'), $this->user())
                : null,
            default => null,
        };
    }

    /**
     * Одобрена task-идея (Q1 идея-бек лог) → AssistantTask + флоу-генерация. idea-approval Е
     * единственият човешки гейт: approval_policy='auto' + firstReviewDone → flow става ready
     * (без втора апрувъл стъпка). `run` → пуска веднага след генерация.
     */
    private function materializeTaskProposal(Company $company, array $payload, bool $run): void
    {
        $ownerId = (int) ($payload['org_member_id'] ?? $payload['target_member_id'] ?? 0);
        $owner = ($ownerId ? $company->members()->where('kind', 'assistant')->find($ownerId) : null)
            ?? $company->members()->where('kind', 'assistant')->where('status', 'active')->first();
        if (! $owner) {
            return;
        }

        $actMode = $payload['act_mode'] ?? 'draft';
        $task = AssistantTask::create([
            'org_member_id' => $owner->id,
            'title' => (string) ($payload['title'] ?? 'Задача'),
            'description' => (string) ($payload['description'] ?? $payload['title'] ?? ''),
            'trigger' => 'manual',
            'act_mode' => in_array($actMode, ['draft', 'act', 'mixed'], true) ? $actMode : 'draft',
            'approval_policy' => 'auto',   // идеята е одобрена → flow → ready, без втори гейт
            'status' => 'proposed',
        ]);

        app(TaskRunService::class)->generate($task, runAfterGenerate: $run, origin: 'autonomous', firstReviewDone: true);
    }

    private function company(): Company
    {
        return Company::findOrFail((int) session('client_company_id'));
    }

    private function user(): ?User
    {
        return User::find(session('client_user_id'));
    }

    private function companyProposal(int $id): OrgProposal
    {
        $proposal = OrgProposal::findOrFail($id);
        abort_unless($proposal->company_id === (int) session('client_company_id'), 403);

        return $proposal;
    }

    private function companyTask(int $id): AssistantTask
    {
        $task = AssistantTask::with('orgMember')->findOrFail($id);
        abort_unless($task->orgMember?->company_id === (int) session('client_company_id'), 403);

        return $task;
    }

    private function companyRun(int $id): FlowRun
    {
        $run = FlowRun::with('flow')->findOrFail($id);
        abort_unless($run->flow?->company_id === (int) session('client_company_id'), 403);

        return $run;
    }
}
