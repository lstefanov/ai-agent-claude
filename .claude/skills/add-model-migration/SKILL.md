---
name: add-model-migration
description: >
  Use this skill when adding a database model or migration in FlowAI, for requests like
  "add a model", "create a migration", "add a table", or "add a column". Covers app/Models,
  database/migrations, and the no-back-compat reset convention.
---

# Add a FlowAI model or migration

Add an Eloquent model and its migration.

## Files to touch

- `app/Models/Xxx.php` - the model.
- `database/migrations/xxxx_*.php` - the migration.
- Seeders when the data is part of the seeded baseline.

## Steps

1. Create the migration with the full target schema.
2. Create or update the Eloquent model with fillable, casts, and relations.
3. Apply with `php artisan migrate`, or `php artisan migrate:fresh --seed` to reset and reseed.

## Guardrails

- Do not keep legacy or back-compat paths; the owner resets the DB instead of migrating old data.
- When you replace behavior, delete the old column, table, or code path rather than bridging it.
- Do not hand-edit files marked auto-generated.

## Verify

- `php -l` on the model and migration.
- `git diff --check` on changed files.
- Do not run tests or eval suites.
