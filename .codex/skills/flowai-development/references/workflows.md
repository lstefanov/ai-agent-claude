# FlowAI Engineering Workflows

## Flow Lifecycle

1. A user describes a flow.
2. `flows/generate-agents` starts generation.
3. `FlowPlannerService` runs intent analysis, pipeline design, and critique.
4. `AgentGeneratorService` hardens the plan.
5. The builder renders the DAG for review and editing.
6. Saving the graph goes through `GraphNormalizer` into `flow_nodes` and `flow_edges`.
7. Running a flow creates a `FlowRun`.
8. `GraphFlowExecutor` computes waves and dispatches `ExecuteNodeJob` batches on the `flows` queue.
9. `NodeExecutorService` runs each node through local Ollama or paid-prefixed providers.
10. Successful runs can promote plans, distill memory, and harvest company knowledge.

## Planning Changes

Start with `FlowPlannerService` when the change affects what agents are proposed.
Move deterministic validation, defaulting, pruning, or guarantees into `AgentGeneratorService`.
Use `PlanGraphBuilder` for graph assembly shape.
Use `GraphNormalizer` for Drawflow import, export, parse, and persistence.
Treat planner output as untrusted even when prompt instructions are strict.
Do not preserve old planner behavior through compatibility paths when replacing behavior.

## Runtime Changes

Start with `GraphFlowExecutor` when the change affects DAG waves, dispatching, finalization, failure, resume, or terminal run state.
Start with `NodeExecutorService` when the change affects one node's execution behavior.
Use `app/Services/Execution` helpers for prompt building, FlowNode to Agent bridging, QA gate behavior, and adaptive replanning.
Preserve idempotency in jobs and terminal state transitions.
Keep long-running work in queued jobs.
Use Horizon for worker behavior and do not introduce direct worker processes.

## AI Organization Changes

Start with `app/Services/Org` and the matching `app/Http/Controllers/Client/Org` controller.
Use `AssistantTask` as the work unit that can generate and run a flow.
Use `OrgProposal` and `OrgEvent` for structural changes and traceability.
Respect `AutonomousBudgetService` for autonomous work.
Respect `OrgActPolicy` for real-world connector writes.
Keep manual paths explicit and separate from autonomous caps.

## Billing Changes

Start with `BillableOperationService` for reserve and finish boundaries.
Use `CreditMeterService` for reserve, settle, refund, and ledger records.
Do not debit wallets directly.
Keep `credit_ledger` append-only.
Make terminal failure, cancellation, and approval rejection settle or refund consistently.
Attribute LLM usage through the existing `LlmContext`, `LlmUsage`, and request recording paths.

## MCP Changes

Start with `McpClientService` for connector routing.
Add connector-specific behavior behind `McpConnectorInterface` implementations.
Keep credentials isolated inside connector resolution and never serialize secrets.
Use `McpParamResolver` for parameter interpolation.
Use `ConnectorToolLog` for real connector calls.
Use draft behavior when org act mode is disabled.
Require human approval before write tools when policy requires it.

## Knowledge Changes

Start with `KnowledgeService` for high-level product behavior.
Use services under `app/Services/Knowledge` for ingestion, extraction, chunking, facts, gaps, conflicts, synthesis, and chat.
Keep company knowledge supplementary to planning unless the product request says otherwise.
Keep web tools available for research agents when fresh external research is needed.

## Horizon And Queue Debugging

Use `composer dev` when the full local dev stack and queued jobs must process.
Use `php artisan horizon:status` to inspect Horizon.
Use `php artisan horizon:terminate` after code or config changes so workers reload code.
Use `php artisan flows:watchdog` and `php artisan flows:cancel-stuck` for stuck flow operations.
Do not use `php artisan queue:work` or `php artisan queue:listen`.
Remember that background jobs need Horizon running.
Inspect `.env`, `config/horizon.php`, `config/queue.php`, Redis state, `failed_jobs`, and app logs when jobs do not move.

## Auth And Session Debugging

Check the active environment before debugging guard or session issues.
Local `.env` may use file sessions.
Defaults in `.env.example` and `config/session.php` use database sessions.
Admin routes and client routes have separate surfaces and guards.

## Ollama And Provider Debugging

Check model routing through `ModelRouterService`, `ModelSelectorService`, `OllamaService`, and `config/model_router.php`.
Paid-prefixed providers should remain behind the existing provider services.
Do not make direct provider HTTP calls from controllers, jobs, or views.

## Bug Fix Workflow

1. Reproduce the symptom through the closest product path allowed by repo rules.
2. Trace from route or job entrypoint to the canonical service boundary.
3. Identify the invariant that failed.
4. Add the smallest deterministic fix at the boundary that owns the invariant.
5. Verify with allowed static checks, syntax checks, and manual inspection.
