# CLAUDE.md

Guidance for working in this repository.

## Rules

- **Do NOT add tests.** Do not write new test files or test cases.
- **Do NOT run tests.** Do not run `php artisan test`, `phpunit`, `composer test`, or any test command.

## What this is

A Laravel 12 (PHP 8.2) web app for building and running **multi-agent AI workflows**. Users define a **Company**, create **Flows** (ordered pipelines of agents), and run them. Each **Agent** is an LLM-driven step that can call tools (web search, scraping, crawling) and pass its output to the next agent. LLM inference runs through **Ollama** (local models); image generation via **ComfyUI**; web search via **Brave**.

The domain hierarchy: `Company → Flow → Agent[]`, with each execution recorded as `FlowRun → AgentRun[]`.

## Tech stack

- **Backend:** Laravel 12, PHP 8.2
- **Frontend:** Blade views + Vite + Tailwind CSS v4 (no SPA framework)
- **DB:** SQLite (default), database-backed queue, cache, and sessions
- **Queue:** database driver — background jobs need a running worker
- **LLM:** Ollama (`OLLAMA_URL`), with a model-selector/fallback layer
- **External services:** Brave Search, ComfyUI, an internal crawl service (`CRAWL_SERVICE_URL`)

## Layout

- `app/Agents/` — agent implementations, all extending `BaseAgent` (e.g. `OrchestratorAgent`, `DeepResearcherAgent`, `ReportComposerAgent`, `QaVerifierAgent`). `AgentFactory` instantiates them; `Tools/` holds tool classes (`BraveSearchTool`, `WebScraperTool`, `SiteCrawlerTool`, `SiteDiscoveryTool`).
- `app/Services/` — orchestration and integrations: `FlowExecutorService` (runs a flow end-to-end with QA scoring), `OllamaService`, `ModelSelectorService`, `AgentGeneratorService`, `BraveSearchService`, `CrawlService`, `ComfyUIService`, `FinalComposerService`.
- `app/Jobs/` — `ExecuteFlowJob`, `ExecuteAgentJob`, `SyncOllamaModelsJob` (queued work).
- `app/Models/` — `Company`, `Flow`, `Agent`, `FlowRun`, `AgentRun`, `LlmModel`, `AgentTemplate`, `User`.
- `app/Http/Controllers/` — resourceful controllers for companies, flows, agents, runs, models, templates; `Admin/` for the admin-gated area (`is_admin` middleware).
- `app/Console/Commands/` — CLI entry points (`ExecuteFlowCommand`, `GenerateAgentsCommand`, `RunScheduledFlows`, `PullOllamaModel`, `TestOllamaModel`).
- `app/Support/` — pure helpers (`ReasoningStripper`, `UrlExtractor`, `PageContent`, pricing helpers).
- `routes/web.php` — UI + AJAX endpoints (generation polling, run polling, model pull/test). `routes/api.php` — flow webhook trigger for n8n/Zapier/Make.
- `resources/views/` — Blade templates grouped by domain (`flows/`, `agents/`, `runs/`, `models/`, `companies/`, `admin/`).

## How a flow runs

`FlowExecutorService::run()` creates a `FlowRun`, writes a per-run log to `storage/logs/run-{id}.log`, then executes each enabled agent in order via `AgentFactory`. Agents build prompts from `prompt_template` with `{{key}}` context substitution, optionally invoke tools, and produce output consumed by later agents. `QaVerifierAgent` scores output against a QA threshold (default 60).

## Common commands

- `composer dev` — runs server + queue worker + log tailer (`pail`) + Vite concurrently. **Use this for local dev** so queued jobs actually process.
- `php artisan serve` — web server only
- `php artisan queue:listen` — queue worker only
- `npm run dev` / `npm run build` — Vite
- `php artisan migrate` — apply migrations

## Conventions

- Follows standard Laravel structure and naming. Format with **Laravel Pint** (`vendor/bin/pint`).
- Background/long-running work goes through queued **Jobs**, surfaced to the UI via token/poll endpoints.
- Keep LLM-provider calls behind `OllamaService` / `ModelSelectorService`; don't call Ollama directly from controllers.
