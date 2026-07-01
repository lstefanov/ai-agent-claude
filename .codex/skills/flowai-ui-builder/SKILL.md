---
name: flowai-ui-builder
description: Project-specific UI workflow for FlowAI Blade, Tailwind CSS v4, Alpine.js, Drawflow, admin screens, client portal screens, AI Organization screens, Blade components, resources/css/app.css, resources/js, responsive polish, accessibility, and visual QA in /Users/lub/Sites/localhost/ai-agent-claude.
---

# FlowAI UI Builder

## Start Here

Use this skill for UI work in FlowAI.
The stack is Laravel Blade, Vite, Tailwind CSS v4, Alpine.js, and Drawflow.
Do not migrate to a SPA or introduce a new frontend framework unless the user explicitly asks for that architecture change.

Inspect `PRODUCT.md`, `DESIGN.md`, `resources/css/app.css`, and a representative existing Blade component before changing UI.
For Drawflow builder work, read `references/drawflow-guardrails.md` before editing.

## Core Guardrails

- Keep the UI light, professional, dense, readable, and operational.
- Use existing Blade components and design tokens before adding new patterns.
- Use Heroicons through the existing icon system.
- Do not use emoji as icons.
- Do not use dynamic Tailwind utility concatenation unless the full class set is safelisted or statically visible.
- Do not mutate Drawflow ids, `data-*` contracts, node data shape, or export format.
- Do not put cards inside cards.
- Do not use gradient text, default glassmorphism, side-stripe borders, generic AI purple gradients, or gamer/neon styling.
- Show status with icon, text, and color together.
- Keep numbers tabular and stable.

## Reference Routing

- Read `references/design-system.md` for FlowAI identity, tokens, typography, colors, status rules, and anti-patterns.
- Read `references/ui-workflows.md` for admin, client portal, AI Organization, component, responsive, accessibility, and verification workflow.
- Read `references/drawflow-guardrails.md` before any builder or graph UI change.
- Read `references/visual-audit.md` before UI critique, redesign, polish, or final visual QA.

## Working Pattern

1. Identify the surface: admin, client portal, AI Organization, builder, or shared component.
2. Read the nearest layout, component, and page that already solve a similar problem.
3. Preserve server-rendered Blade and Alpine contracts.
4. Make focused visual and interaction changes without changing data contracts.
5. Check mobile, desktop, empty, loading, error, focus, and reduced-motion states where relevant.
6. Use only allowed verification from the repository rules.

## UI Audit Pattern

1. Scan the existing design system and the nearest comparable surface.
2. Diagnose visual hierarchy, contrast, layout, interaction states, motion, responsiveness, and copy.
3. Fix targeted issues using the existing stack and tokens.
4. Verify the changed surface visually and with allowed static checks.

## Repo-Local Install Note

This skill is intentionally repo-local under `.codex/skills`.
If Codex does not auto-discover repo-local skills in a future session, copy or symlink this folder into `~/.codex/skills`.
