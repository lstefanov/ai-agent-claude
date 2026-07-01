# Agent Template Picker — Design Spec

**Date:** 2026-05-30  
**Status:** Approved

---

## Overview

When clicking "＋ Добави агент" in the Flow create page, instead of immediately creating a blank agent, a popup modal opens. The popup shows a searchable grid of agent templates organized in three tabs. Selecting a template clones its data into a new agent in the Alpine `agents[]` array (no DB write happens until the form is submitted).

---

## Database

### New table: `agent_templates`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `company_id` | bigint FK nullable | `null` = system (global) template |
| `name` | string | Shown on the picker card |
| `description` | text | Short text shown on the picker card |
| `icon` | string | Emoji (e.g. `📧`) |
| `type` | string(50) | Matches `agents.type` values |
| `role` | text | |
| `system_prompt` | text | |
| `prompt_template` | longtext | |
| `model` | string(100) | Default model suggestion |
| `capabilities` | json nullable | |
| `strengths` | text nullable | |
| `limitations` | text nullable | |
| `input_description` | text nullable | |
| `output_description` | text nullable | |
| `is_verifier` | boolean | default false |
| `qa_threshold` | tinyint nullable | |
| `config` | json | `{temperature: 0.7, num_predict: 1000}` — same defaults as `addAgent()` |
| `sort_order` | smallint | Ordering within each group in the picker |
| `timestamps` | | |

`company_id` is a nullable FK to `companies`. Templates with `company_id = null` are system templates visible to all companies.

---

## Cloning Behaviour

When the user selects a template, all fields are copied into a new Alpine agent object (identical structure to the existing `addAgent()` output) with a fresh `_uid`. The template record is not modified. The agent is saved to the DB only when the Flow form is submitted — no extra write at selection time.

---

## Routes

### API endpoint (used by the popup)

```
GET /agent-templates/picker?company_id={id}
```

Protected by `auth` middleware. Returns JSON:
```json
{
  "system": [ { id, name, description, icon, type, ... }, ... ],
  "company": [ { id, name, description, icon, type, ... }, ... ]
}
```

Called lazily (single fetch) when the popup is first opened. Results are cached in the Alpine component for the page lifetime — no repeated fetches on re-open.

### Company agent template management

```
GET    /companies/{company}/agent-templates              → index
GET    /companies/{company}/agent-templates/create       → create form
POST   /companies/{company}/agent-templates              → store
GET    /companies/{company}/agent-templates/{template}/edit → edit form
PUT    /companies/{company}/agent-templates/{template}   → update
DELETE /companies/{company}/agent-templates/{template}   → destroy
```

Controller: `App\Http\Controllers\AgentTemplateController`

### Admin system template management

```
GET    /admin/agent-templates              → index
GET    /admin/agent-templates/create       → create form
POST   /admin/agent-templates              → store
GET    /admin/agent-templates/{template}/edit → edit form
PUT    /admin/agent-templates/{template}   → update
DELETE /admin/agent-templates/{template}   → destroy
```

Controller: `App\Http\Controllers\Admin\AgentTemplateController`  
Middleware: `IsAdmin` (checks `users.is_admin = true`)

---

## Popup UI

**Trigger:** "＋ Добави агент" button in `create.blade.php` calls `openAgentPicker()` instead of `addAgent()`.

**Layout:** Modal overlay, 800px wide, Alpine.js powered.

### Tabs (act as filters)

| Tab | Content |
|---|---|
| **Всички** (default) | Blank agent card at top, then "🏢 Моите агенти" section, then "⚙ Системни агенти" section |
| **🏢 Моите агенти** | Only company templates — no blank agent card |
| **⚙ Системни агенти** | Only system templates — no blank agent card |

**Search input** filters visible cards by name or type (client-side, across current tab's content).

**Blank agent card** — spans full width (4 columns), dashed border, always at top of "Всички" tab only.

**Template cards** — 4-column grid, show: icon, name, description, type badge (green for company, purple for system). Hover: indigo border + lift shadow.

**On card click:** populate Alpine agent object with template data → close modal → open inline edit panel for that agent.

---

## Company Agent Templates Page (`/companies/{company}/agent-templates`)

- Breadcrumb: `← {Company Name}`
- Header: "🤖 Агенти на компанията" + "＋ Нов агент шаблон" button
- List of agent rows: icon, name+badge, description, Edit + Delete actions
- Create/Edit form fields: icon (emoji), name, short description, type (select), default model (select), role, system_prompt, prompt_template
- Linked from company nav/show page

---

## Admin System Templates Page (`/admin/agent-templates`)

Identical layout to the company page. Accessible only to users with `is_admin = true`. Manages templates with `company_id = null`.

### Admin access

New `is_admin` boolean column on `users` table (migration). New `IsAdmin` middleware:

```php
if (!auth()->user()?->is_admin) abort(403);
```

---

## Seeder

`AgentTemplateSeeder` creates 8 initial system templates:

| Icon | Name | Type |
|---|---|---|
| 📧 | Email Изпращач | email |
| 🔍 | Уеб Изследовател | researcher |
| 📊 | Анализатор | analyzer |
| ✍️ | Съдържание BG | content_bg |
| 🌐 | Преводач | translator |
| ✅ | QA Верификатор | qa_verifier |
| 📝 | Обобщителят | summarizer |
| 🤔 | Решение | decision |

Each seeded with sensible defaults for role, system_prompt, prompt_template, config.

---

## Files to Create / Modify

### New files
- `database/migrations/…_create_agent_templates_table.php`
- `database/migrations/…_add_is_admin_to_users_table.php`
- `database/seeders/AgentTemplateSeeder.php`
- `app/Models/AgentTemplate.php`
- `app/Http/Controllers/AgentTemplateController.php`
- `app/Http/Controllers/Admin/AgentTemplateController.php`
- `app/Http/Middleware/IsAdmin.php`
- `resources/views/companies/agent-templates/index.blade.php`
- `resources/views/companies/agent-templates/create.blade.php`
- `resources/views/companies/agent-templates/edit.blade.php`
- `resources/views/admin/agent-templates/index.blade.php`
- `resources/views/admin/agent-templates/create.blade.php`
- `resources/views/admin/agent-templates/edit.blade.php`
- `resources/views/admin/layouts/admin.blade.php` (simple admin layout)

### Modified files
- `routes/web.php` — add company + admin template routes + picker API route
- `resources/views/flows/create.blade.php` — replace `addAgent()` with picker modal
- `resources/views/companies/show.blade.php` — add link to agent templates section
- `database/seeders/DatabaseSeeder.php` — call `AgentTemplateSeeder`
- `bootstrap/app.php` — register `IsAdmin` middleware alias

---

## Out of Scope

- Editing agents already attached to a flow via the template system (clones are independent)
- Template versioning
- Sharing company templates between companies
- The "Добави агент" button on the flow show page (not in scope for this iteration)
