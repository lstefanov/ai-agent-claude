<?php

namespace App\Services\Org\Billing;

use App\Models\AssistantTask;
use App\Models\Company;
use App\Models\CreditLedgerEntry;
use App\Models\CreditReservation;
use App\Models\CreditWallet;
use App\Models\FlowRun;
use App\Models\LlmRequest;
use App\Support\BillableUnit;
use App\Support\ModelLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Context-agnostic кредитна машина (§0.5.2): reserve → settle/refund, унифицирана за
 * ВСИЧКИ билинг контексти (chat/research/avatar/interview/generation/task_run) през
 * CreditReservation, а НЕ само за FlowRun. Източникът на истината за разхода остава
 * llm_requests/cost_usd — тук само превеждаме персистираните редове в кредити и движим баланса.
 *
 * Идемпотентност: reserve е по unique idempotency_key на резервацията; settle/refund/topup
 * редове в credit_ledger са operation-scoped ("{reservation}:settle" и т.н.) → retry/паралел
 * не дебитира/refund-ва два пъти.
 */
class CreditMeterService
{
    /**
     * Атомарна резервация: единичен conditional UPDATE сваля баланса само ако стига
     * (balance >= estimate) → два паралелни/повторени run-а не свалят под нула. При успех
     * създава CreditReservation(reserved) + ledger ред type=reserve.
     *
     * @param  Model|null  $subject  полиморфният субект (FlowRun / AssistantTask / OrgMember)
     *
     * @throws InsufficientCreditsException при недостиг
     */
    public function reserve(int $companyId, string $contextType, ?Model $subject, int $estimate): CreditReservation
    {
        $estimate = max(1, $estimate);
        [$subjectType, $subjectId] = $this->subjectKey($subject);
        $key = $this->reserveKey($contextType, $subjectType, $subjectId);

        return DB::transaction(function () use ($companyId, $contextType, $subjectType, $subjectId, $estimate, $key) {
            // Идемпотентност: същият RESERVE ключ → връщаме съществуващата (без втори дебит).
            $existing = CreditReservation::where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            // Атомарен decrement с условен гард — 0 засегнати реда = недостиг.
            $affected = DB::table('credit_wallets')
                ->where('company_id', $companyId)
                ->where('balance', '>=', $estimate)
                ->update(['balance' => DB::raw("balance - {$estimate}"), 'updated_at' => now()]);

            if ($affected === 0) {
                $available = (int) (CreditWallet::where('company_id', $companyId)->value('balance') ?? 0);
                throw new InsufficientCreditsException($companyId, $estimate, $available);
            }

            $reservation = CreditReservation::create([
                'company_id' => $companyId,
                'context_type' => $contextType,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'estimated_credits' => $estimate,
                'spent_credits' => 0,
                'status' => 'reserved',
                'idempotency_key' => $key,
            ]);

            $this->ledger($reservation, 'reserve', 'debit', $estimate, 'run', $key);

            return $reservation;
        });
    }

    /**
     * Реалният разход под резервацията = сума на BillableUnit над персистираните
     * llm_requests (по reservation_id) + flat tool разход. Чете САМО реални редове.
     */
    public function actualFor(CreditReservation $reservation): int
    {
        $rows = LlmRequest::where('reservation_id', $reservation->id)
            ->get(['completion_tokens', 'cost_usd']);

        if ($rows->isEmpty()) {
            return 0;
        }

        // LLM редове: сумираме completion токените и прилагаме каноничната формула веднъж.
        $totalCompletion = (int) $rows->sum(fn ($r) => (int) $r->completion_tokens);
        $credits = BillableUnit::creditsForLlm($this->levelForReservation($reservation), $totalCompletion);

        // Flat/tool редове (без completion токени, но с явна cost_usd) → през markup.
        $flatUsd = (float) $rows->filter(fn ($r) => (int) $r->completion_tokens <= 0)
            ->sum(fn ($r) => (float) $r->cost_usd);
        if ($flatUsd > 0) {
            $credits += (int) ceil($flatUsd * (float) config('billing.credit_markup', 3.0));
        }

        return $credits;
    }

    /**
     * Реконсилиация: записва spent_credits, ledger ред type=settle, и refund-ва остатъка
     * (estimate - actual), ако е положителен; ако actual > estimate → допълнителен дебит.
     * Идемпотентен по operation-scoped ключове; status → settled.
     */
    public function settle(CreditReservation $reservation, int $actualSpent): void
    {
        $actualSpent = max(0, $actualSpent);

        DB::transaction(function () use ($reservation, $actualSpent) {
            $reservation = CreditReservation::whereKey($reservation->id)->lockForUpdate()->first();
            if (! $reservation || $reservation->status !== 'reserved') {
                return; // вече закрита — идемпотентен no-op
            }

            $estimate = (int) $reservation->estimated_credits;
            $this->ledger($reservation, 'settle', 'debit', $actualSpent, 'run', "{$reservation->id}:settle");

            if ($actualSpent < $estimate) {
                // Връщаме непохарчения остатък в баланса.
                $refund = $estimate - $actualSpent;
                $this->creditWallet($reservation->company_id, $refund);
                $this->ledger($reservation, 'refund', 'credit', $refund, 'refund', "{$reservation->id}:refund");
            } elseif ($actualSpent > $estimate) {
                // Overage — допълнителен дебит (работата вече е извършена).
                $extra = $actualSpent - $estimate;
                $this->debitWallet($reservation->company_id, $extra);
            }

            $reservation->update(['spent_credits' => $actualSpent, 'status' => 'settled']);
        });
    }

