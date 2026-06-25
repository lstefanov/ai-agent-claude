<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Пуска фоновата генерация на агенти (`flows:generate-agents`) и връща token.
 * Единствената точка, която сглобява `agent_gen_request_{token}` кеша и стартира
 * процеса — ползва се и от админ builder-а, и от клиентския wizard. Поллингът
 * става през `flows.generation-status/{token}` (FlowController::generationStatus).
 */
class AgentGenerationLauncher
{
    /**
     * @param  array<string, array{provider: string, model: ?string}>  $phases  per-фазови override-и (празно = .env defaults)
     * @param  bool  $minimalQa  клиентски flows: само финалният QA gate (A4)
     * @param  bool  $persist  клиентски flows: фоновата команда сама записва активна
     *                         версия в DB-то (админът записва ръчно през builder-а)
     * @param  int|null  $draftId  изходният FlowDraft — маркира се 'completed' след запис
     * @param  int|null  $assistantTaskId  org задачата — flow_id ѝ се връзва + status='ready'
     *                                     след запис (огледало на draft пътя, §0.5.6)
     * @return string токенът за поллинг на статуса
     */
    public function launch(int $companyId, int $flowId, string $name, string $description, string $level, array $phases = [], bool $minimalQa = false, bool $persist = false, ?int $draftId = null, ?int $assistantTaskId = null): string
    {
        $token = Str::uuid()->toString();

        // Данните, които фоновата команда чете.
        Cache::put("agent_gen_request_{$token}", [
            'company_id' => $companyId,
            'flow_id' => $flowId,
            'name' => $name,
            'description' => $description,
            'level' => $level,
            'phases' => $phases,
            'minimal_qa' => $minimalQa,
            'persist' => $persist,
            'draft_id' => $draftId,
            'assistant_task_id' => $assistantTaskId,
        ], now()->addMinutes(15));

        // Начален статус — поллерът веднага вижда 'pending'.
        Cache::put("agent_gen_{$token}", [
            'status' => 'pending',
            'agents' => [],
            'error' => null,
            'stage' => 'Стартиране...',
            'updated_at' => now()->timestamp,
        ], now()->addMinutes(15));

        // Фонов artisan процес (не го убива HTTP timeout-ът).
        $php = env('PHP_CLI_BINARY', PHP_BINARY);
        $artisan = base_path('artisan');
        $tok = escapeshellarg($token);
        exec("{$php} {$artisan} flows:generate-agents {$tok} >> ".escapeshellarg(storage_path('logs/agent-gen.log')).' 2>&1 &');

        return $token;
    }
}
