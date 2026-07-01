<?php

namespace App\Services;

use App\Jobs\GenerateAgentsJob;
use App\Models\AssistantTask;
use App\Models\Flow;
use App\Services\Org\Billing\BillableOperationService;
use App\Services\Org\Billing\InsufficientCreditsException;
use App\Support\ModelLevel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Пуска фоновата генерация на агенти (`flows:generate-agents`) и връща token.
 * Единствената точка, която сглобява `agent_gen_request_{token}` кеша и диспечира
 * Horizon job-а (`org` queue) — ползва се и от админ builder-а, и от клиентския wizard.
 * Поллингът става през `flows.generation-status/{token}` (FlowController::generationStatus).
 *
 * Тук се ОТВАРЯ generation кредитната резервация за ВСИЧКИ пътища (§0.5/A): org задача →
 * hard-gate (хвърля при недостиг), ръчна билдър/wizard генерация → best-effort. reservation_id
 * + operation_id влизат в кеша; GenerateAgentsCommand ги чете и settle-ва (partial при провал).
 */
class AgentGenerationLauncher
{
    public function __construct(private BillableOperationService $billable) {}

    /**
     * @param  array<string, array{provider: string, model: ?string}>  $phases  per-фазови override-и (празно = .env defaults)
     * @param  bool  $minimalQa  клиентски flows: само финалният QA gate (A4)
     * @param  bool  $persist  клиентски flows: фоновата команда сама записва активна
     *                         версия в DB-то (админът записва ръчно през builder-а)
     * @param  int|null  $draftId  изходният FlowDraft — маркира се 'completed' след запис
     * @param  int|null  $assistantTaskId  org задачата — flow_id ѝ се връзва + status='ready'
     *                                     след запис (огледало на draft пътя, §0.5.6)
     * @param  string  $origin  manual | autonomous — за билинг атрибуцията на резервацията
     * @return string токенът за поллинг на статуса
     *
     * @throws InsufficientCreditsException при org generation без кредити (hard-gate)
     */
    public function launch(int $companyId, int $flowId, string $name, string $description, string $level, array $phases = [], bool $minimalQa = false, bool $persist = false, ?int $draftId = null, ?int $assistantTaskId = null, string $origin = 'manual'): string
    {
        $token = Str::uuid()->toString();

        // Generation резервация (§0.5): org задача → subject=AssistantTask + hard-gate (block при
        // недостиг); ръчна билдър/wizard → subject=Flow + best-effort (soft). token се генерира ПРЕДИ
        // reserve → opKey="gen:{token}" е стабилен. begin() може да хвърли (org hard-gate) ПРЕДИ dispatch.
        $isOrg = $assistantTaskId !== null;
        $subject = $isOrg ? AssistantTask::find($assistantTaskId) : Flow::find($flowId);
        // org generation чете BILLING_GATE_GENERATION_ORG (default hard); ръчна (null) минава през
        // BillingGatePolicy → BILLING_GATE_GENERATION_MANUAL (default soft). И двата ключа са живи.
        $hardGate = $isOrg ? ((string) config('billing.gate.generation_org', 'hard') === 'hard') : null;
        [$reservation, $operationId] = array_values($this->billable->begin(
            $companyId,
            'generation',
            $subject,
            opKey: "gen:{$token}",
            level: ModelLevel::fromRequest($level),
            origin: $origin,
            hardGate: $hardGate,
        ));

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
            'generation_reservation_id' => $reservation?->id,
            'generation_operation_id' => $operationId,
        ], now()->addMinutes(15));

        // Начален статус — поллерът веднага вижда 'pending'.
        Cache::put("agent_gen_{$token}", [
            'status' => 'pending',
            'agents' => [],
            'error' => null,
            'stage' => 'Стартиране...',
            'updated_at' => now()->timestamp,
        ], now()->addMinutes(15));

        // Horizon job на `org` queue (supervisor-org) — наблюдаемо, без detached exec (§8.3).
        // Не го убива HTTP timeout-ът; не конкурира node execution на supervisor-flows.
        GenerateAgentsJob::dispatch($token)->onQueue('org');

        return $token;
    }
}
