# QA Agent System Redesign

**Date:** 2026-05-31  
**Status:** Approved for implementation

---

## Context

The current QA system has two coexisting modes that confuse users:

1. **Standalone verifier** — a QA agent added as an explicit pipeline step (appears as step 2, 4, 6... in the run list; adds dropdowns in the flow header)
2. **Step-level QA** — a property on each agent (`config.qa`) that runs a verifier invisibly after that step

The result: runs show 10 steps when there are really 5, the header has unexplained "QA 70%" dropdowns, QA config doesn't save reliably, and users can't specify *what* each QA agent should validate.

**Goal:** Single, clear QA model — step-level only — with per-step custom validation prompts, and AI generation that auto-configures QA for every agent.

---

## What Changes

### 1. Data Model — No Migration Needed

`agent.config.qa` already exists. Add one field: `custom_prompt`.

```json
{
  "qa": {
    "enabled": true,
    "verifier_agent_id": 5,
    "threshold": 60,
    "max_retries": 3,
    "custom_prompt": "Провери дали резултатът съдържа поне 3 конкурента с цени..."
  }
}
```

The `verifier_agent_uid` temporary-link field is already supported for pre-save linking — no change needed there.

Standalone verifiers (`is_verifier = true` agents that are NOT referenced by any step policy) are **no longer creatable via UI**, but existing ones remain valid in the database for old runs.

---

### 2. Flow Builder — QA Tab in Agent Edit Panel

**File:** `resources/views/flows/show.blade.php`

**Remove:**
- The header QA% threshold dropdowns (currently generated for every `is_verifier` agent)
- The `updateQaThresholds` AJAX call and form

**Replace the current "QA след стъпка" section** inside the agent edit panel with a two-tab layout:

- Tab 1: **Агент** — existing fields (name, type, system_prompt, prompt_template, model, etc.)
- Tab 2: **QA Верификация** — new layout:
  - Toggle: Enable/disable QA for this step
  - Dropdown: Select QA agent (only agents with `type = qa_verifier`)
  - Select: Threshold % (0–100, step 5, default 60)
  - Select: Max retries (0–10, default 3)
  - Textarea: "Какво да проверява QA-то" (`custom_prompt`) — optional, placeholder explains default behavior

**Save:** The existing save endpoint for agents must persist `config.qa.custom_prompt` alongside existing qa fields. The save bug is in the Alpine.js agent save handler — the `config` object is likely not being serialized into the form submission. Fix: ensure `config` is JSON-encoded in the hidden input before POST.

---

### 3. Run Page — QA Badge per Step

**File:** `resources/views/runs/show.blade.php`

**Remove:** Standalone verifier agents from the visible step list. Any agent whose ID appears in `step_qa_policies` as a `verifier_agent_id` is hidden from the numbered list.

**Add per-step QA badge** (right side of each step card):

| State | Badge |
|---|---|
| QA не е конфигуриран | *(нищо)* |
| Изчаква | `QA 60% · изчаква` (grey) |
| Изпълнява се | `⏳ QA...` (yellow) |
| Минало | `QA ✓ 84%` (green) |
| Неминало, retry | `QA ✗ 51% · retry 2/3` (orange) |
| Неминато, изчерпани опити | `QA ✗ 48%` (red) |

Show `retry N/M` only when retries > 0.

**Alpine helpers to update:** `stepQaLabel()`, `stepQaResult()` — already exist, extend them to return the badge data above.

---

### 4. Backend — Custom Prompt Support

**File:** `app/Services/FlowExecutorService.php` — `runStepQaGate()`

Currently calls `QaVerifierAgent` with the verifier's default `prompt_template`. Change: if `policy['custom_prompt']` is set and non-empty, pass it as the prompt override instead of the verifier agent's template.

**File:** `app/Agents/QaVerifierAgent.php`

Add an optional `$promptOverride` parameter to the `run()` method. If provided, use it as the prompt; otherwise fall back to the agent's `prompt_template`.

**File:** `app/Http/Controllers/FlowRunController.php`

Remove the `updateQaThresholds()` method and its route (no longer needed — thresholds are set per-step in `config.qa`).

---

### 5. AI Generation — Auto-QA per Agent

**File:** `app/Services/AgentGeneratorService.php`

**Change the system prompt** to:

- **Remove** the rule "always include exactly one qa_verifier at the end"
- **Add** rule: include exactly one `qa_verifier` agent (can be anywhere, it will be hidden from UI)
- **Add** rule: every non-verifier agent **must** include `config.qa`:
  ```json
  {
    "temperature": 0.7,
    "num_predict": 1000,
    "qa": {
      "enabled": true,
      "verifier_agent_uid": "qa_main",
      "threshold": 60,
      "max_retries": 3,
      "custom_prompt": "<Bulgarian prompt tailored to this agent's output>"
    }
  }
  ```
- The `qa_verifier` agent must have `"uid": "qa_main"` in its generated JSON so the references resolve

**The `custom_prompt` for each agent** should describe what specifically to validate — tailored to the agent's `role` and `output_description`. Example rules to add to the AI prompt:
  - For `competitor_profiler`: verify at least 3 competitors found with names and URLs
  - For `deep_researcher`: verify sources cited and key findings present
  - For `analyzer`: verify structured data/JSON output is valid and complete
  - For `report_composer`: verify report has all required sections and is in Bulgarian

**File:** `resources/views/flows/create.blade.php` — frontend normalization (lines ~750–791)

Update the agent normalization loop to handle `config.qa.verifier_agent_uid` references: after generation completes, find the qa_verifier agent's position, then populate `config.qa.verifier_agent_id` for each agent that references it by UID. This wiring already exists partially — extend it to also pass through `custom_prompt`.

---

## Files to Modify

| File | Change |
|---|---|
| `app/Services/AgentGeneratorService.php` | New AI prompt: per-agent QA config + custom_prompt |
| `app/Services/FlowExecutorService.php` | Pass `custom_prompt` override to QA gate |
| `app/Agents/QaVerifierAgent.php` | Accept `$promptOverride` in `run()` |
| `app/Http/Controllers/FlowRunController.php` | Remove `updateQaThresholds()` |
| `resources/views/flows/show.blade.php` | Two-tab edit panel, remove header dropdowns, fix save bug |
| `resources/views/runs/show.blade.php` | Badge per step, hide verifier agents from list |
| `resources/views/flows/create.blade.php` | Normalize `custom_prompt` from generated agents |
| `routes/web.php` | Remove `updateQaThresholds` route |

No database migrations required.

---

## Verification

1. **Flow builder:** Open an existing flow → edit an agent → QA tab appears → enable QA, set threshold 70%, retries 3, custom prompt → Save → refresh page → settings persist
2. **Run page:** Start a run → QA verifier agents do NOT appear as numbered steps → after each step completes, QA badge appears with score → if score < threshold, step retries → badge shows `retry N/M`
3. **Header:** No QA% dropdowns in the flow header
4. **AI generation:** Create new flow → "Генерирай агенти с AI" → generated agents all have QA tab pre-filled with enabled=true, threshold=60%, retries=3, and a Bulgarian custom_prompt relevant to each agent's task
5. **Custom prompt execution:** Run a flow where a step has a custom QA prompt → check run logs → verifier received the custom prompt, not the default template
