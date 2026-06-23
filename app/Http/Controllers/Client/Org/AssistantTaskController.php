<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Models\AssistantTask;
use App\Support\ModelLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Контрол на задачите (§2.5). Във Фаза 2: per-task ниво (явен override или nullиране =
 * „наследява" нивото на члена). Пускането на задача (run) идва във Фаза 3.
 */
class AssistantTaskController extends Controller
{
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
