---
name: verify-changes
description: >
  Use this skill to verify FlowAI changes with only the allowed commands, for requests like
  "verify this", "check the changes are sound", "lint my edits", or "how do I validate
  without tests". Encodes the allowed verification set, the forbidden commands, and a checklist.
---

# Verify FlowAI changes

Verify changes using only the sanctioned commands.

## Allowed

- `php -l path/to/file.php` for each changed PHP file.
- `git diff --check -- path/to/file` for whitespace and conflict markers.
- Targeted `rg`, `sed`, `find`, `git diff`, `git status`, `git show`, and static inspection.
- `composer validate` only when the change affects Composer metadata.
- `npm run build` only when frontend assets changed and the user did not forbid it.

## Forbidden

- Any test runner: `php artisan test`, `phpunit`, `composer test`, Pest, or equivalent.
- Eval commands such as `flows:run-evals` as normal verification; run those only when the user explicitly asks for that operation.
- Formatters that rewrite files, unless formatting is part of the requested work.

## After code or config changes

- Run `php artisan horizon:terminate` so Horizon restarts with new code.
- Use `composer dev` locally when queued jobs must process.

## Final sanity checklist

- No forbidden test or eval command was run.
- Changed PHP files pass `php -l`.
- `git diff --check` passes for changed files.
- No generated file or `CHANGELOG.md` was hand-edited.
- No legacy compatibility path was retained when behavior was replaced.
- Controllers stayed thin and canonical services own the behavior.
- Billing uses `BillableOperationService` or `CreditMeterService`; Drawflow work uses `GraphNormalizer`.

## Output

State exactly which allowed checks you ran and their results.
Never substitute a forbidden command.
