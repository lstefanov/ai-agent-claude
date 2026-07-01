---
name: add-queued-job
description: >
  Use this skill when adding a background job in FlowAI, for requests like "add a job",
  "queue this work", "run this in the background", or "make this async". Covers app/Jobs,
  the flows/org/default Horizon queues, and the idempotency and billing rules.
---

# Add a FlowAI queued job

Move long-running or background work into a queued job on the right Horizon queue.

## Files to touch

- `app/Jobs/XxxJob.php`, or `app/Jobs/Org/XxxJob.php` for organization work.

## Steps

1. Create the job class and put the long-running logic there, keeping controllers thin.
2. Assign it to the correct queue: `flows` for flow execution, `org` for organization work, `default` otherwise.
   Horizon supervisors cover exactly these three.
3. Make the job idempotent so retries are safe.
4. If the job does metered work, reserve and settle through `BillableOperationService`.

## Guardrails

- Only Horizon runs workers; never rely on a stray `queue:work` or `queue:listen`.
- After changing job code or config, run `php artisan horizon:terminate` so Horizon restarts with the new code.
- Use `composer dev` locally when queued jobs must actually process.
- See `docs/HORIZON-REDIS.md`.

## Verify

- `php -l` on the job.
- Do not run tests or eval suites.
