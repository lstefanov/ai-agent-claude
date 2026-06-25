<?php

namespace App\Services\Org\Billing;

use App\Models\Company;

/**
 * Админ-симулирано зареждане (§0.5.4) — БЕЗ Stripe код. Просто зачислява кредити
 * през CreditMeterService::topup. Stripe е по-късен drop-in (Фаза 6).
 */
class AdminSimulatedPaymentProvider implements PaymentProvider
{
    public function __construct(private CreditMeterService $meter) {}

    /** Няма реален процесор — симулираме успешна такса. */
    public function charge(Company $company, int $amountCents, array $meta = []): bool
    {
        return true;
    }

    public function grantCredits(Company $company, int $credits, string $type = 'topup', array $meta = []): void
    {
        $this->meter->topup($company, $credits, $type, $meta);
    }
}
