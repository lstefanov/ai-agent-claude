<?php

namespace App\Services\Org\Billing;

use App\Models\CreditReservation;
use App\Support\BillableUnit;
use App\Support\LlmContext;
use App\Support\ModelLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Каноничният път за ВСЯКО кредитно таксуване (§0.5). Капсулира:
 *  - estimate + reserve (hard-gate или best-effort според BillingGatePolicy / explicit override),
 *  - пълна LlmContext атрибуция (ВИНАГИ company/context/subject + operation_id; reservation_id само при резервация),
 *  - settle/refund с partial-settle при провал (ако вече е похарчено — settle реалното, не refund),
 *  - винаги push/pop (универсално безопасно — не разрушава обгръщащ контекст).
 *
 * Два режима: run() за sync/job (една операция в един процес); begin()/finish() за async
 * (generation — резервацията се отваря в launcher-а, settle става по-късно в командата).
 */
class BillableOperationService
{
    public function __construct(private CreditMeterService $meter) {}

    /**
     * Sync/job билинг обвивка. Връща резултата на $work.
     *
     * @template T
     *
     * @param  callable():T  $work
     * @return T
     */
    public function run(
        int $companyId,
        string $contextType,
        ?Model $subject,
        callable $work,
        ?string $opKey = null,
        ?ModelLevel $level = null,
        string $origin = 'manual',
        ?bool $hardGate = null,
    ): mixed {
        $operationId = (string) Str::uuid();
        $reservation = $this->openReservation($companyId, $contextType, $subject, $opKey, $level, $origin, $hardGate);

        LlmContext::push($this->frame($companyId, $contextType, $subject, $operationId, $reservation));

        $outcome = 'completed';
        $error = null;
        try {
            $result = $work();
        } catch (\Throwable $e) {
            $outcome = 'failed';
            $error = $e;
            $result = null;
        } finally {
            LlmContext::pop();
            $this->finish($reservation, $outcome);
        }

        if ($error) {
            throw $error;
        }

        return $result;
    }

    /**
     * Async режим — стъпка 1: отвори резервация (launcher-ът я вика, записва
     * reservation_id + operation_id в кеша на заявката). Връща [reservation, operation_id].
     *
     * @return array{reservation: ?CreditReservation, operation_id: string}
     */
    public function begin(
        int $companyId,
        string $contextType,
        ?Model $subject,
        ?string $opKey = null,
        ?ModelLevel $level = null,
        string $origin = 'manual',
        ?bool $hardGate = null,
    ): array {
        return [
            'reservation' => $this->openReservation($companyId, $contextType, $subject, $opKey, $level, $origin, $hardGate),
            'operation_id' => (string) Str::uuid(),
        ];
    }

    /**
     * Async режим — стъпка 2: реконсилирай резервацията с реалния разход (partial-settle).
     * При провал НЕ refund-ва сляпо: ако вече има реален разход → settle(actual)+outcome;
     * само ако НЯМА никакви заявки → пълен refund.
     */
    public function finish(?CreditReservation $reservation, string $outcome = 'completed'): void
    {
        if (! $reservation) {
            return;
        }

        $actual = $this->meter->actualFor($reservation);

        if ($actual > 0) {
            $this->meter->settle($reservation, $actual, $outcome);
        } elseif ($outcome === 'failed') {
            $this->meter->refund($reservation);
        } else {
            $this->meter->settle($reservation, 0, $outcome);
        }
    }

    /** Контекст-кадърът, стампван върху всеки llm_requests ред на операцията. */
    public function frame(int $companyId, string $contextType, ?Model $subject, string $operationId, ?CreditReservation $reservation): array
    {
        return array_filter([
            'company_id' => $companyId,
            'purpose' => $contextType,
            'context_type' => $contextType,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'reservation_id' => $reservation?->id,   // само при реална резервация (0.4)
            'operation_id' => $operationId,
        ], fn ($v) => $v !== null);
    }

    /** Резервирай (best-effort или hard-gate). Връща null при липса на кредити + soft gate. */
    private function openReservation(int $companyId, string $contextType, ?Model $subject, ?string $opKey, ?ModelLevel $level, string $origin, ?bool $hardGate): ?CreditReservation
    {
        $hard = $hardGate ?? BillingGatePolicy::hardGate($contextType, $origin);
        // Оценката иска конкретно ниво; persist-натото model_level остава само ако е ЯВНО подадено
        // (иначе levelForReservation пада към контекстната логика — напр. org → manager.level).
        $estimate = BillableUnit::estimateFor($contextType, $this->resolveLevel($contextType, $level));

        try {
            return $this->meter->reserve($companyId, $contextType, $subject, $estimate, $origin, $opKey, $level);
        } catch (InsufficientCreditsException $e) {
            if ($hard) {
                throw $e;   // hard-gate (task_run/generation org/autonomous) → 402/skip нагоре
            }
            Log::info("[Billable] best-effort (no credits) company {$companyId} ctx {$contextType}");

            return null;    // soft → продължава, но атрибуцията пак носи company/operation_id
        }
    }

    private function resolveLevel(string $contextType, ?ModelLevel $level): ModelLevel
    {
        if ($level) {
            return $level;
        }

        return ModelLevel::fromRequest((string) config("billing.context_levels.{$contextType}", 'medium'));
    }
}
