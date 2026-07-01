# FlowAI Task Runbooks

Use these runbooks when a request matches a common FlowAI engineering operation.
Keep the two main Codex skills as entrypoints; do not split these into separate Codex skills unless the user asks for that structure later.

## Add An Agent Type

Use when adding a new agent that the planner can place in a DAG.

- Add `app/Agents/XxxAgent.php` extending the existing agent base shape.
- Register the type in `app/Agents/AgentFactory.php`.
- Add the type to `config/agent_types.php` with `output_role`, label, and planner-facing description.
- Route model calls through the existing runtime services.
- Do not model `mcp_action` or `human_approval` as agents.
- Validate planner-provided config in deterministic PHP.

## Add An Agent Tool

Use when exposing a new callable capability to agents through `AgentLoop`.

- Add `app/Agents/Tools/XxxTool.php` following the `AgentTool` contract.
- Model the shape on `WebSearchTool`, `BraveSearchTool`, or `KnowledgeSearchTool`.
- Expose a stable tool name, selection description, and parameter schema.
- Delegate external calls to a service.
- Wire the tool into relevant agents through `AgentFactory`.
- Use `SsrfGuard` for web, URL, and file-fetch paths that can reach arbitrary targets.

## Add An HTTP Surface

Use when adding a route, controller, endpoint, or page.

- Pick the surface first.
- Admin uses `routes/web.php`, admin guards, and admin views.
- Client portal uses `routes/client.php`, `client_auth`, and client views.
- AI Organization controllers live under `app/Http/Controllers/Client/Org`.
- Keep controllers thin.
- Push real work into services or queued jobs.
- Preserve auth, guard, session, and company-scope boundaries.

## Add A Queued Job

Use when making work async or adding background processing.

- Add the job under `app/Jobs` or `app/Jobs/Org`.
- Use `flows` for flow execution, `org` for AI Organization work, and `default` otherwise.
- Make retries safe through idempotency.
- Reserve and settle through `BillableOperationService` when the job is metered.
- Use Horizon only.
- After job or queue config changes, plan for `php artisan horizon:terminate` so workers reload code.

## Add A Model Or Migration

Use when adding or replacing database structure.

- Add or update the Eloquent model under `app/Models`.
- Add the migration with the full target schema.
- Add fillable, casts, and relations where needed.
- Do not keep legacy bridge code or old columns when replacing behavior.
- The owner resets the DB instead of migrating old data.
- Do not hand-edit generated files.

## Add An LLM Provider

Use when integrating a new cloud model backend or routing some nodes to a provider.

- Add a provider service mirroring `OpenAiChatService` or `AnthropicChatService`.
- Wire planning and generation selection through `GeneratorService`.
- Wire agent-node routing through `ModelRouterService` and `config/model_router.php`.
- Keep local and paid-prefixed runtime dispatch consistent with `OllamaService`.
- Keep paid usage in `LlmUsage`.
- Do not call provider SDKs from controllers, jobs, views, or agents.

## Add An MCP Connector

Use when adding an external app connector.

- Add `app/Services/Mcp/Connectors/XxxConnector.php`.
- Extend `AbstractConnector` and satisfy `McpConnectorInterface`.
- Register the connector in `config/mcp.php`.
- Resolve parameters through `McpParamResolver`.
- Return `McpToolResult`.
- Route all calls through `McpClientService`.
- Keep per-company state in `CompanyConnector`.
- Audit real calls in `ConnectorToolLog`.
- Honor `OrgActPolicy` before write tools touch the real world.
- Use `SsrfGuard` for outbound targets.

## Add A Billable Operation

Use when work reserves or settles credits.

- Wrap the unit of work with `BillableOperationService`.
- Let `CreditMeterService` reserve, settle, refund, and write ledger rows.
- Choose hard gate or soft best effort through `BillingGatePolicy`.
- Keep mutable operation state in `CreditReservation`.
- Keep `credit_ledger` append-only.
- Never debit `CreditWallet` directly.

## Add A Payment Provider

Use when adding a provider that funds credit wallets.

- Implement `PaymentProvider` under `app/Services/Org/Billing`.
- Model the shape on `StripePaymentProvider` and `AdminSimulatedPaymentProvider`.
- Add a webhook route when confirmation is asynchronous.
- Verify provider signatures for webhooks.
- Credit wallets only through `CreditMeterService`.
- Keep provider secrets in environment configuration.

## Add An Org Mutation

Use when changing AI Organization structure.

- Express structural changes as `OrgProposal`.
- Record applied changes in `OrgEvent`.
- Treat `OrgVersion` as immutable.
- Treat `OrgMember` as stable identity.
- Treat `Director` and `Assistant` as placements inside a version.
- Route autonomous structural changes through approval.
- Apply `AutonomousBudgetService` caps to autonomous work.
- Respect `OrgActPolicy` for real-world connector writes.

## Extend Knowledge Ingest

Use when adding a source type, document handling path, chunking behavior, facts, gaps, conflicts, or embeddings.

- Start in `KnowledgeService` or `app/Services/Knowledge`.
- Use `KnowledgeIngestor` to move from `KnowledgeResource` to pages, chunks, and facts.
- Extract files with `DocumentTextExtractor`.
- Ingest URLs through `CrawlService` and `WebPageCacheService`.
- Run ingest through `IngestResourceJob` or `IngestUrlResourceJob`.
- Reuse `EmbeddingService`.
- Keep planner knowledge supplementary unless the product request says otherwise.

## Harden The Planner

Use when changing flow planning, DAG shape, provider pins, or generated plan safety.

- Keep `FlowPlannerService` stages distinct.
- Use `PlanGraphBuilder` when graph assembly changes.
- Put deterministic validation and normalization in `AgentGeneratorService`.
- Treat every LLM-produced field as untrusted.
- Feed reusable improvements through `PlanLibraryService` and `FlowMemoryService` rather than hardcoding one-off examples.
- Use `docs/DYNAMIC-AGENT-PLANNER.md` for deeper context.

## Work On GraphNormalizer

Use when saving, parsing, or changing Drawflow graph payloads.

- Put Drawflow parsing and shaping in `GraphNormalizer`.
- Persist normalized output to `flow_nodes` and `flow_edges`.
- Use `FlowVersionService` for snapshots and restores.
- Treat builder payloads as untrusted.
- Do not duplicate Drawflow export knowledge in controllers, jobs, views, or ad hoc helpers.
- Remove replaced graph paths instead of keeping fallbacks.

## Debug A Stuck Flow

Use when a run is stuck or queues are stalled.

- Inspect stuck run state with `php artisan flows:watchdog`.
- Clean stuck runs with `php artisan flows:cancel-stuck` only when that is the intended operation.
- Inspect workers with `php artisan horizon:status`.
- Restart workers after code or config changes with `php artisan horizon:terminate`.
- Confirm no stray `queue:work` or `queue:listen` process is being used.
- Confirm Redis is up and the configured client is `predis`.
- Use `composer dev` locally when queued jobs must actually process.
