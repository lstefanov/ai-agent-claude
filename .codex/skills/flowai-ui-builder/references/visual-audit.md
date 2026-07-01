# FlowAI Visual Audit

Use this reference before UI critique, redesign, polish, or final visual QA.
Keep changes focused on the requested surface and the adjacent UI needed to make it coherent.
Do not copy Claude design hooks or commands into Codex behavior.

## Scan

- Read `PRODUCT.md`, `DESIGN.md`, and `resources/css/app.css`.
- Identify whether the surface is admin, client portal, AI Organization, builder, shared component, or layout.
- Read the nearest existing page and component that solve a similar UI problem.
- Confirm the existing tokens, component names, icon path, Alpine state, and route contracts.
- Check Tailwind v4 class visibility before using dynamic utilities.

## Visual Hierarchy

- Make the primary action and primary status obvious.
- Keep operational data denser than marketing pages but still scannable.
- Use cards only for repeated items, modals, or genuinely framed tools.
- Avoid nested cards.
- Align titles, descriptions, controls, and numeric columns across repeated items.
- Use whitespace to group tasks and state, not to decorate.
- Keep page-level headings smaller inside dense dashboards than in true hero surfaces.

## Contrast And Color

- Body text must meet WCAG AA contrast.
- Placeholder text must also be readable.
- Avoid washed-out gray text on tinted backgrounds.
- Use `ink`, `muted`, and semantic strong text tokens instead of ad hoc color.
- Keep azure accent usage restrained on operational surfaces.
- Use role and domain colors only when they encode function.
- Never use color as the only status signal.

## Typography And Numbers

- Use IBM Plex Sans for display and body.
- Use JetBrains Mono for code, model names, providers, prices, tokens, and metrics.
- Use `tabular-nums` for changing or aligned numbers.
- Keep prose around 65 to 75 characters per line.
- Use `text-wrap: balance` or `text-wrap: pretty` where it improves headings or prose.
- Avoid all-caps labels as a reflex.
- Avoid title case everywhere in Bulgarian UI.
- Make long Bulgarian labels wrap or truncate intentionally.

## Layout And Responsive Behavior

- Test mentally or visually at 375 px, normal desktop, and wide desktop.
- Avoid horizontal scroll at mobile widths.
- Use `min-w-0` for flex children with long text.
- Use stable dimensions for toolbars, counters, grids, boards, tiles, and icon buttons.
- Use grid for real two-dimensional layout and flex for one-dimensional layout.
- Avoid percentage math that makes responsive behavior fragile.
- Keep dropdowns and popovers out of clipped overflow containers.
- Use a semantic z-index scale instead of arbitrary large values.

## Interaction States

- Every button, link, tab, segmented control, input, menu item, and icon button needs a clear hover state.
- Every interactive element needs visible focus.
- Pressable controls should provide subtle active feedback when that will not slow frequent keyboard use.
- Loading states should match the layout shape when work takes visible time.
- Empty states should tell the user what can happen next.
- Error states should be direct, inline when possible, and recoverable.
- Disabled states should explain themselves when context is not obvious.
- Toasts should not steal focus and should use `aria-live="polite"`.

## Motion

- Animate only when motion explains state, preserves spatial continuity, or provides useful feedback.
- Avoid animation for frequent keyboard-first actions.
- Prefer transform and opacity.
- Avoid `transition: all`.
- Avoid animating width, height, top, left, or other layout-heavy properties.
- Use existing motion tokens from `resources/css/app.css`.
- Keep common UI motion under roughly 320 ms.
- Respect `prefers-reduced-motion`.
- Do not animate from `scale(0)`.
- Ensure reveal animations enhance already-visible content instead of hiding content until JavaScript runs.

## Accessibility

- Use semantic HTML for nav, main, article, aside, section, forms, and buttons.
- Label every form field.
- Add `aria-label` for icon-only buttons.
- Keep modal focus traps, Escape behavior, and focus return predictable.
- Keep touch targets comfortable, especially on mobile.
- Do not rely on hover-only affordances.
- Provide alt text for meaningful images.

## Copy And Content

- Keep existing Bulgarian UI language when the surface is Bulgarian.
- Use plain, specific labels.
- Avoid hype words and vague marketing copy.
- Avoid game terms such as quest, hero, character, XP, boost, and pay-to-win language.
- Do not use lorem ipsum or fake generic names in product surfaces.
- Make success messages calm.
- Make error messages direct.

## AI UI Anti-Patterns

Refuse and rewrite these patterns:

- Indigo to purple AI gradients.
- Gradient text.
- Decorative glassmorphism.
- Side-stripe card borders.
- Hero metric blocks used as default scaffolding.
- Identical icon-card grids.
- Tiny uppercase eyebrow labels on every section.
- Numbered section markers used as decoration.
- Neon or dark gamer styling.
- Decorative effects that reduce data clarity.
- Generic Bootstrap admin styling.
- Overly rounded everything.
- Stock or placeholder imagery that hides the real product state.

## Final Visual QA

- Check alignment, spacing, contrast, focus, hover, active, disabled, loading, empty, and error states.
- Check text overflow and clipped popovers.
- Check mobile, desktop, and wide layouts.
- Check that status uses icon, text, and color together.
- Check that costs, QA scores, tokens, credits, and levels remain readable.
- Check that no Drawflow data contract changed during builder UI work.
- Use browser or screenshot inspection when a dev server is available and visual behavior matters.
