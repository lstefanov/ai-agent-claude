# CLAUDE.md

Guidance for working in this repository.

## Rules

- **Do NOT add tests.** Do not write new test files or test cases.
- **Do NOT run tests.** Do not run `php artisan test`, `phpunit`, `composer test`, or any test command.
- **No legacy/back-compat code.** The owner resets the DB rather than migrating old data — when replacing logic, delete the old path instead of keeping fallbacks.

## What this is

A Laravel 12 (PHP 8.2) web app for building and running **multi-agent AI workflows**. Users define a **Company**, create **Flows** described in free text, and an LLM planner (**FlowPlannerService**, the "agent that creates agents") designs the agent pipeline automatically as a **DAG** (graph of nodes + edges), shown for review in a visual Drawflow builder, then executed with real parallelism.

Planning runs on a cloud LLM (**OpenAI** by default; Anthropic, DeepSeek and Gemini supported) via structured outputs, with optional **per-phase hybrid** routing (`PLANNER_{INTENT,DESIGN,CRITIQUE,REVISION}_{PROVIDER,MODEL}` — e.g. Claude only for pipeline design, a cheap/free model for the rest). Agent execution mixes local **Ollama** models and cloud-pinned steps (`<provider>/<model>`), governed by a per-generation **model-cost level** (`App\Support\ModelLevel`: low/medium/high/ultra, default medium — chosen in the builder popup and the A/B page): low = mostly local with ≤3 cheap cloud pins; medium = cheap cloud (Gemini/DeepSeek/xAI/Qwen) for most agents with ≥3 staying local; high = all cheap cloud with ≤3 OpenAI; ultra = all OpenAI with ≤2 Anthropic. Bulgarian-prose agents stay on local BgGPT on every level except ultra. Image generation via **ComfyUI**; web search via **Brave**; scraping/crawling via an internal crawl service.

The domain hierarchy: `Company → Flow → FlowNode[]/FlowEdge[]`, with each execution recorded as `FlowRun → NodeRun[]`.

## Tech stack

- **Backend:** Laravel 12, PHP 8.2
- **Frontend:** Blade views + Vite + Tailwind CSS v4, Alpine.js, Drawflow (graph builder) — no SPA framework
- **DB:** SQLite (default), database-backed queue, cache, and sessions
- **Queue:** database driver — background jobs need a running worker
- **LLM (planning):** `GENERATOR_PROVIDER=openai|anthropic|deepseek|gemini|xai|qwen` (cloud, API key) or `ollama` (free local planning via Ollama structured outputs on `OLLAMA_PLANNER_MODEL`) — `GeneratorService::resolve()` picks per phase (`services.planner.phases` overrides); OpenAI/DeepSeek/Gemini/xAI/Qwen share one OpenAI-compatible client (`OpenAiChatService::for($provider)`)
- **LLM (runtime):** Ollama (`OLLAMA_URL`) with a model-selector layer; `openai/<model>` / `anthropic/<model>` / `deepseek/<model>` / `gemini/<model>` / `xai/<model>` / `qwen/<model>` prefixes route a node to that paid provider (`App\Support\PaidModel` owns the prefixes and the cheap/premium tier split)
- **External services:** Brave Search, ComfyUI, internal crawl service (`CRAWL_SERVICE_URL`), Google Places (reviews)

## Layout

- `app/Services/` — the core:
  - `FlowPlannerService` — three-phase planner (intent analysis → pipeline design → critique), OpenAI Structured Outputs, capability registry; logs each phase to `agent_generation_logs`.
  - `AgentGeneratorService` — deterministic hardening of the plan: model selection, num_predict guard-rails, exactly one `bg_text_corrector` + `qa_verifier`, dedupe, cycle-free `depends_on` graph.
  - `GraphFlowExecutor` — DAG execution: topological "waves", `Bus::batch` parallelism, fail_fast/best_effort policy. Within a wave, cloud-pinned nodes run fully parallel; local nodes share `OLLAMA_MAX_CONCURRENT` global slots (`App\Support\OllamaSemaphore`).
  - `NodeExecutorService` — runs one node: input from direct predecessors (namespaced, no information loss), technical retries, inline step-QA gate; bridges FlowNode → transient `Agent` DTO.
  - `GraphNormalizer` — the ONLY place that understands the Drawflow export format.
  - `PlanLibraryService` — the planner's long-term memory: saving the graph (= approval) snapshots intent + pipeline into `plan_library`; a successful run marks it `proven`; the most similar proven plans are injected as few-shot examples at design time.
  - `FlowMemoryService` — the flow's run-to-run memory (`flow_memories`): after each successful run a queued `DistillFlowMemoryJob` stores digests + embeddings of content-node outputs and per-node "lessons" (from replan events). Next runs get a "ПАМЕТ/ПОУКИ" prompt block, and a post-generation dedup gate (cosine ≥ `MEMORY_SIMILARITY_THRESHOLD`, default 0.80) retries too-similar outputs with feedback (max `MEMORY_DEDUP_MAX_RETRIES`, then accept+flag — never fails the run). Embeddings via `EmbeddingService` (`MEMORY_EMBEDDING_PROVIDER=openai|ollama`); per-flow toggle in the builder "Памет" panel (settings.memory.enabled). Content node = `output_role` 'body' minus transformers (bg_text_corrector/translator).
  - `GeneratorService` (planning provider switch), `OpenAiChatService` / `AnthropicChatService` (paid-provider chat + structured outputs + embeddings/tool-call), `OllamaService` (local runtime; routes paid-prefixed models), `ModelSelectorService`, `BraveSearchService`, `CrawlService`, `ComfyUIService`, `GooglePlacesService`, `FinalComposerService`.
