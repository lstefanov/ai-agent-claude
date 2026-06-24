<?php

namespace App\Services\Org\Billing;

use App\Models\Company;
use App\Models\CreditLedgerEntry;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

/**
 * Админ/планови билинг операции (§0.5.4): зареждане на кредити + сетване на план,
 * и месечния grant. Платежният слой е зад PaymentProvider (сега AdminSimulated).
 */
class BillingService
{
    public function __construct(
        private PaymentProvider $payments,
        private CreditMeterService $meter,
    ) {}

    /**
     * Админ действие „Зареди кредити + сложи план" за фирма (§0.5.4). Зачислява кредити
     * (ledger type=topup) и по избор сетва/сменя абонаментния план (тавана max_star_tier).
     */
    public function adminTopUp(Company $company, int $credits, ?Plan $plan = null): void
    {
        DB::transaction(function () use ($company, $credits, $plan) {
            if ($credits > 0) {
                $this->payments->grantCredits($company, $credits, 'topup', ['source' => 'admin']);
            }

            if ($plan) {
                Subscription::updateOrCreate(
                    ['company_id' => $company->id],
                    ['plan_id' => $plan->id, 'status' => 'active', 'current_period_end' => now()->addMonth()],
                );
            }
        });
    }

    /**
     * Месечен grant на включените кредити по плана (§6.1). Извиква се ръчно/планирано;
     * при Stripe фазата го дърпа webhook-ът.
     */
    public function grantMonthly(Subscription $subscription): void
    {
        $plan = $subscription->plan;
        if (! $plan) {
            return;
        }

        DB::transaction(function () use ($subscription, $plan) {
            $this->meter->topup($subscription->company, $plan->monthly_credits, 'grant', [
                'plan' => $plan->key,
                'period_start' => now()->toDateString(),
            ]);

            // Отбелязваме включените за периода + началото на периода на wallet-а.
            $subscription->company->creditWallet()->update([
                'included_this_period' => $plan->monthly_credits,
                'overage_used' => 0,
                'period_start' => now(),
            ]);

            $subscription->update(['current_period_end' => now()->addMonth()]);
        });
    }

    /**
     * Абонира фирмата за план (§6.2) — таксува през PaymentProvider (Stripe или симулиран),
     * записва subscription + зачислява месечните кредити. Неуспешна такса → не активира.
     */
    public function subscribe(Company $company, Plan $plan): Subscription
    {
        $charged = $this->payments->charge($company, $plan->price_cents, ['plan' => $plan->key]);

        $subscription = Subscription::updateOrCreate(
            ['company_id' => $company->id],
            ['plan_id' => $plan->id, 'status' => $charged ? 'active' : 'past_due', 'current_period_end' => now()->addMonth()],
        );

        if ($charged) {
            $this->grantMonthly($subscription);
        }

        return $subscription;
    }

    /** Еднократна покупка кредити (§6.2) — таксува + зачислява. */
    public function topUp(Company $company, int $credits, int $priceCents = 0): CreditLedgerEntry
    {
        $this->payments->charge($company, $priceCents > 0 ? $priceCents : $credits, ['credits' => $credits]);

        return $this->meter->topup($company, $credits, 'topup', ['source' => 'purchase']);
    }

    /**
     * Stripe webhook (§6.2): подновяване → grantMonthly; неуспешно плащане → past_due; отказ.
     */
    public function handleWebhook(array $payload): void
    {
        $type = (string) ($payload['type'] ?? '');
        $companyId = $payload['data']['object']['metadata']['company_id'] ?? null;
        if (! $companyId) {
            return;
        }

        $company = Company::find($companyId);
        $subscription = $company?->subscription;
        if (! $subscription) {
            return;
        }

        match ($type) {
            'invoice.paid', 'invoice.payment_succeeded' => $this->grantMonthly($subscription),
            'invoice.payment_failed' => $subscription->update(['status' => 'past_due']),
            'customer.subscription.deleted' => $subscription->update(['status' => 'canceled']),
            default => null,
        };
    }
}
