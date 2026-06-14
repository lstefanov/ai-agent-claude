# FlowAI — UI/UX Redesign Plan (за Claude Code)

> Брийф за пълен редизайн на UI/UX на **всички** страници, popups и навигация.
> Стек: **Laravel 12 + Blade + Tailwind v4 (Vite) + Alpine.js + Drawflow**. Без SPA, без React.
> Характер: **operational / control-room** (анти-„пореден админ панел") · Тема: **само светла** · Език на UI: **български** (не се променя).
> Заемки по зони: **Linear** (навигация, скорост, keyboard-first) · **Stripe** (таблици, форми, числа) · **Grafana/Datadog** (само monitoring) · **Vercel** (вдъхновение, не основа).

> **СТАТУС (2026-06-14):**
> ✅ **Фаза 0** (CDN→Vite, tokens, шрифтове, SVG лого) — изпълнена.
> ✅ **Toolchain** (Impeccable + Emil + Taste skills + детектор hook) — инсталиран в `.claude/skills/`.
> ✅ **Фаза 1** (дизайн система) — `PRODUCT.md` + `DESIGN.md` написани; azure акцент + Emil motion токени в `app.css`; build чист.
> ✅ **Фаза 2** (общи компоненти) — `resources/views/components/` (icon/button/card/badge/field/input/textarea/select/modal/table/segmented/tabs/breadcrumb/empty-state/alert + `danger-outline` бутон); blade-heroicons + `<x-icon>` (blade-icons `components.default=null` за да печели нашият); layout nav+flash + `status-badge` мигрирани.
> ✅ **Типография сменена** (impeccable overused) — Geist/Instrument Sans → **IBM Plex Sans (display+body) + JetBrains Mono**.
> ✅ **Фаза 3, група B (Companies)** — ВСИЧКИ мигрирани (виж по-долу).
> ✅ **РЕДИЗАЙНЪТ Е ЗАВЪРШЕН** — целият app (всички 39 view-а) е azure + zinc + IBM Plex Sans/JetBrains Mono, „operational/control-room" характер. ВСИЧКИ страници рендерират 200, 0 грешки (потвърдено).
> Покрито: B Companies (пълно), C Flows ×11, D Builder (4930 реда, Drawflow data моделът непокътнат, 0 console грешки), E runs/show, F models+admin×6+layout, G partials, A welcome (пренаписан лендинг). Типография Geist/Instrument → IBM Plex Sans/JetBrains Mono. Violet втори акцент → унифициран azure. **Всички emoji-като-икони в заглавия премахнати (0)**; chrome emoji (бутони/панели/headers) → heroicons.
> **Остатъчно (приемливо):** домейн-идентификатори (agent-type node иконки, language флагове, level опции 💎/👑, connector service иконки, phase-picker tier иконки) + монохромни статус-глифове (✓✗↻×★▶) — НЕ четат като slop. **QA одит прескочен по искане на owner-а.** Препоръка: owner да направи ръчен builder generate→save→run smoke тест (изисква Horizon/Ollama).

Как да го изпълниш: чети фаза по фаза; за всяка има готов prompt в раздел 10. Не минавай към следваща фаза преди визуална проверка (`preview_*` + `npx impeccable detect`).

---

## 0. Design toolchain (skills) — ✅ ИНСТАЛИРАН

Гръбнакът на естетиката са три **anti-slop** skills (framework-agnostic, работят с Blade/Tailwind), инсталирани в `.claude/skills/`. Ползвай **един primary наведнъж**, за да няма конфликт на правила.

| Skill | Роля | Как се ползва |
|---|---|---|
| **`impeccable`** (pbakaus, Apache-2.0) | **Гръбнак.** Product register (dashboards/admin/forms), anti-slop „absolute bans", 24 команди, детерминистичен детектор. | Команди: `/impeccable shape|craft|critique|audit|polish|bolder|quieter|...`. Чете `PRODUCT.md`+`DESIGN.md` преди всичко. |
| **`emil-design-eng`** (Emil Kowalski) | **Motion/craft.** Animation Decision Framework, силни easing криви, durations, springs. | Призовавай за motion работа/ревю (toasts, modals, hover, builder live). Не постоянно. |
| **`redesign-existing-projects`** (Taste, MIT) | **Одит/посока.** Layout/spacing/йерархия одит на съществуващ UI. | В началото за посока + втори QA минаване. После изключи (overlap с impeccable). |

- **Детектор без API ключ:** `npx impeccable detect <file|dir|URL>`. URL-вариантът (Puppeteer) тича срещу **живия dev сървър** → лови slop в **компилирания** Blade (file-based detect на `.blade.php` е ограничен — ползвай URL).
- **Hook:** активен (`.claude/settings.local.json` PostToolUse + `.impeccable/config.json`). Сканира `.css/.js/.ts/.html/...` — **НЕ `.blade.php`**; за Blade покритието е през ръчния URL `detect`.
- ⚠️ Skills се викат през Skill tool само в сесия, **закотвена в основната директория** (рестарт). Дотогава: чети `SKILL.md` директно + ползвай `detect` от CLI.

---

## 1. Guardrails — какво да НЕ се чупи

- **Работи в основната директория** `/Users/lub/Sites/localhost/ai-agent-claude` (не worktree). Пипай само Blade/CSS/components/composer — **не backend** (контролери/services/routes), освен нов Blade partial/component.
- **Tailwind v4 purge.** След премахването на Play CDN (Фаза 0) се компилират само класове, **статично присъстващи** във файловете. Не сглобявай Tailwind utility имена в JS/`:class` чрез конкатенация → ще се purge-нат (регресии). Custom `df-*` (hand-written CSS) са safe; за динамични utilities → `@source inline(...)` или пълни имена. Конкретен риск: `flows/builder.blade.php:3718`. Следствие: всяка CSS промяна иска `npm run dev`/`build`.
- **Drawflow графът е свещен.** В `flows/builder.blade.php` не променяй data-модела/`id`/`data-*`, нито Drawflow експорт формата (`GraphNormalizer` е единственото място, което го разбира). Restyle само „хромето". Тествай: генериране → запис → презареждане → run.
- **Без тестове.** Без legacy/back-compat (трий стария път). **UI текстовете остават на български.**
- **PHP форматиране:** `vendor/bin/pint` след Blade/PHP промени.
- **Email** `mail/flow-run-report.blade.php` — изключение (inline CSS, table layout); стилизирай ръчно.

---

## 2. Дизайн система — ✅ ЗАКОТВЕНА в `PRODUCT.md` + `DESIGN.md`

Източникът на истината за **намерението** е `DESIGN.md`; за **токените** — `resources/css/app.css` (`@theme`). Без hardcoded hex в Blade — само utilities.

### 2.1 Характер
**Operational / control-room.** Пулт за управление: плътно и четимо, но спокойно (анти-enterprise). Принципи (от `PRODUCT.md`): пултът показва истината; плътно но спокойно; числата не подскачат (`tabular-nums`); статусът никога не е само цвят; движението е обратна връзка, не шоу.

### 2.2 Токени (виж `app.css`)
- **Шрифтове:** `font-display` + `font-sans` IBM Plex Sans · `font-mono` JetBrains Mono („control-room" технически избор, отличителен спрямо AI-default шрифтовете). `tabular-nums` за цени/QA/токени.
- **Неутрали (zinc):** `ink #18181b` / `muted #52525b` / `subtle #a1a1aa`; surfaces `#fff`/`#fafafa`; `line #e4e4e7` / `line-strong #d4d4d8`.
- **Акцент — instrument azure:** `primary #0369a1` (fills/бутони/текст-на-бяло, безопасен ≥4.5:1 двупосочно), `primary-hover #075985`, `accent #0ea5e9` (САМО декоративни сигнали: focus glow, running/active, graph линии).
- **Semantic:** `success #16a34a`, `warning #d97706`, `danger #dc2626`, `info #0284c7`. Статус mapping (`<x-badge>`): pending→subtle, running→accent (pulse), success→success, failed→danger, cancelled→muted; **иконка+текст, не само цвят**.
- **Сенки:** `shadow-card` / `shadow-popover`. **Радиуси:** карти 12–16px, без over-rounding (32px+).
- **Motion (Emil):** `--ease-out/in-out/drawer` криви + `--duration-press/quick/base/slow`; UI < 320ms; само `transform`/`opacity`; `prefers-reduced-motion` глобален блок.

### 2.3 Anti-patterns (refuse-and-rewrite)
Генеричен Bootstrap admin, enterprise претрупване, indigo→лилав градиент, hero-metric шаблон, идентични card-grid-ове, eyebrow/`01·02·03` над всяка секция, gradient text, side-stripe borders, glassmorphism по подразбиране, nested cards, ghost-card (1px border + мека ≥16px сянка), цвят като единствен статус-сигнал.

---

## 3. Фаза 0 — Foundation ✅ ГОТОВО
Tailwind play CDN премахнат от двата layout-а + `admin/login`; `@vite` + Google Fonts; tokens в `app.css`; inline Tom Select/`x-cloak`/`line-clamp` преместени (token-driven); emoji → SVG. `welcome.blade.php` НЕ е пипан (Фаза 3).

## 4. Фаза 1 — Дизайн система ✅ ГОТОВО
`PRODUCT.md` + `DESIGN.md` написани (impeccable init артефакти; register=product). `app.css`: azure акцент (#0369a1 + accent #0ea5e9, замени indigo #4f46e5), Emil motion токени, reduced-motion блок. `impeccable context` ги чете; `npm run build` чист.

---

## 5. Фаза 2 — Общи Blade компоненти (СЛЕДВАЩА)

Преди отделните страници: изгради преизползваеми компоненти в `resources/views/components/` и замени повтарящия се markup (трий стария). Ползвай `impeccable craft`/`shape`; verify с `detect` срещу URL.

- **Икони:** `composer require blade-ui-kit/blade-heroicons`; `<x-icon>` обвива Heroicons (outline), един set, консистентен щрих; icon-only бутони с `aria-label`.
- **Навигация** (`layouts/app.blade.php` + admin): sticky top-bar, modern; активен таб (`aria-current="page"`), hover, `:focus-visible` ринг; мобилно hamburger+drawer (Alpine). Keyboard-first (Linear).
- **`<x-button>`** primary/secondary/ghost/danger; sm/md; loading (spinner + запазен етикет, disabled докато тръгне заявката).
- **`<x-card>`**, **`<x-badge>`** (пренапиши `partials/status-badge.blade.php` — semantic + иконка).
- **Форми:** `<x-input>/<x-textarea>/<x-select>` (обвий Tom Select)/`<x-field>` (видим label, helper, inline грешка под полето, `required`, правилни `type`/`inputmode`/`autocomplete`); при submit с грешки → фокус на първото невалидно.
- **`<x-modal>`** единен: scrim 40–60%, focus trap, `Esc`, връщане на фокус, `overscroll-behavior:contain`, scale+fade от тригер.
- **`<x-table>`** sticky header, zebra, `tabular-nums`, `aria-sort`, празно състояние (Stripe).
- **`<x-segmented>`** (model-cost ниво — `flows/create` + builder toolbar), **`<x-tabs>`** (`companies/show`), **`<x-breadcrumb>`** (eval drill-down).
- **`<x-empty-state>`** (иконка+съобщение+CTA), skeleton/shimmer (>300ms).
- **Toast/flash:** запази Alpine auto-dismiss; semantic токени + `aria-live="polite"`; не краде фокус.

## 6. Фаза 3 — Редизайн страница по страница

Прилагай Фаза 2 компонентите. По групи; след всяка → `preview_screenshot` + `npx impeccable detect <URL>`.

- **A. Вход/лендинг/layouts:** `welcome.blade.php` (default scaffold → истински FlowAI лендинг; **махни всички `dark:` + hardcoded hex** `#706f6c`/`#1b1b18`/`#f53003`); `admin/login.blade.php`; layouts (навигация Фаза 2, `max-w-7xl`). *Auth: само `admin/login`, няма потребителски login/register.*
- **B. Companies:** `index` (карти/таблица, празно състояние, CTA), `create`/`edit` (форм компоненти), `show` (табове: flows/knowledge/connectors), `connectors` (статус badge-ове), `knowledge` (ресурси, ingest, „Тествай знанията" чат), `agent-templates/*`.
- **C. Flows:** `create`/`edit` (free-text + `<x-segmented>` ниво), `show` (преглед + история run-ове), `plan-ab` (рамо до рамо), `eval/*` (dashboard, QA скорове, drill-down + breadcrumb), `partials/{assistant-panel,dag-preview,memory-panel,phase-picker}` (единен панелен език).
- **E. Runs:** `runs/show` — operational екран (Grafana зона): waves/нодове, live статуси, лог панел (mono, четим), цени per node, retry индикатори; приоритет четимост+плътност.
- **F. Models/Admin:** `models/index`, `admin/agent-templates/*`, `admin/costs/index` (карти+таблица/прост чарт, достъпни цветове, легенда, празно).
- **G. Partials:** `status-badge`→`<x-badge>`; `agent-type-select`/`token-helper` уеднакви.
- **H. Email** — `mail/flow-run-report.blade.php` отделно (inline CSS).

## 7. Фаза 4 — Builder (Drawflow), внимателно
Най-сложният екран (`builder.blade.php` ~4924 реда, Drawflow CSS/JS от jsDelivr CDN). Restyle само визуалния пласт.
- Toolbar: `<x-segmented>` за нивото + preview на цена; бутони Фаза 2.
- Нод-карти: нова визия (заглавие, тип иконка, provider/модел чип mono, tools), състояния (selected/running/error) — **без** промяна на `id`/`data-*`.
- Popups → `<x-modal>`. Странични панели: единен език (header, скрол с `overscroll-behavior:contain`, празни/loading).
- Live run: прогрес наратив, per-node статуси, лог — `aria-live`.
- **Drawflow CSS override** в **отделен Vite-managed partial**, зареден **след** CDN link-а (коректен cascade); извън огромния inline `<style>`. Не пипай Drawflow JS.
- Тествай след всяка промяна: генериране → ревю → запис → презареждане → run; `detect` срещу живия builder URL.

## 8. Фаза 5 — QA / одит
- **`impeccable audit`** (a11y/perf/responsive) + **`impeccable critique`** по сменените страници; `npx impeccable detect <URL>` = 0 находки.
- **Emil motion review** на анимациите (Before/After таблица).
- **Taste `redesign-existing-projects`** втори минаване (layout/spacing/йерархия).
- (По избор) Vercel web-interface-guidelines като a11y cross-check.
- Достъпност: контраст ≥4.5:1, цвят не е единствен сигнал, `h1→h6`, „Skip to content", keyboard nav. Responsive 375px/laptop/ultra-wide; без хоризонтален скрол; `min-w-0` на flex деца.

---

## 9. Финален verification checklist
- [ ] Всичко през Vite build (`npm run build` чист) в двата layout-а; без Tailwind CDN.
- [ ] Един SVG icon set (Heroicons), консистентен щрих; без emoji-иконки.
- [ ] Всички цветове/шрифтове от tokens — без hardcoded hex в Blade.
- [ ] Само светла тема; контраст ≥4.5:1; статус = иконка+текст, не само цвят.
- [ ] Всяка страница от раздел 6 редизайнирана.
- [ ] Всички popups през `<x-modal>` (scrim/focus trap/Esc/анимация).
- [ ] Форми: видими labels, inline грешки, фокус на първата грешка, loading бутони.
- [ ] Drawflow: генериране → запис → презареждане → run без регресия.
- [ ] Празни/loading състояния навсякъде с динамични данни.
- [ ] `npx impeccable detect` = 0 находки по сменените URL-и; `prefers-reduced-motion` уважен.
- [ ] `vendor/bin/pint` минат; UI на български; без тестове; без legacy fallback.

## 10. Как да задвижиш Claude Code (готови prompt-ове)
Пускай по един на фаза; между фазите визуална проверка + `detect`.
1. **Foundation / Дизайн система:** ✅ готови.
2. **Компоненти:** `Изпълни Фаза 2 — изгради Blade компонентите от плана (impeccable craft) в resources/views/components, добави blade-heroicons + <x-icon>, и замени повтарящия се markup (трий стария).`
3. **Страници:** `Изпълни Фаза 3, група B (Companies), използвайки компонентите от Фаза 2. След това preview screenshot + npx impeccable detect срещу URL-ите.` (после C, E, F, G, A — една по една)
4. **Builder:** `Изпълни Фаза 4 (Drawflow). Спазвай guardrails — не променяй data модела/id/data-*. Drawflow override в отделен Vite partial след CDN link-а. После опиши как ръчно да тествам генериране→запис→run.`
5. **QA:** `Изпълни Фаза 5 — impeccable audit + critique + Emil motion review + Taste одит върху resources/views; detect срещу всички сменени URL-и; мини checklist раздел 9.`