- `app/Agents/` — agent implementations extending `BaseAgent` (e.g. `DeepResearcherAgent`, `ReportComposerAgent`, `QaVerifierAgent`, `GenericAgent` for planner-composed `custom` agents with config-driven tools). `AgentFactory` instantiates by node type; `Tools/` holds tool classes (`BraveSearchTool`, `WebScraperTool`, `SiteCrawlerTool`, `SiteDiscoveryTool`, `GoogleReviewsTool`).
- `app/Jobs/` — `ExecuteFlowJob` (scheduler entry), `ExecuteNodeJob` (one node per queued job), `SyncOllamaModelsJob`.
- `app/Models/` — `Company`, `Flow`, `FlowNode`, `FlowEdge`, `FlowRun`, `NodeRun`, `LlmModel`, `AgentTemplate`, `AgentGenerationLog`, `PlanLibraryEntry`, `User`. `Agent`/`AgentRun` are transient runtime DTOs for the node bridge — there are no `agents`/`agent_runs` tables.
- `app/Http/Controllers/` — companies, flows (incl. generation endpoints), flow builder/graph, runs, models, templates; `Admin/` for the admin-gated area (`is_admin` middleware).
- `app/Console/Commands/` — `GenerateAgentsCommand` (background generation), `ExecuteFlowCommand`, `RunScheduledFlows`, `PlanAbCommand` (`flows:plan-ab` — OpenAI vs Anthropic plan comparison), `PullOllamaModel`, `TestOllamaModel`.
- `app/Support/` — pure helpers (`GraphTopology`, `ReasoningStripper`, `UrlExtractor`, `PageContent`, `LlmUsage` — paid-provider token/cost accumulator, pricing helpers).
- `routes/web.php` — UI + AJAX endpoints (generation polling, run polling, graph store/validate, model pull/test). `routes/api.php` — flow webhook trigger.
- `resources/views/` — Blade templates; `flows/builder.blade.php` is the Drawflow graph editor (generation popup, live run mode, generation log panel).
- `docs/DYNAMIC-AGENT-PLANNER.md` — planner architecture + roadmap.

## How a flow is created and runs

1. User describes the flow → `flows/generate-agents` starts a background `flows:generate-agents` process; the builder polls and narrates progress.
2. `FlowPlannerService.plan()`: intent analysis → pipeline design (DAG of single-responsibility agents with prompts, tools, provider, tuning) → critique/repair. `AgentGeneratorService.finalizePlannedAgents()` hardens the result.
3. The builder renders the plan as a graph; the user reviews/edits and saves (`GraphNormalizer.sync` → `flow_nodes`/`flow_edges`).
4. Run: `GraphFlowExecutor` computes waves and dispatches `ExecuteNodeJob` batches on the `flows` queue; `NodeExecutorService` executes each node (Ollama or OpenAI by model prefix), step-QA gates retry on low scores — from the second retry the planner revises the failing agent (adaptive replanning, run-scoped only; a degenerate-output watchdog triggers the same path). A per-run log lands in `storage/logs/run-{id}.log`; paid-provider cost is tracked per node (`node_runs.cost_usd`) and per planner phase (`agent_generation_logs.cost_usd`).
5. A successful run promotes the flow's saved plan to `proven` in the plan library, feeding future planning as few-shot examples, and dispatches `DistillFlowMemoryJob` (default queue) to remember what was produced — future runs steer away from duplicating it (flow memory + dedup gate).

## Common commands

- `composer dev` — runs server + queue worker + log tailer (`pail`) + Vite concurrently. **Use this for local dev** so queued jobs actually process.
- `php artisan serve` — web server only
- `php artisan queue:listen` — queue worker only
- `npm run dev` / `npm run build` — Vite
- `php artisan migrate` — apply migrations; `php artisan migrate:fresh --seed` — reset DB (seeds LLM models + system agent templates)

## Conventions

- Follows standard Laravel structure and naming. Format with **Laravel Pint** (`vendor/bin/pint`).
- Background/long-running work goes through queued **Jobs**, surfaced to the UI via token/poll endpoints.
- LLM calls stay behind services: planning via `GeneratorService`/`OpenAiChatService`, runtime via `OllamaService` (which owns the `openai/` routing); don't call providers directly from controllers or agents.
- The planner PROPOSES, code GUARANTEES: never trust LLM output for structure — validate/normalize in `AgentGeneratorService`.
