<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Models\AssistantTask;
use App\Services\Org\Billing\InsufficientCreditsException;
use App\Services\Org\TaskRunService;
use App\Support\ModelLevel;
use App\Support\QueueHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Контрол на задачите: per-task ниво (Фаза 2), генерация на Flow + ръчно пускане (Фаза 3).
 * Цялата логика минава през TaskRunService (споделен с director tick/callback) — никаква
 * дублирана генерационна логика, никакво синхронно чакане.
 */
class AssistantTaskController extends Controller
{
    /** „Генерирай" — материализира задачата във Flow през launcher-а (без авто-run). */
    public function generate(AssistantTask $task, TaskRunService $runner): JsonResponse
    {
        $this->authorizeTask($task);
        $result = $runner->generate($task, runAfterGenerate: false);

        return response()->json($result);
    }

    /** Поллинг на генерацията (същия глобален cache като wizard-а). */
    public function genStatus(AssistantTask $task, string $token): JsonResponse
    {
        $this->authorizeTask($task);
        $status = Cache::get("agent_gen_{$token}");
        if (! $status) {
            return response()->json(['status' => 'expired', 'error' => 'Токенът изтече.'], 404);
        }

        return response()->json($status + ['task_status' => $task->fresh()->status]);
    }

    /**
     * „Изпълни": готова задача → wallet гейт + FlowRun с org-контекст; без flow_id →
     * асинхронна генерация + run_after_generate (без синхронно чакане). Недостиг → 402.
     */
    public function run(AssistantTask $task, TaskRunService $runner): JsonResponse
    {
        $this->authorizeTask($task);

        if (! QueueHeartbeat::flowsAlive()) {
            return response()->json(['message' => 'Системата за изпълнение не е активна. Опитай след малко.'], 503);
        }

        try {
            $result = $runner->requestRun($task, runAfterGenerate: true);
        } catch (InsufficientCreditsException $e) {
            return response()->json([
                'message' => 'Недостатъчно кредити за пускане.',
                'needed' => $e->needed,
                'available' => $e->available,
                'upsell' => true,
            ], 402);
        }

        if (($result['status'] ?? null) === 'running' && isset($result['run_id'])) {
            $result['poll_url'] = route('client.runs.progress', $result['run_id']);
        }

        return response()->json($result);
    }

    /** Per-task ниво: задава явен override или го маха (null = наследява члена). */
    public function setTier(Request $request, AssistantTask $task): JsonResponse
    {
        $this->authorizeTask($task);

        $raw = $request->input('tier');
        // Празно/„inherit" → null (наследява нивото на члена).
        $tier = in_array($raw, [null, '', 'inherit'], true) ? null : ModelLevel::tryFrom((string) $raw)?->value;
        if ($raw !== null && $raw !== '' && $raw !== 'inherit' && $tier === null) {
            return response()->json(['ok' => false, 'error' => 'Невалидно ниво.'], 422);
        }

        $task->update(['star_tier' => $tier]);

        return response()->json([
            'ok' => true,
            'inherits' => $task->inheritsTier(),
            'effective' => $task->effectiveStarTier()->value,
        ]);
    }

    private function authorizeTask(AssistantTask $task): void
    {
        abort_unless($task->orgMember?->company_id === (int) session('client_company_id'), 403);
    }
}
