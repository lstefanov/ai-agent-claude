# Token Helper UI — Design Spec
**Date:** 2026-05-31  
**Status:** Approved

## Context

The prompt templates and system prompts in the AI agent editor support `{{token}}` placeholders that are substituted at runtime (e.g. `{{company_name}}`, `{{input}}`, `{{Изследовател}}`). Currently there is only a single line of helper text below the field with no detail on what tokens exist or how they work. Users have to guess or look at existing templates.

The goal is a **collapsible inline reference panel** below every `system_prompt` and `prompt_template` field — everywhere these fields appear — showing all available tokens grouped by category, each with a detailed description. Clicking a token inserts it at the cursor position in the textarea.

---

## Token Catalogue

### 🏢 Компания (always available)

| Token | Source | Description |
|---|---|---|
| `{{company_name}}` | `Company.name` | Името на компанията. Пример: "Иванов и синове ООД". Взето от профила на компанията. |
| `{{company_description}}` | `Company.description` | Пълното описание на дейността, мисията и ценностите на компанията. Взето от профила на компанията. |
| `{{company_industry}}` | `Company.industry` | Индустрията / секторът на компанията. Пример: "Технологии", "Финанси", "Търговия на дребно". |

### 📥 Вход на флоуто (always available)

| Token | Source | Description |
|---|---|---|
| `{{input}}` | `Flow.topic` → updated | Текущият вход за агента. В началото е темата на флоуто. След всеки агент се **обновява** с изхода на предишния агент. Използвай когато искаш последния наличен контекст. |
| `{{topic}}` | `Flow.topic` → updated | Идентичен с `{{input}}` — алтернативно наименование. Обновява се след всеки агент. |
| `{{flow_topic}}` | `Flow.topic` → preserved | Оригиналната тема на флоуто — **никога не се сменя** независимо какво са изходили предишните агенти. Използвай когато искаш да се върнеш към първоначалното задание. |

### 🤖 Изходи от агенти (flow context only)

| Token | Source | Description |
|---|---|---|
| `{{ИмеНаАгент}}` | `context[$agent->name]` | Пълният изход на конкретен агент от флоуто. Токънът е **точното им на агента**. Достъпен само след като агентът е изпълнен, т.е. само за агенти с по-малък `order`. Пример: `{{Изследовател на конкуренти}}`. |

In template context (no flow): section is visible but shows explanatory placeholder instead of chips.

---

## UI Design

### Component: `partials/token-helper.blade.php`

Single reusable partial used everywhere. Parameters:

```php
@include('partials.token-helper', [
    'textareaId' => 'system_prompt_field',   // string — DOM id of the textarea
    'agents'     => $agentNames,             // array of name strings | null (template context)
    'xAgents'    => null,                    // Alpine expression string | null (for Alpine contexts)
])
```

**States:**
- **Collapsed (default):** Shows `▶ Налични токъни [N]` button — N = count of non-agent tokens + agent count
- **Expanded:** Shows 3 grouped sections with token chips + descriptions

**Token chip click:** calls global `insertToken(textareaId, token)` which:
1. Focuses the textarea
2. Gets `selectionStart` / `selectionEnd`
3. Inserts `{{token}}` at cursor, replacing any selection
4. Sets `selectionStart = selectionEnd = cursor + token.length`
5. Fires `input` event (triggers Alpine `x-model` reactivity)

**Agent section — two rendering modes:**
- **Server-side** (`$agents` param): Blade `@foreach` renders chips. Used in `agents/edit.blade.php` (agents ordered before current agent) and template forms (null → placeholder text).
- **Alpine-driven** (`$xAgents` param): renders `<template x-for>` from an Alpine expression. Used in `flows/show.blade.php` and `flows/create.blade.php` where agents are managed client-side.

---

## Files to Modify

### New file
- `resources/views/partials/token-helper.blade.php` — the component

### Existing files (add `@include` after each prompt textarea)

| File | Fields | Agent tokens |
|---|---|---|
| `resources/views/agents/edit.blade.php` | `system_prompt`, `prompt_template` | Server-side: agents from `$flow->agents` with `order < $agent->order` |
| `resources/views/flows/show.blade.php` | `system_prompt`, `prompt_template` (inline Alpine editor) | Alpine-driven: agents with `order < agent.order` from Alpine state |
| `resources/views/flows/create.blade.php` | `system_prompt`, `prompt_template` (inline Alpine editor) | Alpine-driven: same |
| `resources/views/admin/agent-templates/_form.blade.php` | `system_prompt`, `prompt_template` | null (template context) |
| `resources/views/companies/agent-templates/_form.blade.php` | `system_prompt`, `prompt_template` | null (template context) |

### textarea IDs
The current textareas in `agents/edit.blade.php` and template forms don't have `id` attributes — add them. For Alpine inline editors, bind id with `:id="'sp-' + index"` and `:id="'pt-' + index"`.

---

## Global JS function

Add `insertToken(id, token)` to `layouts/app.blade.php` (or a shared script block):

```js
function insertToken(id, token) {
    const el = document.getElementById(id);
    if (!el) return;
    el.focus();
    const start = el.selectionStart;
    const end = el.selectionEnd;
    const val = el.value;
    el.value = val.slice(0, start) + '{{' + token + '}}' + val.slice(end);
    const pos = start + token.length + 4; // 4 = length of '{{' + '}}'
    el.selectionStart = el.selectionEnd = pos;
    el.dispatchEvent(new Event('input', { bubbles: true }));
}
```

---

## Verification

1. Open `/flows/{id}/agents/{id}/edit` → expand token panel on both fields → click `{{company_name}}` → appears at cursor in textarea
2. Open `/flows/{id}` → open inline agent editor → same test
3. Open `/admin/agent-templates/create` → expand panel → agent section shows placeholder text (no flow context)
4. Open `/companies/{id}/agent-templates/create` → same as admin
5. Verify Alpine `x-model` syncs: after inserting token in `flows/show` inline editor → save agent → token appears in saved `prompt_template`
6. Verify collapsed state is default and count badge shows correct number
