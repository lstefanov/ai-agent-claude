# FlowAI Verification Rules

## Forbidden Verification

- Do not run `php artisan test`.
- Do not run `phpunit`.
- Do not run `composer test`.
- Do not run Pest, PHPUnit wrappers, or equivalent test runners.
- Do not add test files or test cases.
- Do not run eval suites unless the user explicitly asks for that exact operation.
- Do not run `php artisan flows:run-evals` as normal verification.
- Do not run formatters that rewrite files unless formatting is part of the requested work.

## Allowed Static Commands

- Use `php -l path/to/file.php` for changed PHP files.
- Use `git diff --check -- path/to/file` for changed files.
- Use `rg`, `sed`, `find`, `git diff`, `git status`, `git show`, and static source inspection.
- Use `composer validate` only if the change affects Composer metadata.
- Use `npm run build` only when frontend assets were changed and the user did not forbid it.

## Manual Product Checks

Use manual or UI-aligned checks when a bug fix needs end-user reproduction.
For flow execution, inspect the route, controller, job, run state, node state, logs, and Horizon status.
For builder changes, manually reason through generate, save, reload, and run paths.
For org changes, inspect task, proposal, decision, run, billing, and act-policy state transitions.
For MCP write changes, inspect approval and act-mode behavior before any real connector call.

## Final Sanity Checklist

- Confirm no forbidden test or eval command was run.
- Confirm changed PHP files pass `php -l` when PHP was edited.
- Confirm `git diff --check` passes for changed files.
- Confirm no generated file or `CHANGELOG.md` was manually edited.
- Confirm no legacy compatibility path was retained when behavior was replaced.
- Confirm controllers stayed thin and canonical services own the behavior.
- Confirm billing uses `BillableOperationService` or `CreditMeterService`.
- Confirm Drawflow payload work uses `GraphNormalizer`.
