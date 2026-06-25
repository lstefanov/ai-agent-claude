<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CreditWallet;
use App\Models\Plan;
use App\Services\Org\Billing\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Кредити & планове (§6.5). Платежният канал е зад PaymentProvider (Stripe drop-in, иначе
 * админ-симулиран). Webhook-ът е извън client_auth, валидиран по подпис.
 */
class BillingController extends Controller
{
    public function index()
    {
        $company = $this->company();
        $wallet = $company->creditWallet ?? CreditWallet::firstOrCreate(['company_id' => $company->id]);

        return view('client.org.billing', [
            'company' => $company,
            'wallet' => $wallet,
            'ledger' => $wallet->ledger()->latest('id')->take(20)->get(),
            'plans' => Plan::where('is_active', true)->orderBy('price_cents')->get(),
            'subscription' => $company->subscription?->load('plan'),
        ]);
    }

    public function subscribe(Request $request, BillingService $billing): JsonResponse
    {
        $plan = Plan::where('key', (string) $request->input('plan'))->where('is_active', true)->firstOrFail();
        $subscription = $billing->subscribe($this->company(), $plan);

        return response()->json(['ok' => true, 'status' => $subscription->status, 'plan' => $plan->key]);
    }

    public function topUp(Request $request, BillingService $billing): JsonResponse
    {
        $credits = (int) $request->input('credits', 0);
        if ($credits <= 0) {
            return response()->json(['ok' => false, 'error' => 'Невалидно количество кредити.'], 422);
        }

        $company = $this->company();
        $billing->topUp($company, $credits);

        return response()->json(['ok' => true, 'balance' => (int) $company->creditWallet()->value('balance')]);
    }

    /** Stripe webhook — извън client_auth (валидиран по подпис). */
    public function webhook(Request $request, BillingService $billing)
    {
        $secret = (string) config('billing.stripe.webhook_secret');
        if ($secret !== '' && ! $this->validSignature($request, $secret)) {
            return response('invalid signature', 400);
        }

        $billing->handleWebhook($request->all());

        return response('ok');
    }

    /** Минимална Stripe-Signature HMAC проверка (когато webhook secret е зададен). */
    private function validSignature(Request $request, string $secret): bool
    {
        $header = (string) $request->header('Stripe-Signature');
        if ($header === '') {
            return false;
        }
        parse_str(str_replace(',', '&', $header), $parts);
        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        return hash_equals($expected, (string) $signature);
    }

    private function company(): Company
    {
        return Company::findOrFail((int) session('client_company_id'));
    }
}
