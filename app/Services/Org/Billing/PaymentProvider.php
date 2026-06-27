<?php

namespace App\Services\Org\Billing;

use App\Models\Company;

/**
 * Платежен слой — интерфейс от старта (§0.5.4). Сега: AdminSimulatedPaymentProvider
 * (нищо външно). Stripe е по-късен drop-in (Фаза 6) — сменя се само binding-ът.
 */
interface PaymentProvider
{
    /** Таксува реална сума (центове) за зареждане на кредити. Връща дали е успешно. */
    public function charge(Company $company, int $amountCents, array $meta = []): bool;

    /** Зачислява кредити към wallet-а на фирмата (платено или безплатно). */
    public function grantCredits(Company $company, int $credits, string $type = 'topup', array $meta = []): void;
}
