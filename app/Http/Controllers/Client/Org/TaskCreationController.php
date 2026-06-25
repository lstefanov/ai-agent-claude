<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Models\AssistantTask;
use App\Models\Company;
use App\Models\OrgMember;
use App\Services\Org\AssistantRouterService;
use App\Services\Org\Billing\InsufficientCreditsException;
use App\Services\Org\TaskRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * „Нова задача" (Фаза 3, и двата входа): опиши → Управителят авто-рутира към най-подходящия
 * асистент, ИЛИ ръчно избери асистент. И в двата случая се създава AssistantTask и асистентът
 * „създава" flow-а по персоната си (генерацията стартира веднага). Никакви плаващи flows.
 */
class TaskCreationController extends Controller
{
    public function create(AssistantRouterService $router)
    {
        $company = $this->company();
        if (! $company->active_org_version_id) {
            return redirect()->route('client.org.start');
        }

        $assistants = $router->activeAssistants($company);
        if ($assistants === []) {
            return redirect()->route('client.org.design.review')
                ->with('error', 'Първо проектирай екип с поне един асистент.');
        }

        return view('client.org.task-new', [
            'assistants' => $assistants,
            'preselect' => (int) request('assistant', 0),
        ]);
    }

    public function store(Request $request, AssistantRouterService $router, TaskRunService $runner): JsonResponse
    {
        $company = $this->company();

        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'required|string|max:4000',
            'mode' => 'required|in:auto,manual',
            'assistant_id' => 'nullable|integer',
        ]);

        $description = trim($data['description']);
        $title = trim((string) ($data['title'] ?? '')) ?: Str::limit($description, 60, '');

        // Избор на асистент: авто (Управителят рутира) или ръчно.
        if ($data['mode'] === 'manual') {
            $memberId = (int) ($data['assistant_id'] ?? 0);
            if (! collect($router->activeAssistants($company))->firstWhere('member_id', $memberId)) {
                return response()->json(['ok' => false, 'error' => 'Изберете валиден асистент.'], 422);
            }
            $reason = 'Избран ръчно.';
        } else {
            $routed = $router->route($company, $description);
            if (! $routed) {
                return response()->json(['ok' => false, 'error' => 'Няма наличен асистент.'], 422);
            }
            $memberId = $routed['member_id'];
            $reason = $routed['reason'];
        }

        $member = OrgMember::with('persona')->findOrFail($memberId);
        abort_unless($member->company_id === $company->id, 403);

        // Текущото подчинение (директор) от плейсмънта на асистента.
        $directorMemberId = $member->currentPlacement()?->director?->orgMember?->id;

        $task = AssistantTask::create([
            'org_member_id' => $member->id,
            'current_director_member_id' => $directorMemberId,
            'title' => $title,
            'description' => $description,
            'trigger' => 'manual',
            'act_mode' => 'draft',
            'status' => 'proposed',
        ]);

        // Асистентът „създава" flow-а по своята персона (генерацията стартира веднага).
        try {
            $gen = $runner->generate($task, runAfterGenerate: false);
        } catch (InsufficientCreditsException $e) {
            $task->delete();   // атомарно: създаване-или-нищо

            return response()->json([
                'ok' => false,
                'message' => 'Недостатъчно кредити за генерация.',
                'needed' => $e->needed,
                'available' => $e->available,
                'upsell' => true,
            ], 402);
        }

        $token = $gen['token'] ?? null;

        return response()->json([
            'ok' => true,
            'task_id' => $task->id,
            'member_id' => $member->id,
            'member_name' => $member->persona->name ?? $member->display_name,
            'reason' => $reason,
            'status' => $gen['status'] ?? 'generating',
            'token' => $token,
            'gen_status_url' => $token ? route('client.org.tasks.gen-status', ['task' => $task->id, 'token' => $token]) : null,
            'run_url' => route('client.org.tasks.run', $task->id),
            'member_url' => route('client.org.member', $member->id),
        ]);
    }

    private function company(): Company
    {
        return Company::findOrFail((int) session('client_company_id'));
    }
}
