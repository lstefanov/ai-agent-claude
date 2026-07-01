# FlowAI Design System

## Product Register

FlowAI is a professional control room for an operational AI organization.
The user manages a living AI team of managers, directors, and assistants.
The UI should feel alive, characterful, and professional without losing operational clarity.
The experience is light, dense, readable, and work-focused.

## Visual Strategy

- Use a light theme only.
- Use zinc neutrals for operational surfaces.
- Use instrument azure as the primary accent.
- Use role and domain colors only as functional identity for people, departments, skills, rings, tags, and stat bars.
- Keep operational screens restrained.
- Let profile surfaces show more character through portraits, roles, skill color, and progress.
- Never sacrifice costs, QA scores, tokens, levels, or status readability for decoration.

## Tokens

`resources/css/app.css` is the token source of truth.
Do not hardcode hex values in Blade.
Use Tailwind utilities generated from the existing `@theme` tokens.

Core token roles:

- `canvas`, `surface`, and `surface-subtle` for page and panel surfaces.
- `line` and `line-strong` for borders.
- `ink`, `muted`, and `subtle` for text.
- `primary`, `primary-hover`, and `primary-fg` for main actions.
- `accent` for decorative active or running signals, focus glow, and graph lines.
- `success`, `warning`, `danger`, and `info` for semantic status.
- `char-*`, `char-*-soft`, and `char-*-strong` for role and domain identity.
- `star` for model level.

## Typography

- Use IBM Plex Sans for display and body.
- Use JetBrains Mono for code, model names, providers, prices, tokens, and metrics.
- Use `tabular-nums` for prices, QA scores, tokens, credits, tables, and counters.
- Use balanced wrapping for h1 through h3 where appropriate.
- Keep prose line length around 65 to 75 characters.
- Do not scale font size directly with viewport width.
- Do not use negative letter spacing beyond the established display floor.

## Components

Prefer existing Blade components:

- `x-button`
- `x-card`
- `x-badge`
- `x-field`
- `x-input`
- `x-textarea`
- `x-select`
- `x-modal`
- `x-table`
- `x-segmented`
- `x-tabs`
- `x-breadcrumb`
- `x-empty-state`
- `x-icon`
- `x-stars`

Use profile-layer components when working on AI Organization surfaces:

- member avatar partials and avatar components
- skill chips
- skill member nodes
- team assistant and lead cards
- task cards
- credit meters when available

## Status Rules

Status must never be color-only.
Use icon, text, and color together.
Common mapping:

- `pending` uses neutral or subtle treatment.
- `running` uses accent and may use restrained motion.
- `success` uses success.
- `failed` uses danger.
- `cancelled` uses muted treatment.
- `waiting_approval` must be visibly actionable.

## Motion

Use the existing motion tokens in `app.css`.
Button press should be around 100 to 160 ms.
Dropdowns and selects should be around 150 to 250 ms.
Modals and drawers should be around 200 to 320 ms.
Animate transform and opacity before layout properties.
Do not use `transition: all`.
Respect `prefers-reduced-motion`.
Do not animate frequent keyboard-first actions.

## Iconography

Use Heroicons through the existing icon system.
Use `aria-label` for icon-only buttons.
Do not use emoji as icons.
Do not mix icon sets casually.

## Anti-Patterns

- Generic Bootstrap admin styling.
- Enterprise clutter.
- Neon, dark gamer, or sci-fi styling.
- Childish gamification such as XP popups or confetti.
- Aggressive paywall or pay-to-win patterns.
- Indigo to purple AI gradients.
- Gradient text.
- Glassmorphism by default.
- Side-stripe borders.
- Nested cards.
- Identical card grids as the default layout.
- Tiny uppercase eyebrow labels on every section.
- Numbered section markers as default scaffolding.
- Status conveyed by color alone.
- Rounded everything with oversized radii.
- Decorative effects that reduce data clarity.
