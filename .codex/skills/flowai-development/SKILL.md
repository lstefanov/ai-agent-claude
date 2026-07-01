---
name: flowai-development
description: Project-specific engineering workflow for the FlowAI Laravel app in /Users/lub/Sites/localhost/ai-agent-claude. Use when Codex works on FlowAI planning, implementation, debugging, code review, services, jobs, queues, billing, MCP connectors, knowledge, AI Organization, flow execution, GraphNormalizer, Horizon, Ollama, or repository verification.
---

# FlowAI Development

## Start Here

Use this skill for engineering work in the FlowAI repository.
Treat `AGENTS.md` as authoritative and this skill as the fast operating guide.

First inspect the current code path before planning edits.
Use `rg`, `sed`, `git diff`, and focused static reads before touching files.
For bugs, reproduce the behavior as close to the end user path as the repo rules allow, usually through UI/manual flow inspection, logs, job state, or static tracing.

## Non-Negotiables

- Do not add tests, test files, or test cases.
- Do not run tests, including `php artisan test`, `phpunit`, `composer test`, or equivalents.
- Do not run eval suites unless the user explicitly asks for that exact operation.
- Do not edit `CHANGELOG.md` or generated files.
- Do not keep legacy or backward compatibility paths when replacing behavior.
- Do not use `queue:work` or `queue:listen`; Horizon is the only worker path.
- Do not debit wallets directly; use `BillableOperationService`.
- Do not parse or write Drawflow payloads outside `GraphNormalizer`.
- Do not introduce em dash characters.
- Keep long Markdown sentence-per-line when writing or substantially editing docs.

## Reference Routing

- Read `references/architecture.md` when choosing service boundaries, models, jobs, routes, or ownership.
- Read `references/workflows.md` when changing or debugging planning, runtime, AI Organization, billing, MCP, knowledge, Horizon, or Ollama flows.
- Read `references/task-runbooks.md` when the task matches a common FlowAI operation such as adding an agent, tool, route, job, connector, billable operation, provider, migration, org mutation, knowledge ingest path, or graph-format change.
- Read `references/review-checklists.md` before planning a non-trivial change, reviewing a diff, or doing final guardrail verification.
- Read `references/verification.md` before final verification or when deciding which commands are allowed.

## Task Routing

- Agent type or runtime agent behavior: use the `add-agent` runbook.
- Agent callable tool: use the `add-agent-tool` runbook.
- Route, controller, or page: use the `add-http-surface` runbook.
- Background work: use the `add-queued-job` runbook.
- Model, table, column, or migration: use the `add-model-migration` runbook.
- Cloud model backend or provider routing: use the `add-llm-provider` runbook.
- External-app action integration: use the `add-mcp-connector` runbook.
- Metered credit work: use the `add-billable-operation` runbook.
- Payment or top-up provider: use the `add-payment-provider` runbook.
- AI Organization structure change: use the `add-org-mutation` runbook.
- Company knowledge ingest or embeddings: use the `knowledge-ingest` runbook.
- Dynamic planner or DAG hardening: use the `planner-hardening` runbook.
- Drawflow persistence or graph payload shape: use the `graph-normalizer-work` runbook.
- Stuck runs, stalled queues, or Horizon issues: use the `debug-stuck-flow` runbook.
- Diff review against project rules: use `references/review-checklists.md`.

## Working Pattern

1. Ground in repo truth.
2. Identify the canonical service or boundary before editing.
3. Keep controllers thin and push business logic into existing services or jobs.
4. Treat LLM output as untrusted and harden it in deterministic PHP.
5. Preserve queued, observable execution through Horizon.
6. Verify only with allowed commands and static inspection.

## Repo-Local Install Note

This skill is intentionally repo-local under `.codex/skills`.
If Codex does not auto-discover repo-local skills in a future session, copy or symlink this folder into `~/.codex/skills`.
