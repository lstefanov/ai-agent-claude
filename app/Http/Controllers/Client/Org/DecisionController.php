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
            'pending' => $box->pending($company),
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

        // Материализация на одобреното СТРУКТУРНО предложение (§7.3): наемане / уволнение.
        if ($result['ok'] ?? false) {
            $this->materializeProposal($proposal, $result['materialize'] ?? null);
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

    /** Материализира одобрено предложение според типа (§7.3). */
    private function materializeProposal(OrgProposal $proposal, ?string $type): void
    {
        $company = $proposal->company;
        $payload = (array) $proposal->payload;
        $mutator = app(OrgMutationService::class);

        match ($type) {
            'hire' => $mutator->hireFromProposal($company, $payload, $this->user()),
            'fire' => filled($payload['target_member_id'] ?? null)
                ? $mutator->fireMember($company, (int) $payload['target_member_id'], $this->user())
                : null,
            default => null,   // mandate и др. — само одобрено + одит (event вече записан)
        };
        // NB: 'task' предложенията вече НЕ минават оттук — те са директни AssistantTask+draft
        // (ревизиран §6.1), одобряват се през approveTask().
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
