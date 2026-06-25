<?php

namespace App\Services\Org\Billing;

use App\Models\Company;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Реален процесор (Stripe) — drop-in зад PaymentProvider (§6.2). Активира се само когато
 * STRIPE_SECRET е зададен (иначе binding-ът остава на AdminSimulated). Без SDK зависимост —
 * директни Http повиквания. Кредитирането минава през CreditMeterService (метерингът не се пипа).
 */
class StripePaymentProvider implements PaymentProvider
{
    public function __construct(private CreditMeterService $meter) {}

    public function charge(Company $company, int $amountCents, array $meta = []): bool
    {
        $secret = (string) config('billing.stripe.secret');
        if ($secret === '') {
            return false;
        }

        try {
            $response = Http::withToken($secret)->asForm()->post('https://api.stripe.com/v1/payment_intents', [
                'amount' => max(1, $amountCents),
                'currency' => 'bgn',
                'confirm' => 'true',
                'metadata[company_id]' => $company->id,
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('[Stripe] charge failed: '.$e->getMessage());

            return false;
        }
    }

    public function grantCredits(Company $company, int $credits, string $type = 'topup', array $meta = []): void
    {
        // Плащането е потвърдено → кредитираме през метеринга (същата машина като админ-симулацията).
        $this->meter->topup($company, $credits, $type, $meta);
    }
}
