<?php

namespace App\Services\Org\Billing;

use App\Models\Company;
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
}
