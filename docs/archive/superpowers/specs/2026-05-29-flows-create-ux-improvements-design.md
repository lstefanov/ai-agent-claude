# Design Spec: Flows Create UX Improvements
**Date:** 2026-05-29  
**Status:** Approved

---

## Overview

Six UX improvements to the `/flows/create` page and agent generation system, focused on Bulgarian-language output, better usability, and inline agent editing.

---

## Feature 1 — Bulgarian agent names

**What:** The AI generator must produce descriptive Bulgarian names for each agent instead of English snake_case identifiers.

**Where:** `AgentGeneratorService.php` — system prompt + user message.

**Change:** In the JSON schema instruction, change `"name": "snake_case_name"` to require a short, descriptive Bulgarian phrase (e.g., "Изследовател на тенденции", "Автор на Facebook постове"). The `type` field stays in English snake_case for internal use. The UI already renders `agent.name` prominently — no frontend change needed.

---

## Feature 2 — Bulgarian agent descriptions (role field)

**What:** The `role` field must be written in Bulgarian and be more detailed — 2–3 sentences explaining what the agent does, what input it expects, and what it outputs.

**Where:** `AgentGeneratorService.php` — user message schema description.

**Change:** Update the schema instruction for `role` from "one-sentence job description" to "2–3 sentence description in Bulgarian explaining: what this agent does, what input it receives, and what it produces."

---

## Feature 3 — Bulgarian system prompt and prompt_template

**What:** The `prompt_template` and a new `system_prompt` field must be generated in Bulgarian (for Bulgarian-language flows). The prompt_template must be substantive — at minimum 5–6 sentences with specific formatting instructions, tone, length, and placeholders like `{{company_description}}`, `{{input}}`.

**Where:** `AgentGeneratorService.php` — schema + agent normalizer. Also: `agents` DB table and `Agent` model if `system_prompt` is not already a column (check migration).

**Change:**
- New migration: add `system_prompt` (`longText`, nullable) column to the `agents` table (confirmed missing from `2026_05_28_130658_create_agents_table.php`).
- Add `system_prompt` to the JSON schema the AI generates.
- Update `normalizeAgent()` to include `system_prompt`.
- Update `Agent` model `$fillable` to include `system_prompt`.
- Update the instruction: `prompt_template` must be at least 5 sentences in Bulgarian, with concrete placeholders and format/tone instructions. Always generate both fields in Bulgarian (the app is Bulgarian-focused; no language detection needed).

---

## Feature 4 — Inline agent editing + post-save editing

**What:** Two editing surfaces:

### 4a — Inline editing on `/flows/create` (before save)
Each agent card in "Step 3: Agents" has an **Edit** button that expands an inline form panel. The panel allows editing:
- Name (Bulgarian text input)
- Type (select from known types)
- Role / description (textarea, Bulgarian)
- System prompt (textarea, monospace)
- Prompt template (textarea, monospace, larger — min 5 rows)
- Model (select)

Actions: **Save changes** (collapses panel, updates Alpine `agents` array) / **Cancel**.

Add/delete/reorder (drag + ↑↓ buttons):
- **Add agent** button at bottom appends a blank agent with defaults; user fills inline form.
- **Delete** (✕) button removes agent from array after confirm.
- **Drag handle** (three horizontal bars, left of card) enables drag-to-reorder via SortableJS or Alpine drag logic.
- **↑ / ↓ buttons** for keyboard-accessible reordering.
- After any reorder, `order` field on each agent is re-numbered sequentially (1, 2, 3…).

**Constraint:** No validation prevents the user from placing a QA verifier anywhere in the chain — the UI allows full freedom. A soft warning appears if a `qa_verifier` type agent is not last.

### 4b — Post-save editing on `/agents/{agent}/edit`
The existing `agents/edit.blade.php` page already exists. It needs the same field set as 4a (name, role, system_prompt, prompt_template, model, type). Verify the existing form covers all fields; add missing ones.

**Route:** Existing `AgentController@edit` / `AgentController@update` — extend if needed.

---

## Feature 5 — AI "Improve description" button

**What:** A button inside the "Flow description" textarea that sends the current text to the AI and shows an improved version in a preview panel below the textarea. The user can **Accept** (replaces textarea content) or **Discard** (dismisses panel).

**Where:** `flows/create.blade.php` Alpine component + new backend route.

**New route:** `POST /flows/improve-description` — accepts `{ description: string, name: string, company_id: int }`, calls Ollama with a short prompt to expand and improve the description in Bulgarian. Returns `{ improved: string }`.

**UI:**
- Button positioned bottom-right inside the textarea (absolute positioned).
- While improving: button shows spinner, is disabled.
- Preview panel appears below textarea with the improved text, Accept and Discard buttons.
- Accept replaces `flowDescription` Alpine variable and dismisses panel.
- Discard dismisses panel without changes.

**Prompt for improvement:** System prompt instructs the AI to expand the description to be more specific, adding context about the pipeline structure, target audience, language requirements, and output format. Response must be 3–5 sentences in Bulgarian. Return only the improved description text, no preamble.

---

## Feature 6 — Apple-style schedule UI (Hybrid)

**What:** Replace the raw cron `<input type="text">` with a user-friendly schedule picker. Hybrid design: preset buttons + time picker + "Advanced" fallback for raw cron.

**Where:** `flows/create.blade.php` and `flows/edit.blade.php`.

**UI structure:**
1. **Preset grid (4 buttons):** Hourly / Daily / Weekly / Monthly — each shows an icon and label.
2. **Contextual time picker:** Appears based on selected preset:
   - Hourly: no extra picker needed (runs every hour at :00).
   - Daily: "At what time:" → `<select>` with hours (00:00–23:00).
   - Weekly: "Which day:" → day-of-week select + time select.
   - Monthly: "Which day of month:" → 1–28 select + time select.
3. **Human-readable summary:** "Will run every day at 10:00 · cron: `0 10 * * *`"
4. **"Advanced (custom)" button:** Expands a raw cron text input for power users, hidden by default.
5. **"No schedule" option:** Default state — flow runs only manually.

**Cron generation:** Alpine computes the cron string from the selected preset + time values. The hidden `<input name="schedule_cron">` is updated reactively. The backend receives the same cron string as before — no server-side changes needed.

---

## Architecture Notes

- All backend changes are in `AgentGeneratorService.php` (features 1–3) and a new controller method for feature 5.
- All frontend changes are in `flows/create.blade.php` using Alpine.js (already in use).
- **SortableJS** (CDN) for drag-to-reorder — loaded via `<script>` tag, no build step, integrates cleanly with Alpine.js `x-for` lists.
- No database migrations needed unless `system_prompt` column is missing from `agents` table (check `2026_05_28_130658_create_agents_table.php`).

---

## Out of scope

- Real-time collaboration or conflict resolution.
- Publishing to Facebook directly (separate feature).
- Mobile-specific layouts (responsive is fine, no special mobile design).
