# FlowAI Architecture

## Product Shape

FlowAI is a Laravel 12 and PHP 8.2 app for building and running multi-agent AI workflows.
Users define a `Company`, describe a `Flow`, and let `FlowPlannerService` design a DAG of agents.
The admin app reviews and edits the DAG in a Drawflow builder.
The runtime executes saved DAGs through queued jobs with real parallelism.
The client portal adds a business-facing AI Organization layer on top of the flow engine.

## Surfaces

- Admin app: `routes/web.php`, root `APP_DOMAIN`, admin-gated builder and operations.
- Client portal: `routes/client.php`, `CLIENT_DOMAIN` or `/client`, business-facing UX, `client_auth` gated.
- Webhooks and API entrypoints: `routes/api.php`.
- Scheduler and commands: `routes/console.php` and `app/Console/Commands`.

## Planning Boundary

- `FlowPlannerService` performs intent analysis, pipeline design, critique, capability selection, and provider pinning.
- `GeneratorService` starts provider selection for planning and lightweight AI assist.
- `AgentGeneratorService` hardens planner output and enforces deterministic guarantees.
- `PlanGraphBuilder` assembles hardened plans into Drawflow export shape.
- `GraphNormalizer` is the only component that should understand Drawflow export format.
- `FlowVersionService` snapshots and restores flow templates.
- `PlanLibraryService` stores proven plans for future planning examples.
- `FlowMemoryService` stores run-to-run flow memory and deduplicated lessons.

## Runtime Boundary

- `GraphFlowExecutor` computes DAG waves, dispatches node jobs, finalizes runs, fails terminal runs, and resumes after approvals.
- `ExecuteFlowJob` enters the flow runtime.
- `ExecuteNodeJob` runs one node through `NodeExecutorService`.
- `NodeExecutorService` owns single-node execution and delegates prompt, bridge, QA, and replanning helpers in `app/Services/Execution`.
- `AgentLoop` is the shared agent loop for tool-calling and iterative agent steps.
- `FinalComposerService` composes final run output.
- `DeliveryService` handles best-effort delivery after successful runs.

## Model Boundary

- `ModelRouterService` chooses cloud providers by task profile and `config/model_router.php`.
- `ModelSelectorService` chooses local Ollama models.
- `OllamaService` owns runtime routing for local and paid-prefixed models.
- Provider calls should remain behind `GeneratorService`, `OpenAiChatService`, `AnthropicChatService`, and `OllamaService`.

## AI Organization Boundary

- The AI Organization layer lives under `app/Services/Org`, `app/Jobs/Org`, and `app/Http/Controllers/Client/Org`.
- `OrgVersion` is the immutable org structure snapshot.
- `OrgMember` is the stable identity for managers, directors, and assistants.
- `Director` and `Assistant` are placements inside an org version.
- `AssistantTask` is the reusable work unit that can generate and run a `Flow`.
- `OrgProposal` and `OrgEvent` record mutations and history.
- `OrgPlannerService`, `DirectorAgentService`, `AssistantRouterService`, `TaskRunService`, `ApprovalService`, and `OrgGraphService` are common entrypoints.
- Autonomous work must pass `AutonomousBudgetService`.
- Manual chat, manual ticks, and manual task runs bypass autonomous budget caps.

## Billing Boundary

- Billing data lives around `CreditWallet`, `CreditReservation`, `CreditLedgerEntry`, `Plan`, and `Subscription`.
- `CreditReservation` is mutable state for one metered operation.
- `credit_ledger` is the append-only audit log.
- `BillableOperationService` is the canonical reserve and settle entrypoint.
- `CreditMeterService` reserves, settles, refunds, and records ledger rows.
- `BillingGatePolicy` decides hard gate versus soft best effort.
- `LlmUsage` accumulates paid-provider token usage and pricing.
- Never debit credit wallets directly.

## Knowledge Boundary

- Knowledge base v2 is a company RAG system.
- `KnowledgeService` is the main entrypoint.
- Detailed services live in `app/Services/Knowledge`.
- Resources flow through `KnowledgeResource`, `KnowledgePage`, `KnowledgeChunk`, and `KnowledgeFact`.
- Gaps, conflicts, folders, events, and chat are modeled separately.
- URL ingest uses `CrawlService`, `WebPageCacheService`, `web_page_cache`, and `web_page_digests`.
- Embeddings are shared with flow memory through `EmbeddingService`.

## MCP Boundary

- MCP connector calls should remain behind `McpClientService`.
- Connector contracts and implementations live in `app/Services/Mcp`.
- Company connector state lives in `CompanyConnector`.
- Every real connector call is audited in `ConnectorToolLog`.
- `SsrfGuard` blocks unsafe internal targets.
- Write tools must honor `OrgActPolicy`.
- Org flows with act disabled should draft intended actions instead of performing real writes.

## Frontend Boundary

- Blade views live under `resources/views`.
- Shared components live under `resources/views/components`.
- The app uses Vite, Tailwind CSS v4, Alpine.js, and Drawflow.
- `resources/css/app.css` is the design token source of truth.
- Keep UI work server-rendered unless the user explicitly asks for a frontend architecture change.
