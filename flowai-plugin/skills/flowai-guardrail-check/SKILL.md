---
name: flowai-guardrail-check
description: >
  Use this skill to review a change against FlowAI's non-negotiable rules before committing,
  for requests like "check my diff", "does this follow our conventions", "review against the
  rules", or "guardrail check". Reads the working diff and reports violations.
---

# FlowAI guardrail check

Review the current change against the repository's non-negotiable rules.

## How to run

1. Get the working diff with `git diff` and `git diff --staged`.
2. Inspect changed files against each rule below.
3. Report violations as `file:line`, the rule broken, and the fix.
   Do not run tests or eval suites as part of this check.

## Rules to enforce

- No tests: no new test files, no new test cases, no test runner such as `php artisan test`, `phpunit`, or `composer test`.
- No hand-edits to `CHANGELOG.md` or files marked auto-generated.
- No legacy or back-compat paths when replacing behavior; the old path must be deleted.
- No em dash characters anywhere; a plain hyphen is required.
- Long Markdown that was written or substantially edited must have one full sentence per physical line.
- Service boundaries hold: provider calls behind services, connector calls behind `McpClientService`, metered work through `BillableOperationService`, Drawflow through `GraphNormalizer`.
- No direct `CreditWallet` debit; `credit_ledger` stays append-only.
- Org connector writes respect `OrgActPolicy`; autonomous work respects `AutonomousBudgetService`.
- Controllers stay thin; long-running work lives in queued jobs.

## Output

Group findings by severity, each with file, line, the rule, and a concrete fix.
