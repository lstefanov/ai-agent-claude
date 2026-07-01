# CLAUDE.md

Operational guidance for working in this repository.

## Non-Negotiable Rules

- Do NOT add tests.
- Do NOT write new test files or new test cases.
- Do NOT run tests.
- Forbidden test commands include `php artisan test`, `phpunit`, `composer test`, and any equivalent test runner.
- Do NOT run eval suites as verification unless the user explicitly asks for that specific operation.
- Do NOT manually edit `CHANGELOG.md` files or files marked as auto-generated.
- Do NOT keep legacy or back-compat paths when replacing behavior.
- The owner resets the DB instead of migrating old data, so delete the old path when replacing logic.
- Never use the em dash character.
- Use a plain hyphen instead.
- When writing or substantially editing long Markdown files, put each full sentence on its own physical line.
- Make technical decisions for quality, simplicity, robustness, scalability, and long term maintainability.
- Do not optimize primarily for development cost.

## Product

This is a Laravel 12 and PHP 8.2 web app for building and running multi-agent AI workflows.
Users define a `Company`, describe a `Flow` in free text, and let `FlowPlannerService` design a DAG of agents.
The admin app reviews and edits that DAG in a Drawflow builder.
The runtime executes saved DAGs with real parallelism through queued jobs.

The app has three major surfaces:

- Admin app: `routes/web.php`, root `APP_DOMAIN`, full builder and operational controls, `is_admin` gated.
- Client portal: `routes/client.php`, mounted on `CLIENT_DOMAIN` or `/client`, simplified business-facing UX, `client_auth` gated.
- AI Organization: a workforce layer where managers, directors, and assistants compile work into flows.

## Runtime

- Backend: Laravel 12, PHP 8.2.
- Frontend: Blade, Vite, Tailwind CSS v4, Alpine.js, and Drawflow for the admin graph builder.
- Database: SQLite by default for app data, `job_batches`, and `failed_jobs`.
- Sessions: local `.env` currently uses `SESSION_DRIVER=file`.
- Session defaults: `.env.example` and `config/session.php` default to database sessions.
- Always check the active environment before debugging auth, guard, or session issues.
- Queue and cache: Redis with the `predis` client.
- Queue workers: Laravel Horizon only.
- Horizon supervisors cover `flows`, `org`, and `default` queues.
- Do not use stray `queue:work` or `queue:listen` processes.
- Use `composer dev` for local dev when queued jobs must process.
- Background jobs need Horizon running.

## Core Systems

- Flow planning starts in `FlowPlannerService` and provider selection starts in `GeneratorService`.
- Planned graphs are hardened in `AgentGeneratorService`.
- `PlanGraphBuilder` assembles planner output into graph shape.
- `GraphNormalizer` is the only place that should understand the Drawflow export format.
- `FlowVersionService` snapshots and restores flow templates.
- `GraphFlowExecutor` runs DAG waves and dispatches node jobs.
- `NodeExecutorService` runs one node and delegates execution helpers in `app/Services/Execution/`.
- `AgentLoop` is the shared agent step and tool-calling loop.
- `ModelRouterService` chooses cloud providers for agent nodes by task profile and `config/model_router.php`.
- `ModelSelectorService` chooses local Ollama models.
- `OllamaService` owns runtime routing for local and paid-prefixed models.
- `PlanLibraryService` stores proven plans for future planning examples.
- `FlowMemoryService` stores run-to-run flow memory and dedup lessons.

## When Changing Code

- Prefer existing Laravel structure, service boundaries, and local helper APIs.
- Keep controllers thin.
- Put long-running or background work in queued jobs.
- Keep LLM provider calls behind services such as `GeneratorService`, `OpenAiChatService`, `AnthropicChatService`, and `OllamaService`.
- Keep connector calls behind `McpClientService`.
- Use `BillableOperationService` for metered work.
- Never debit credit wallets directly.
- Use `GraphNormalizer` for Drawflow payloads.
- Respect `OrgActPolicy` for real-world connector writes.
- Treat planner output as untrusted.
- Validate and normalize LLM structure in deterministic code, especially in `AgentGeneratorService`.
- Use Laravel Pint for formatting only when formatting is part of the requested work and does not conflict with these rules.

