---
name: add-billable-operation
description: >
  Use this skill when adding metered or credit-charged work in FlowAI, for requests like
  "make this billable", "charge credits for X", "add a metered operation", or "reserve and
  settle credits". Covers BillableOperationService, CreditMeterService, and the ledger rules.
---

# Add a FlowAI billable operation

Meter a unit of work so it reserves and settles credits correctly.

## Files to touch

- The service or job doing the work.
- `app/Services/Org/Billing/BillableOperationService.php` is the entry point you call, not one you bypass.

## Steps

1. Wrap the work with `BillableOperationService`, the canonical reserve-and-settle entry point.
2. Let `CreditMeterService` reserve, settle, refund, and write ledger rows.
3. Choose the gate behavior via `BillingGatePolicy`, hard gate versus soft best effort.

## Guardrails

- Never debit a `CreditWallet` directly.
- `credit_ledger` is append-only; never mutate past ledger rows.
- `CreditReservation` holds the mutable state for one operation while the ledger is the audit log.
- Paid LLM usage accrues in `LlmUsage`.

## Verify

- `php -l` on changed files.
- Do not run tests or eval suites.
