---
name: add-http-surface
description: >
  Use this skill when adding a route, controller, or page in FlowAI, for requests like
  "add a page", "add a route", "add an admin or client screen", or "expose an endpoint".
  Covers the admin, client, and org surfaces, their guards, and frontend safety rules.
---

# Add a FlowAI HTTP surface

Add a route, thin controller, and view on the correct surface.

## Pick the surface first

- Admin app: `routes/web.php`, `is_admin` gated, views under `resources/views/admin`.
- Client portal: `routes/client.php`, `client_auth` gated, views under `resources/views/client`, simplified UX.
- AI Organization: controllers under `app/Http/Controllers/Client/Org/`, routes in `routes/client.php`, views under `resources/views/client/org`.

## Steps

1. Add the route to the surface's route file with the correct guard.
2. Create a thin controller under the matching `app/Http/Controllers/` subfolder.
3. Add the Blade view under the matching `resources/views/` group.
4. Push any real work into a service or queued job.

## Frontend rules

- Tailwind v4 compiles only statically visible classes; do not build utility names by string concatenation in PHP, Blade, or JavaScript, and safelist unavoidable dynamic classes with `@source inline(...)` in `resources/css/app.css`.
- Keep Alpine state local, preserve `x-cloak`, and do not hide critical server-rendered content behind JavaScript-only rendering.
- Accessibility: WCAG 2.1 AA contrast, visible `:focus-visible`, hit targets at least 24 px and at least 44 px on mobile, labels for fields, and `aria-label` for icon-only buttons.
- Responsive: check 375 px, desktop, and wide; no horizontal scroll at 375 px; use `min-w-0` on flex children with long text; long Bulgarian labels wrap or truncate intentionally.
- Client portal copy stays Bulgarian when the existing UI is Bulgarian.
- For deeper visual work, use the `ui-builder` skill.

## Guardrails

- Keep controllers thin.
- Check the active environment before debugging auth, guard, or session issues; local `.env` uses `SESSION_DRIVER=file` while defaults use database sessions.
- Run `npm run build` only when you changed frontend assets and the user did not forbid it.

## Verify

- `php -l` on the controller; static inspection and `git diff --check`.
- Do not run tests or eval suites.