## Allowed Verification

- `php -l path/to/file.php` is allowed for changed PHP files.
- `git diff --check -- path/to/file` is allowed.
- Targeted `rg`, `sed`, `find`, and static inspection are allowed.
- `npm run build` is allowed only when frontend assets were changed and the user did not forbid it.
- Test commands remain forbidden.
- Eval commands remain forbidden as verification.

## Important Paths

- `app/Services/`: core orchestration, planning, execution, billing integration, knowledge, MCP, and organization services.
- `app/Services/Execution/`: node prompt building, FlowNode to Agent bridging, QA gate, and adaptive replanning.
- `app/Services/Knowledge/`: ingest, synthesis, facts, gaps, conflicts, requirements, chat, extraction, and chunking.
- `app/Services/Org/`: AI Organization domain services.
- `app/Services/Org/Billing/`: credit reservations, ledger settlement, budget gates, and payment providers.
- `app/Agents/`: agent implementations and tools.
- `app/Jobs/`: background jobs for flows, planning, knowledge, eval, wizard, and assistant work.
- `app/Jobs/Org/`: organization jobs on the `org` queue.
- `app/Models/`: Eloquent models for flow, org, billing, knowledge, connectors, eval, and users.
- `app/Http/Controllers/`: admin, client, org, flow, knowledge, connector, stats, eval, and auth controllers.
- `app/Support/`: pure helpers and value objects.
- `routes/web.php`: admin app, builder, flow runs, knowledge, connectors, stats, models, and admin routes.
- `routes/client.php`: client portal and AI Organization routes.
- `routes/api.php`: webhook trigger routes.
- `routes/console.php`: scheduler routes.
- `config/`: routing, providers, billing, organization, MCP, agent types, model routing, stats, queues, and services.
- `resources/views/`: Blade views for admin and client surfaces.
- `docs/`: detailed architecture and operational documentation.

## Flow Lifecycle

1. A user describes a flow.
2. `flows/generate-agents` starts a background `flows:generate-agents` command.
3. `FlowPlannerService` runs intent analysis, pipeline design, and critique.
4. `AgentGeneratorService` hardens the plan.
5. The builder renders the DAG for review and editing.
6. Saving the graph goes through `GraphNormalizer` into `flow_nodes` and `flow_edges`.
7. Running a flow creates a `FlowRun`.
8. `GraphFlowExecutor` computes waves and dispatches `ExecuteNodeJob` batches on the `flows` queue.
9. `NodeExecutorService` runs each node through local Ollama or paid-prefixed providers.
10. Successful runs can promote plans, distill flow memory, and harvest company knowledge.

## AI Organization

- The AI Organization layer lives on top of the flow engine.
- Main services live in `app/Services/Org/`.
- Jobs live in `app/Jobs/Org/` and run on the `org` queue.
- Controllers live in `app/Http/Controllers/Client/Org/`.
- Configuration lives in `config/organization.php`.
- `OrgVersion` is the immutable org structure snapshot.
- `OrgMember` is the stable identity for managers, directors, and assistants.
- `Director` and `Assistant` are placement rows inside an org version.
- `AssistantTask` is the reusable work unit that can generate and run a `Flow`.
- Mutations flow through `OrgProposal` and are recorded in `OrgEvent`.
- Autonomous work must pass `AutonomousBudgetService` caps.
- Manual chat, manual ticks, and manual task runs bypass autonomous budget caps.

## Billing

