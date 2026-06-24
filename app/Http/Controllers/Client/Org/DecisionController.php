<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Models\AssistantTask;
use App\Models\Company;
use App\Models\FlowRun;
use App\Models\OrgMember;
use App\Models\OrgProposal;
use App\Models\User;
use App\Services\Org\DecisionBoxService;
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

        $proposal = $this->companyProposal((int) $request->input('id'));
        $result = $box->approveProposal($proposal, $this->user());

        // Материализация на простите типове (нова задача). Структурните hire/fire/mandate
        // минават през ре-дизайн (Фаза 2/7) — тук маркираме одобрението.
        if (($result['ok'] ?? false) && ($result['materialize'] ?? null) === 'task') {
            $this->materializeTaskProposal($proposal);
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

        $proposal = $this->companyProposal((int) $request->input('id'));
        $result = $box->rejectProposal($proposal, $this->user(), $request->input('comment'));

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    /** Одобрено „task" предложение → нова assistant_task (status=proposed). */
    private function materializeTaskProposal(OrgProposal $proposal): void
    {
        $p = (array) $proposal->payload;
        $memberId = $p['org_member_id'] ?? null;
        if (! $memberId || ! OrgMember::where('company_id', $proposal->company_id)->whereKey($memberId)->exists()) {
            return;
        }

        AssistantTask::firstOrCreate(
            ['org_member_id' => $memberId, 'title' => $p['title'] ?? 'Нова задача'],
            [
                'description' => $p['description'] ?? ($p['title'] ?? ''),
                'act_mode' => in_array($p['act_mode'] ?? 'draft', ['draft', 'act', 'mixed'], true) ? $p['act_mode'] : 'draft',
                'trigger' => 'manual',
                'status' => 'proposed',
            ],
        );
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

    private function companyRun(int $id): FlowRun
    {
        $run = FlowRun::with('flow')->findOrFail($id);
        abort_unless($run->flow?->company_id === (int) session('client_company_id'), 403);

        return $run;
    }
}
