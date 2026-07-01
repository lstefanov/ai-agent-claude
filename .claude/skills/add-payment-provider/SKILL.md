---
name: add-payment-provider
description: >
  Use this skill when adding a payment provider in FlowAI, for requests like "add a payment
  provider", "integrate a payment gateway", "wire top-ups", or "handle a payment webhook".
  Covers the PaymentProvider contract and settlement through CreditMeterService.
---

# Add a FlowAI payment provider

Add a provider that funds credit wallets.

## Files to touch

- `app/Services/Org/Billing/XxxPaymentProvider.php` - the provider.
- A webhook route when the provider confirms asynchronously, mirroring the `stripe/webhook` route.

## Steps

1. Implement the `PaymentProvider` contract, modeling on `StripePaymentProvider` and `AdminSimulatedPaymentProvider`.
2. On confirmed payment, credit the wallet only through `CreditMeterService`.
3. Wire the webhook route for asynchronous confirmation and verify the signature.

## Guardrails

- Never write to a `CreditWallet` directly; settle through `CreditMeterService` so the ledger stays consistent.
- Keep secrets in environment variables.

## Verify

- `php -l` on the provider.
- Do not run tests or eval suites.
