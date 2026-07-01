# FlowAI Review Checklists

Use this reference before planning a non-trivial change, reviewing a diff, or doing a final guardrail pass.
Do not import Claude-only commands or hooks into Codex behavior.
Do not run project tests or eval suites.

## Planning Checklist

- Read `AGENTS.md` and relevant code before planning.
- Trace every touchpoint across routes, controllers, services, jobs, models, config, views, and docs.
- Identify the canonical service boundary before editing.
- Keep controllers thin.
- Route LLM access only through provider services.
- Validate and normalize every LLM-produced structure.
- Route graph and Drawflow structure through `GraphNormalizer` and `AgentGeneratorService`.
- Put long-running or LLM work in Horizon jobs.
- Use the correct queue: `flows`, `org`, or `default`.
- Remove old behavior when replacing it.
- State the non-test verification path for every change.

## Code Review Checklist

- Check correctness and obvious behavioral regressions.
- Check service boundaries and controller thinness.
- Check provider calls stay behind `GeneratorService`, `OpenAiChatService`, `AnthropicChatService`, or `OllamaService`.
- Check connector calls stay behind `McpClientService`.
- Check metered work goes through `BillableOperationService` or `CreditMeterService`.
- Check every LLM output is validated or normalized in deterministic PHP.
- Check graph changes go through `GraphNormalizer`.
- Check long-running work is queued through Horizon.
- Check retries and terminal states are idempotent.
- Check N+1 risks and missing eager loads.
- Check return types, error handling, and failed state behavior.
- Check no legacy or compatibility path remains after replacement.
- Check no generated file or `CHANGELOG.md` was manually edited.
- Check no em dash character was introduced.
- Check long edited Markdown uses one full sentence per physical line.

## Security Review Checklist

Use this checklist when the change touches auth, user input, uploads, LLM prompts or outputs, webhooks, crawling, URLs, connectors, billing, or the database.

- Check authorization, guards, gates, policies, and middleware.
- Check company scoping so one company cannot read another company's flows, runs, billing, connectors, or knowledge.
- Check mass assignment and fillable or guarded settings.
- Check raw queries and bindings for SQL injection.
- Check Blade output and JSON payloads for XSS risk.
- Check webhook authentication and signature validation.
- Check secrets are not logged, serialized, or committed.
- Check arbitrary URL, crawl, and web-search paths use `SsrfGuard`.
- Check prompt-injected external content cannot trigger unsafe tool calls without deterministic policy gates.
- Check MCP write tools honor approval and `OrgActPolicy`.
- Check payment webhooks credit through ledger-safe services only.

## Guardrail Diff Review

Run this as a read-only mental pass over `git diff` and `git diff --staged`.

- No tests were added.
- No forbidden test command is part of the workflow.
- No eval command is used as normal verification.
- No generated files or changelogs were hand-edited.
- No legacy path remains when behavior was replaced.
- No direct wallet debit exists.
- No connector bypass exists.
- No Drawflow parser bypass exists.
- No stray queue worker path exists.
- No hardcoded provider call appears outside provider services.
- No UI token bypass appears in Blade when UI was touched.

## Final Verification Checklist

- Run `php -l` only for changed PHP files.
- Run `git diff --check -- <changed files>`.
- Use targeted `rg`, `sed`, `find`, `git diff`, and static inspection.
- Run `npm run build` only if frontend assets changed and the user did not forbid it.
- Do not run Pint unless formatting is explicitly part of the requested work.
- Do not run tests.
- Do not run eval suites.
- Report exactly what was checked and what was not checked.
