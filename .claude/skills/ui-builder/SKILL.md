---
name: ui-builder
description: >
  Use this skill for FlowAI frontend and builder UI work, for requests like "style this page",
  "build an admin, client, or org screen", "fix the Blade component", "work on the Drawflow
  builder", "adjust the design tokens", or "improve responsive or accessibility". Covers Blade,
  Tailwind v4, Alpine, Drawflow, and the design system. Ported from the Codex flowai-ui-builder.
---

# FlowAI UI builder

Do focused UI work on FlowAI without breaking data contracts or the design system.
The stack is Laravel Blade, Vite, Tailwind CSS v4, Alpine.js, and Drawflow.
Do not migrate to a SPA or add a new frontend framework unless the user explicitly asks for that architecture change.

## Before editing

1. Identify the surface: admin, client portal, AI Organization, Drawflow builder, or shared component.
2. Read `resources/css/app.css`, the token source of truth, plus the nearest existing layout and component before adding markup.
3. Search for an existing pattern with `rg` before creating a new component.
4. Preserve route, controller, Alpine, and form contracts unless the user asked for behavior changes.

## Reference routing

- Read `references/design-system.md` for tokens, typography, colors, status rules, motion, and anti-patterns.
- Read `references/drawflow-guardrails.md` before any builder or graph UI change.
- Read `references/ui-workflows.md` for per-surface guidance, Tailwind v4 safety, Alpine, accessibility, and responsive checks.

## Core guardrails

- Keep the UI light, professional, dense, readable, and operational.
- Use existing Blade components and design tokens before adding new patterns; do not hardcode hex in Blade.
- Show status with icon, text, and color together, never color alone.
- Keep numbers tabular and stable with `tabular-nums`.
- Do not build Tailwind utility names by string concatenation; safelist the full set with `@source inline(...)` when dynamic classes are unavoidable.
- Do not mutate Drawflow ids, `data-*` contracts, node data shape, or export format.
- Do not use gradient text, default glassmorphism, nested cards, side-stripe borders, AI purple gradients, or neon and gamer styling.
- Use Heroicons through the existing icon system; no emoji as icons.

## Verify

- Static inspection and `git diff --check`.
- `npm run build` only when frontend assets changed and the user did not forbid it.
- Use browser or screenshot inspection when a dev server is available and visual behavior matters.
- Do not run tests or eval suites.
