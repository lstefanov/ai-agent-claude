# FlowAI — Design Spec
**Date:** 2026-05-28  
**Status:** Approved  
**Approach:** B — Synchronous execution (sync queue) for dev, full architecture preserved

---

## 1. Environment

| Component | Detail |
|-----------|--------|
| PHP | 8.2.0 |
| Laravel | 12.x |
| MySQL | 5.7.39 (MAMP) — credentials: root/root, port 3306 |
| Apache | MAMP PRO, domain: `flowai.local` |
| Ollama | Install via `brew install ollama`, port 11434 |
| ComfyUI | Install via git + Python 3.12 (brew), port 8188, Apple M3 Max (Metal/MPS) |
| Queue | `QUEUE_CONNECTION=sync` in dev .env |

---

## 2. Installation Plan

### Ollama
```bash
brew install ollama
brew services start ollama   # or: ollama serve
```
Pull required models after Ollama starts (see PROJECT-PLAN.md model list).

### ComfyUI
```bash
brew install python@3.12
git clone https://github.com/comfyanonymous/ComfyUI ~/ComfyUI
cd ~/ComfyUI
/opt/homebrew/bin/python3.12 -m venv venv
source venv/bin/activate
pip install torch torchvision torchaudio          # MPS backend (Apple Silicon)
pip install -r requirements.txt
python main.py --listen 127.0.0.1 --port 8188
```

### Startup Script
`scripts/start-services.sh` — starts both Ollama and ComfyUI with one command.

### MAMP PRO Virtual Host
- Domain: `flowai.local`
- Document Root: `[worktree]/public/`
- `/etc/hosts` entry: `127.0.0.1 flowai.local`
- PHP version: 8.2

---

## 3. Project Structure

```
[worktree]/
├── app/
│   ├── Models/          Company, Flow, Agent, LlmModel, FlowRun, AgentRun
│   ├── Services/
│   │   ├── OllamaService.php          # HTTP to localhost:11434
│   │   ├── ComfyUIService.php         # HTTP to localhost:8188 (REAL, not mock)
│   │   ├── AgentGeneratorService.php  # AI-generates agents from flow description
│   │   ├── ModelSelectorService.php   # Fallback logic for model selection
│   │   └── FlowExecutorService.php    # Orchestrates agent execution
│   ├── Agents/
│   │   ├── BaseAgent.php              # Abstract class
│   │   ├── ContentAgent.php
│   │   ├── ImagePromptAgent.php       # Generates ComfyUI workflow JSON via Ollama
│   │   ├── QaVerifierAgent.php
│   │   ├── AnalyzerAgent.php
│   │   ├── ResearcherAgent.php
│   │   ├── SummarizerAgent.php
│   │   ├── DecisionAgent.php
│   │   ├── PublisherAgent.php
│   │   ├── TranslatorAgent.php
│   │   └── OrchestratorAgent.php
│   ├── Jobs/
│   │   ├── ExecuteFlowJob.php
│   │   ├── ExecuteAgentJob.php
│   │   └── SyncOllamaModelsJob.php
│   └── Http/Controllers/
│       ├── CompanyController.php
│       ├── FlowController.php
│       ├── AgentController.php
│       ├── FlowRunController.php
│       └── LlmModelController.php
├── resources/views/
│   ├── layouts/app.blade.php
│   ├── companies/    index, show, create
│   ├── flows/        index, create, show, edit
│   ├── agents/       edit
│   ├── runs/         show
│   └── models/       index
└── scripts/
    └── start-services.sh
```

---

## 4. Database Schema (MySQL 5.7 compatible)

Six tables: `companies`, `flows`, `agents`, `llm_models`, `flow_runs`, `agent_runs`.

**MySQL 5.7 adaptations:**
- No `JSON` column default expressions (use `nullable()` instead)
- No generated columns
- `json()` columns stored as TEXT-compatible JSON (Laravel handles this transparently)

**`llm_models` extended columns:**
- `size_mb` unsignedInt nullable — shown in models list; updated from Ollama `/api/tags` on sync
- `is_enabled` boolean default true — user can toggle; disabled models hidden from agent dropdowns
- `pull_status` varchar(20) nullable — `null / pulling / completed / failed`
- `pull_progress` tinyint unsigned — 0–100, written by `models:pull` artisan command

**`agents` extended columns:**
- `output_language` varchar(10) default `bg` — auto-injected as "Language: respond in X" in system prompt
- `output_tone` varchar(30) nullable — e.g. Friendly, Formal, Cold, Ironic, Persuasive…
- `output_style` varchar(30) nullable — e.g. Academic, Creative, Journalistic, Technical…
- `output_format` varchar(30) nullable — e.g. Report, Blog post, Proposal, Email, FAQ…
- `config` JSON — model parameters: `temperature`, `top_p`, `top_k`, `repeat_penalty`, `num_predict`