- Billing data lives around `CreditWallet`, `CreditReservation`, `CreditLedgerEntry`, `Plan`, and `Subscription`.
- `CreditReservation` is the mutable state for one metered operation.
- `credit_ledger` is the append-only audit log.
- `BillableOperationService` is the canonical reserve and settle entry point.
- `CreditMeterService` reserves, settles, refunds, and records ledger rows.
- `BillingGatePolicy` decides hard gate versus soft best effort.
- `LlmUsage` accumulates paid-provider token usage and pricing.
- Admin simulated payments are available now.
- Stripe support exists behind `StripePaymentProvider` and the `stripe/webhook` route.

## Knowledge

- Knowledge base v2 is a company RAG system.
- Main entry points are `KnowledgeService` and `app/Services/Knowledge/`.
- Resources flow through `KnowledgeResource`, `KnowledgePage`, `KnowledgeChunk`, and `KnowledgeFact`.
- Gaps, conflicts, folders, events, and chat are modeled separately.
- URL ingest uses `CrawlService`, `WebPageCacheService`, `web_page_cache`, and `web_page_digests`.
- Embeddings are shared with flow memory through `EmbeddingService`.
- The planner treats company knowledge as supplementary.
- Research agents should keep web tools when needed.

## MCP

- MCP connectors are used for external-app actions.
- Main entry point: `McpClientService`.
- Connector contracts and implementations live in `app/Services/Mcp/`.
- Company connector state lives in `CompanyConnector`.
- Every call is audited in `ConnectorToolLog`.
- `SsrfGuard` blocks unsafe internal targets.
- Write tools must honor organization act-mode policy.
- See `docs/MCP-CONNECTORS.md` for details.

## Eval

- Eval exists for flow regression checks.
- Core models are `FlowEvalCase` and `FlowEvalRun`.
- Jobs are `RunFlowEvalJob` and `JudgeEvalRunJob`.
- Service entry point is `EvalRunnerService`.
- UI entry point is `FlowEvalController`.
- `flows:run-evals` is an existing scheduled command, but do not run it as normal verification.
- See `docs/EVAL-SUITE.md` for details.

## Common Commands

- `composer dev`: server, Horizon, scheduler, log tailer, and Vite concurrently.
- `php artisan serve`: web server only.
- `php artisan horizon`: start Horizon workers.
- `php artisan horizon:terminate`: restart Horizon after code or config changes.
- `php artisan horizon:status`: inspect Horizon status.
- `php artisan flows:watchdog`: detect stuck flow runs.
- `php artisan flows:cancel-stuck`: clean stuck flow runs.
- `php artisan org:director-ticks --ticks`: dispatch autonomous director ticks.
- `php artisan org:review`: run organization review.
- `php artisan org:digest`: run organization digest.
- `php artisan flows:run-evals`: scheduled eval command, not normal verification.
- `php artisan knowledge:detect-conflicts`: scan knowledge conflicts.
- `npm run dev`: Vite dev server.
- `npm run build`: Vite build when frontend assets changed.
- `php artisan migrate`: apply migrations.
- `php artisan migrate:fresh --seed`: reset DB and seed models, templates, and plans.

## Reference Docs

- `docs/README.md`: index and map of all documentation.
- `docs/DYNAMIC-AGENT-PLANNER.md`: planner design.
- `docs/HORIZON-REDIS.md`: Horizon and Redis queue operations.
- `docs/MCP-CONNECTORS.md`: connector registry and action behavior.
- `docs/KNOWLEDGE.md`: company knowledge base v2 (RAG) reference.
- `docs/BILLING.md`: credits and billing reference.
- `docs/EVAL-SUITE.md`: eval suite.
- `docs/CLIENT-PORTAL-REVAMP-PLAN.md`: canonical client portal and org plan.
- `docs/AI-ORGANIZATION-*`: AI Organization design and progress docs.
- `docs/UI-UX-REDESIGN-PLAN.md`: UI and UX redesign plan.
- `docs/MULTI-AGENT-DEV-WORKFLOW.md`: multi-agent development workflow.
- `docs/EVAL-SUITE-USER-GUIDE.md` and `docs/MCP-CONNECTORS-USER-GUIDE.md`: end-user guides.
- `docs/archive/`: superseded docs.