    /**
     * Пълно връщане на резервирания остатък — операцията не е стартирала / провал преди
     * първи request. ledger type=refund, status=refunded. Идемпотентен.
     */
    public function refund(CreditReservation $reservation): void
    {
        DB::transaction(function () use ($reservation) {
            $reservation = CreditReservation::whereKey($reservation->id)->lockForUpdate()->first();
            if (! $reservation || $reservation->status !== 'reserved') {
                return;
            }

            $remaining = (int) $reservation->estimated_credits - (int) $reservation->spent_credits;
            if ($remaining > 0) {
                $this->creditWallet($reservation->company_id, $remaining);
                $this->ledger($reservation, 'refund', 'credit', $remaining, 'refund', "{$reservation->id}:refund");
            }

            $reservation->update(['status' => 'refunded']);
        });
    }

    /**
     * Зареждане (topup) / безплатен grant: атомарен increment + ledger ред. Няма резервация.
     */
    public function topup(Company $company, int $credits, string $type = 'topup', array $meta = []): CreditLedgerEntry
    {
        $credits = max(0, $credits);

        return DB::transaction(function () use ($company, $credits, $type, $meta) {
            $wallet = CreditWallet::firstOrCreate(['company_id' => $company->id]);
            DB::table('credit_wallets')->where('id', $wallet->id)
                ->update(['balance' => DB::raw("balance + {$credits}"), 'updated_at' => now()]);

            return CreditLedgerEntry::create([
                'credit_wallet_id' => $wallet->id,
                'company_id' => $company->id,
                'reservation_id' => null,
                'type' => $type,                                   // topup | grant
                'idempotency_key' => null,
                'direction' => 'credit',
                'amount' => $credits,
                'reason' => $type === 'grant' ? 'monthly_grant' : 'top_up',
                'meta' => $meta ?: null,
                'created_at' => now(),
            ]);
        });
    }

    // --- вътрешни помощници ---

    /** Записва ред в credit_ledger (идемпотентен по idempotency_key). */
    private function ledger(CreditReservation $r, string $type, string $direction, int $amount, string $reason, string $key): CreditLedgerEntry
    {
        $wallet = CreditWallet::firstOrCreate(['company_id' => $r->company_id]);

        return CreditLedgerEntry::firstOrCreate(
            ['idempotency_key' => $key],
            [
                'credit_wallet_id' => $wallet->id,
                'company_id' => $r->company_id,
                'reservation_id' => $r->id,
                'type' => $type,
                'direction' => $direction,
                'amount' => $amount,
                'reason' => $reason,
                'created_at' => now(),
            ],
        );
    }

    private function creditWallet(int $companyId, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        DB::table('credit_wallets')->where('company_id', $companyId)
            ->update(['balance' => DB::raw("balance + {$amount}"), 'updated_at' => now()]);
    }

    private function debitWallet(int $companyId, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        // Overage — безусловен дебит (разходът вече е реален); wallet гейтът пази НОВИ пускания.
        DB::table('credit_wallets')->where('company_id', $companyId)
            ->update(['balance' => DB::raw("balance - {$amount}"), 'updated_at' => now()]);
        Log::info("[CreditMeter] overage debit {$amount} for company {$companyId}");
    }

    /** Извежда субект-ключа (тип+id) от полиморфен модел. */
    private function subjectKey(?Model $subject): array
    {
        if (! $subject) {
            return [null, null];
        }

        return [$subject->getMorphClass(), (int) $subject->getKey()];
    }

    private function reserveKey(string $contextType, ?string $subjectType, ?int $subjectId): string
    {
        $subj = $subjectType ? class_basename($subjectType)."#{$subjectId}" : 'none';

        return "{$contextType}:{$subj}:reserve";
    }

    /** Нивото за token→кредити преобразуване, изведено от субекта/контекста. */
    private function levelForReservation(CreditReservation $r): ModelLevel
    {
        return match ($r->context_type) {
            'task_run' => $this->taskLevelFromFlowRun((int) $r->subject_id),
            'generation' => optional(AssistantTask::find($r->subject_id))->effectiveStarTier() ?? ModelLevel::Medium,
            'org_planning', 'interview', 'research', 'director_tick' => ModelLevel::fromRequest(config('organization.manager.level')),
            default => ModelLevel::Medium,
        };
    }

    private function taskLevelFromFlowRun(int $flowRunId): ModelLevel
    {
        $run = FlowRun::find($flowRunId);
        $taskId = $run?->context['assistant_task_id'] ?? null;
        if ($taskId && ($task = AssistantTask::find($taskId))) {
            return $task->effectiveStarTier();
        }

        return ModelLevel::Medium;
    }
}
