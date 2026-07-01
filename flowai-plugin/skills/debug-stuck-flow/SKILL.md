---
name: debug-stuck-flow
description: >
  Use this skill when a FlowAI flow run is stuck or jobs are not processing, for requests
  like "flow is stuck", "run will not finish", "jobs are not running", "provider or Ollama
  issue", or "debug Horizon or queues". Covers the watchdog, Horizon, environment, and models.
---

# Debug a stuck FlowAI run

Diagnose stuck flow runs, stalled queues, and provider routing.

## Runbook

1. Detect stuck runs: `php artisan flows:watchdog`.
2. Clean them up: `php artisan flows:cancel-stuck`.
3. Check workers: `php artisan horizon:status`.
   Restart after code or config changes with `php artisan horizon:terminate`.
4. Confirm only Horizon is running workers; no stray `queue:work` or `queue:listen`.

## Environment checks

- Queue and cache are Redis via the `predis` client; confirm Redis is up.
- Horizon supervisors cover the `flows`, `org`, and `default` queues.
- When jobs do not move, inspect `.env`, `config/horizon.php`, `config/queue.php`, Redis state, `failed_jobs`, and the app logs.
- Check the active environment before chasing auth, guard, or session issues; local `.env` uses `SESSION_DRIVER=file` while defaults use database sessions.
- Use `composer dev` locally so jobs actually process.

## Provider and model checks

- Trace model routing through `ModelRouterService`, `ModelSelectorService`, `OllamaService`, and `config/model_router.php`.
- Paid-prefixed providers stay behind the existing provider services; there must be no direct provider HTTP calls from controllers, jobs, or views.

## Guardrails

- Do not run tests or eval suites while debugging.
- See `docs/HORIZON-REDIS.md`.
