<?php

namespace App\Services\Org\Billing;

use App\Models\AssistantTask;
use App\Models\Company;
use App\Models\CreditLedgerEntry;
use App\Models\CreditReservation;
use App\Models\CreditWallet;
use App\Models\FlowRun;
use App\Models\LlmRequest;
use App\Models\Subscription;
use App\Support\BillableUnit;
use App\Support\ModelLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
     * @param  string  $origin  manual | autonomous | system — дневният автономен таван брои само autonomous
     *
     * @throws InsufficientCreditsException при недостиг
     */
    public function reserve(int $companyId, string $contextType, ?Model $subject, int $estimate, string $origin = 'manual', ?string $opKey = null, ?ModelLevel $level = null): CreditReservation
    {
        $estimate = max((int) config('billing.min_reserve_credits', 1), $estimate);
        [$subjectType, $subjectId] = $this->subjectKey($subject);
        $key = $this->reserveKey($contextType, $subjectType, $subjectId, $opKey);
        $meta = $this->pricingSnapshot($level);

        return DB::transaction(function () use ($companyId, $contextType, $origin, $subjectType, $subjectId, $estimate, $key, $level, $meta) {
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

                // Overage гард (§6.1): ако планът позволява + overage_enabled → безусловен
                // decrement (реален дълг) + track overage_used; иначе блок (402 + upsell).
                if (! $this->overageAllowed($companyId)) {
                    throw new InsufficientCreditsException($companyId, $estimate, $available);
                }
                DB::table('credit_wallets')->where('company_id', $companyId)->update([
                    'balance' => DB::raw("balance - {$estimate}"),
                    'overage_used' => DB::raw("overage_used + {$estimate}"),
                    'updated_at' => now(),
                ]);
            }

            $reservation = CreditReservation::create([
                'company_id' => $companyId,
                'context_type' => $contextType,
                'model_level' => $level?->value,
                'origin' => $origin,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'estimated_credits' => $estimate,
                'spent_credits' => 0,
                'status' => 'reserved',
                'billing_meta' => $meta,
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
            ->get(['provider', 'kind', 'completion_tokens', 'cost_usd', 'options']);

        if ($rows->isEmpty()) {
            return 0;
        }

        // LLM редове: сумираме completion токените и прилагаме каноничната формула веднъж.
        $totalCompletion = (int) $rows->sum(fn ($r) => (int) $r->completion_tokens);
        $credits = BillableUnit::creditsForLlm($this->levelForReservation($reservation), $totalCompletion);

        // Flat/tool редове (без completion токени) → таксуват се по config('billing.flat_costs')
        // (brave/places/perplexity/ocr/avatar), не през markup. Fallback към markup само за непознат.
        foreach ($rows->filter(fn ($r) => (int) $r->completion_tokens <= 0) as $row) {
            $credits += $this->flatCreditsFor($row);
        }

        return $credits;
    }

    /** Flat кредити за един не-LLM ред по provider/kind → config('billing.flat_costs'). */
    private function flatCreditsFor(LlmRequest $row): int
    {
        $tool = match (true) {
            $row->provider === 'brave' => 'brave_search',
            $row->provider === 'google_places' => 'places',
            $row->provider === 'perplexity' => 'perplexity',
            $row->provider === 'mistral' && $row->kind === 'ocr' => 'ocr_page',
            $row->provider === 'comfyui' => 'avatar',
            default => null,
        };

        if ($tool === null) {
            // Непознат flat инструмент → fallback към реалния разход × markup.
            return (int) ceil((float) $row->cost_usd * (float) config('billing.credit_markup', 3.0));
        }

        // OCR се таксува на страница; останалите — по един на ред.
        $units = $tool === 'ocr_page' ? max(1, (int) (data_get($row->options, 'pages', 1))) : 1;

        return BillableUnit::flatCredits($tool) * $units;
    }

    /**
     * Реконсилиация: записва spent_credits, ledger ред type=settle, и refund-ва остатъка
     * (estimate - actual), ако е положителен; ако actual > estimate → допълнителен дебит.
     * Идемпотентен по operation-scoped ключове; status → settled.
     */
    public function settle(CreditReservation $reservation, int $actualSpent, string $outcome = 'completed'): void
    {
        $actualSpent = max(0, $actualSpent);

        DB::transaction(function () use ($reservation, $actualSpent, $outcome) {
            $reservation = CreditReservation::whereKey($reservation->id)->lockForUpdate()->first();
            if (! $reservation || $reservation->status !== 'reserved') {
                return; // вече закрита — идемпотентен no-op
            }

            $estimate = (int) $reservation->estimated_credits;

            // Първо движим баланса (refund/overage), за да отрази settle редът КРАЙНИЯ баланс (W4).
            if ($actualSpent < $estimate) {
                // Връщаме непохарчения остатък в баланса.
                $refund = $estimate - $actualSpent;
                $this->creditWallet($reservation->company_id, $refund);
                $this->ledger($reservation, 'refund', 'credit', $refund, 'refund', "{$reservation->id}:refund");
            } elseif ($actualSpent > $estimate) {
                // Overage — допълнителен дебит (работата вече е извършена) + баланс-движещ ledger ред.
                $extra = $actualSpent - $estimate;
                $this->debitWallet($reservation->company_id, $extra);
                $this->ledger($reservation, 'overage', 'debit', $extra, 'overage', "{$reservation->id}:overage");
            }

            // Информативен ред — реалният разход (баланс ефект 0); пише се ПОСЛЕДЕН → wallet_balance_after
            // отразява крайния баланс след refund/overage.
            $this->ledger($reservation, 'settle', 'debit', $actualSpent, 'run', "{$reservation->id}:settle");

            $reservation->update([
                'spent_credits' => $actualSpent,
                'status' => 'settled',
                'outcome' => $outcome,
                'settled_at' => now(),
                'failed_at' => in_array($outcome, ['failed', 'partial'], true) ? now() : null,
            ]);
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

            $reservation->update(['status' => 'refunded', 'outcome' => 'refunded', 'refunded_at' => now()]);
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
            $balanceAfter = (int) (DB::table('credit_wallets')->where('id', $wallet->id)->value('balance') ?? 0);

            return CreditLedgerEntry::create([
                'credit_wallet_id' => $wallet->id,
                'company_id' => $company->id,
                'reservation_id' => null,
                'type' => $type,                                   // topup | grant
                'origin' => 'system',
                'idempotency_key' => null,
                'direction' => 'credit',
                'amount' => $credits,
                'wallet_balance_after' => $balanceAfter,
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
        // Балансът СЛЕД wallet мутацията, която предхожда този ред (settle = текущ баланс, ефект 0).
        $balanceAfter = (int) (DB::table('credit_wallets')->where('id', $wallet->id)->value('balance') ?? 0);

        return CreditLedgerEntry::firstOrCreate(
            ['idempotency_key' => $key],
            [
                'credit_wallet_id' => $wallet->id,
                'company_id' => $r->company_id,
                'reservation_id' => $r->id,
                'type' => $type,
                'origin' => $r->origin ?? 'manual',   // refund/settle наследяват origin-а на резервацията
                'direction' => $direction,
                'amount' => $amount,
                'wallet_balance_after' => $balanceAfter,
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

    /** Overage позволен ли е: глобален флаг + активен абонамент (планът го разрешава). */
    private function overageAllowed(int $companyId): bool
    {
        if (! config('billing.overage_enabled')) {
            return false;
        }

        return Subscription::where('company_id', $companyId)->where('status', 'active')->exists();
    }

    /** Извежда субект-ключа (тип+id) от полиморфен модел. */
    private function subjectKey(?Model $subject): array
    {
        if (! $subject) {
            return [null, null];
        }

        return [$subject->getMorphClass(), (int) $subject->getKey()];
    }

    private function reserveKey(string $contextType, ?string $subjectType, ?int $subjectId, ?string $opKey = null): string
    {
        $subj = $subjectType ? class_basename($subjectType)."#{$subjectId}" : 'none';
        // $opKey прави ключа уникален per-операция (стабилен при retry, различен при нова операция).
        // Без opKey → uuid, за да не реюзваме чужда (settled) резервация (idempotency бъг 0.1).
        $op = $opKey ?? (string) Str::uuid();

        return "{$contextType}:{$subj}:{$op}";
    }

    /** Snapshot на тарифата към момента на резервацията (за обяснимост след промяна на .env). */
    private function pricingSnapshot(?ModelLevel $level): array
    {
        return [
            'model_level' => $level?->value,
            'star_multiplier' => $level ? BillableUnit::base($level) : null,
            'work_per_token' => (float) config('billing.work_per_token', 1.0),
            'token_divisor' => (int) config('billing.token_divisor', 1000),
            'credit_markup' => (float) config('billing.credit_markup', 3.0),
            'pricing_version' => (string) config('billing.pricing_version', ''),
        ];
    }

    /** Нивото за token→кредити: явно записаното при reserve, иначе config, иначе по контекст. */
    private function levelForReservation(CreditReservation $r): ModelLevel
    {
        if ($r->model_level) {
            return ModelLevel::fromRequest($r->model_level);
        }

        if ($configLevel = config("billing.context_levels.{$r->context_type}")) {
            return ModelLevel::fromRequest($configLevel);
        }

        return match ($r->context_type) {
            'task_run' => $this->taskLevelFromFlowRun((int) $r->subject_id),
            'generation' => optional(AssistantTask::find($r->subject_id))->effectiveStarTier() ?? ModelLevel::Medium,
            'org_planning', 'org_digest', 'interview', 'research', 'director_tick' => ModelLevel::fromRequest(config('organization.manager.level')),
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
