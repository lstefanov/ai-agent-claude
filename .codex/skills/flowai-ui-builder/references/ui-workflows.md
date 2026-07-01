# FlowAI UI Workflows

## Before Editing

1. Identify the surface: admin, client portal, AI Organization, builder, shared component, layout, or mail view.
2. Read `PRODUCT.md`, `DESIGN.md`, and `resources/css/app.css` when the task touches visual direction or tokens.
3. Read the nearest existing layout and a representative component before adding new markup.
4. Search for an existing pattern with `rg` before creating a new component.
5. Preserve route, controller, Alpine, and form contracts unless the user asked for behavior changes.

## Admin App

Admin screens live behind `routes/web.php` and use `resources/views/layouts/app.blade.php` plus admin layouts where present.
Admin UI should be dense, operational, and clear.
Use tables, filters, segmented controls, badges, and cards only where they clarify repeated items.
Keep model names, provider names, costs, and run metrics readable with mono or tabular number treatment.

## Client Portal

Client portal screens live behind `routes/client.php` and `resources/views/layouts/client.blade.php`.
The client portal is business-facing and should hide unnecessary implementation complexity without hiding truth.
Keep copy in Bulgarian when existing UI is Bulgarian.
Use plain, specific labels.
Avoid game words such as quest, hero, character, XP, or boost in visible UI.

## AI Organization Screens

AI Organization views live under `resources/views/client/org`.
The user should feel they manage a living AI team.
Represent managers, directors, assistants, tasks, decisions, skills, credits, and run progress clearly.
Use functional color for role, domain, skill, or status identity.
Keep cost, level, run state, and approval state visible.
Do not let portrait or profile styling obscure operational facts.

## Shared Components

Prefer existing components before adding markup to pages.
If a page repeats a pattern, extract or reuse a Blade component only when it reduces real duplication or matches existing component style.
Keep component APIs small and explicit.
Avoid passing raw class fragments that build dynamic Tailwind utilities at runtime.

## Tailwind CSS v4

Tailwind v4 compiles classes that are statically visible through configured sources.
Do not build utility class names by string concatenation in PHP, Blade, or JavaScript.
Use explicit match maps for variants.
If dynamic classes are unavoidable, safelist the complete class set with `@source inline(...)` in `resources/css/app.css`.
Keep token changes in `resources/css/app.css`.
Do not add hardcoded hex values to Blade.

## Alpine.js

Keep Alpine state local to the component or page section that owns it.
Preserve `x-cloak` where it prevents flash.
Do not hide server-rendered critical content behind JavaScript-only rendering.
Use semantic buttons and form controls before custom div interactions.
Keep loading, disabled, error, and empty states visible.

## Accessibility

Meet WCAG 2.1 AA for normal text contrast.
Use visible `:focus-visible` treatment for interactive controls.
Keep hit targets at least 24 px, and at least 44 px on mobile where practical.
Use `aria-live="polite"` for non-blocking live status.
Use labels for form fields and `aria-label` for icon-only buttons.
Do not steal focus with toast messages.
Ensure modals trap focus, close with Escape, and return focus predictably.

## Responsive Checks

Check 375 px mobile width, a normal desktop viewport, and a wide desktop viewport when layout changes.
Ensure no horizontal scroll appears at 375 px.
Use `min-w-0` on flex children that contain long text.
Use stable dimensions for boards, grids, toolbars, counters, icon buttons, and tiles.
Ensure long Bulgarian labels wrap or truncate intentionally.

## Visual QA

Be picky about the UI.
Look for text overflow, cramped spacing, inconsistent alignment, missing hover or focus states, clipped dropdowns, invisible loading states, and unclear empty states.
If a UI issue is clearly adjacent to the touched area and low risk, fix it while there.
Do not perform broad unrelated redesigns during a narrow task.

## Verification

Do not run Laravel or PHP test suites.
Do not run eval suites.
Use static inspection and `git diff --check`.
Run `npm run build` only when frontend assets were changed and the user did not forbid it.
For Blade-only changes, use static inspection unless CSS or JS asset compilation is involved.
Use browser or screenshot inspection when a dev server is available and visual behavior matters.