Full schema matches PROJECT-PLAN.md exactly, with the above adaptations.

---

## 5. Execution Flow

```
POST /flows/{id}/run
  → ExecuteFlowJob::dispatch($flow)     [sync in dev]
  → FlowExecutorService::run($flow)
    → FlowRun::create(status=running)
    → foreach agent (order ASC, is_active=true):
        → AgentRun::create(status=running, input=context)
        → if type == image_prompt:
            → OllamaService::chat(mistral, prompt)          # generate workflow JSON
            → ComfyUIService::generate(workflow_json)       # POST /prompt to :8188
            → poll ComfyUIService::getResult(prompt_id)     # GET /history/{id}
        → else:
            → OllamaService::chat(agent->model, built_prompt)
        → AgentRun::update(output, duration_ms, status=completed)
        → context[agent->name] = output
        → if is_verifier && score < qa_threshold:
            → FlowRun::update(status=failed)
            → STOP
    → FlowRun::update(status=completed)
```

---

## 6. AgentGeneratorService — AJAX Flow

```
POST /flows/generate-agents  (AJAX)
  → OllamaService::chat(mistral, system_prompt + company_context + flow_description)
  → Parse JSON array response
  → Return agents for UI preview (NOT saved to DB yet)

POST /flows (save)
  → Create Flow + bulk create Agents from preview data
```

---

## 7. ComfyUI Integration (Real)

- `ComfyUIService::generate(string $workflowJson): array`
  - POST to `http://localhost:8188/prompt` with `{"prompt": <workflow>}`
  - Returns `prompt_id`
- `ComfyUIService::getResult(string $promptId): ?string`
  - GET `http://localhost:8188/history/{promptId}` — poll until output ready
  - Download image, save to `storage/app/public/generated/{promptId}.png`
  - Return public URL
- `COMFYUI_ENABLED=true` in `.env` — no mock fallback needed

---

## 8. UI Pages

| URL | Page |
|-----|------|
| `/` | Companies grid |
| `/companies/{id}` | Company detail + flows list |
| `/companies/{id}/flows/create` | New flow + AI agent generator |
| `/flows/{id}` | Flow detail: agents table + run history + "Run now" button |
| `/flows/{id}/agents/{agentId}/edit` | Edit agent — 3 tabs: Basic / Output Preferences / Parameters |
| `/runs/{id}` | Run detail: step-by-step agent results |
| `/models` | LLM models: toggle, add, download with progress bar, test |

**Agent edit tabs:**
- **Basic** — name, role, prompt template, model dropdown (only enabled models), active toggle, QA threshold
- **Output Preferences** — Language (bg/en/de/fr/es/ru), Tone (10 options), Style (10 options), Format (12 options); all auto-injected into system prompt at execution time
- **Parameters** — temperature (0–2), top_p (0–1), top_k (1–200), repeat_penalty (0–2), num_predict (-1 = unlimited); stored in `config` JSON; empty = model default

**Models page features:**
- ⏸/▶ toggle button — enable/disable model (disabled = hidden from agent dropdowns)
- ＋ Add model form — manual registration (ollama_tag, display_name, category, RAM, size_mb, description)
- ⬇ Изтегли — starts `artisan models:pull` as background process; Alpine.js polls progress every 2s
- ▶ Тест — sends one-shot prompt to the model, shows inline response

---

## 9. Development Phases

All 4 phases implemented in one session:

| Phase | Content |
|-------|---------|
| 1 | Laravel scaffold, migrations, seeds, OllamaService, ComfyUIService, layout |
| 2 | Companies & Flows CRUD, AgentGeneratorService, AJAX preview |
| 3 | Agent classes, FlowExecutorService, FlowRun/AgentRun logging, Jobs |
| 4 | Run results UI, scheduler, models page, Tailwind polish |

---

## 10. Key .env Settings

```env
APP_NAME=FlowAI
APP_URL=http://flowai.local

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=flowai
DB_USERNAME=root
DB_PASSWORD=root

QUEUE_CONNECTION=sync

# PHP binary for background artisan commands (web PHP ≠ CLI PHP on MAMP)
PHP_CLI_BINARY=/opt/homebrew/bin/php

OLLAMA_URL=http://localhost:11434
OLLAMA_GENERATOR_MODEL=mistral
OLLAMA_DEFAULT_FALLBACK=llama3.1:8b

COMFYUI_URL=http://localhost:8188
COMFYUI_ENABLED=true

INTEGRATIONS_MOCK=true
```
