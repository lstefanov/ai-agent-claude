# Design

> Визуалната система на FlowAI. Източник на истината за токените е `resources/css/app.css` (`@theme`);
> този файл обяснява НАМЕРЕНИЕТО. Никакви hardcoded hex в Blade — само Tailwind utilities от токените.

## Theme / Mood

**Operational core + RPG character layer.** Светла, професионална тема в **два регистъра**:

- **Оперативни повърхности** (табла, таблици, run/поток, форми, билинг числа) остават **control-room**: неутрална zinc основа, един уверен azure акцент, плътно и четимо. Заемки: **Linear** (навигация, keyboard-first), **Stripe Dashboard** (таблици/форми/числа), **Grafana/Datadog** (САМО monitoring зоните).
- **Характерни повърхности** (Екип/Roster, Skill Tree, Карта на героя, чат с членове) носят **RPG идентичност**: портрети, character-цветове по роля, звезди/кредити, прогрес. Заемки: **Football Manager / Crusader Kings / The Sims** — характер и прогрес, но премиум и четимо, НЕ неоново/детинско.

RPG-то е „кожа" върху сериозен пулт: числата и статусите остават безмилостно ясни (tabular, иконка+текст+цвят, WCAG AA). Виж и `PRODUCT.md` (брандови стълбове).

## Color Palette (light only)

Токени (виж `app.css`):
- **Surfaces:** `canvas`/`surface` `#ffffff`, `surface-subtle` `#fafafa`, `line` `#e4e4e7`, `line-strong` `#d4d4d8`.
- **Text (zinc ramp):** `ink` `#18181b`, `muted` `#52525b`, `subtle` `#a1a1aa`.
- **Accent — instrument azure:** `primary` `#0369a1` (fills, бутони, текст-на-бяло — безопасен ≥4.5:1 в двете посоки), `primary-hover` `#075985`, `primary-fg` `#ffffff`. `accent` `#0ea5e9` = вивид azure САМО за декоративни сигнали (focus glow, running/active, graph линии) — никога за дребен текст на бяло.
- **Semantic статуси:** `success` `#16a34a`, `warning` `#d97706`, `danger` `#dc2626`, `info` `#0284c7`.
- **Character / категориен слой (RPG — за персони/отдели/skill-tree):** набор отделни тонове, всеки = **идентичност на роля/директор**, не декорация. Токени `char-{purple|teal|coral|blue|amber|pink|green}`, всеки с три стопа: `-soft` (≈50 фон), база (рингове/стат-барове), `-strong` (≈800 текст, ≥4.5:1 на soft). Покриват аватар-рингове, стат-барове, клонове на дървото, тагове на персона.
- **RPG акценти:** `star` = gold `#c8881a` за звезди/ниво; **кредитите** ползват azure метъра (системен ресурс, не нов цвят).

Стратегия (еволюирана): **оперативните повърхности остават restrained** (azure ≤ ~10% от повърхността); **характерните повърхности ползват категорийния палет като идентичност** (плътни fill-ове, по един доминиращ тон на карта). Все още **без градиенти по подразбиране и без glassmorphism**; топлината идва от портрети и характер, не от наситен фон.

Статус mapping (`<x-badge>` / `status-badge`): `pending`→subtle, `running`→accent (pulse), `success`→success, `failed`→danger, `cancelled`→muted. **Винаги иконка+текст, не само цвят.**

## Typography

- **Display (заглавия):** `font-display` = IBM Plex Sans (тегла 600/700). `text-wrap: balance` на h1–h3.
- **Body:** `font-sans` = IBM Plex Sans (по подразбиране, 400/500). Дължина на ред 65–75ch за проза.
- **Mono (код, имена на модели, провайдъри, токени, цени):** `font-mono` = JetBrains Mono — носи „control-room" контраста и втората фамилия (impeccable: едно sans-семейство в няколко тегла + mono).
- **Числа:** `tabular-nums` за цени, QA скорове, токени, таблици — колоните не подскачат.
- Display letter-spacing floor ≥ -0.04em (без слепени букви). Hero clamp max ≤ 6rem.
- Сдвояване по контраст-ос (sans + mono), не два подобни sans-а.

## Spacing, Radius, Shadow

- **Spacing:** 4/8px скала. Вертикален ритъм 16/24/32/48. Варирай за ритъм, не монотонен grid.
- **Radius (Tailwind v4 вградени, без override):** карти 12–16px (`rounded-xl`/`2xl` максимум за карти), inputs `rounded-lg`, pill само за tags/бутони. **Без over-rounding (32px+).** Вложен радиус: дете ≤ родител.
- **Shadow (наслоени):** `shadow-card` за карти/панели, `shadow-popover` за модали/dropdowns. **Без ghost-card** (1px border + мека ≥16px сянка едновременно) — избери едното.

## Motion (Emil Kowalski craft)

- Криви (в `app.css`): `--ease-out: cubic-bezier(0.23,1,0.32,1)` (вход/изход, отзивчиво), `--ease-in-out` (движение по екрана), `--ease-drawer` (drawer/панели). Без bounce/elastic.
- Durations: button press 100–160ms, dropdown/select 150–250ms, modal/drawer 200–320ms. **UI < 320ms.**
- Анимирай само `transform`/`opacity` (+ blur/shadow когато подобрява); никога `width/height/top/left`, никога `transition: all`.
- Решение „да анимира ли": често виждано (100+/ден, keyboard действия) → не анимира; рядко (modal/toast/onboarding) → стандартно/delight.
- `prefers-reduced-motion: reduce` → near-instant (глобален блок в `app.css`).

## Iconography

Един set — **Heroicons (outline)** през `blade-ui-kit/blade-heroicons`, обвити в `<x-icon>` за консистентен размер/щрих. Без emoji като иконки. icon-only бутони задължително с `aria-label`.

## Components (Phase 2 — единен език)

`<x-button>` (primary/secondary/ghost/danger; sm/md; loading), `<x-card>`, `<x-badge>` (semantic+иконка), форми (`<x-input>/<x-textarea>/<x-select>/<x-field>` с видим label, inline грешка, focus на първата грешка), `<x-modal>` (scrim, focus trap, Esc, scale+fade от тригер), `<x-table>` (sticky header, zebra, tabular-nums, `aria-sort`, празно състояние), `<x-segmented>` (model-cost ниво), `<x-tabs>`, `<x-breadcrumb>`, `<x-empty-state>`, skeleton (>300ms), toast (`aria-live`, не краде фокус).

## Components — RPG слой (Phase 2)

Към единния език се добавят: `<x-avatar>` (портрет с категориен ринг + **fallback инициали** при `pending`/`failed`), `<x-stars>` (5-слот ★ gold = `ModelLevel`; индикатор наследено vs override), `<x-stat-bar>` (черта 0–100, категориен fill), `<x-character-card>` (roster: аватар, име/роля/възраст, звезди, мини статове, статус, „кой кого управлява"), `<x-skill-node>` (active/premium/locked състояния), `<x-credit-meter>` (баланс/прогрес, azure), `<x-quest>` (препоръка от Управителя), `<x-decision-item>` (чакащо одобрение). Всички спазват иконка+текст+цвят, видим focus и reduced-motion.

## Layout

Контейнер `max-w-7xl`, консистентни отстъпи. Flexbox за 1D, Grid за 2D; адаптивни grid-ове `repeat(auto-fit, minmax(...,1fr))` без излишни breakpoints. Семантична z-index скала (dropdown → sticky → modal-backdrop → modal → toast → tooltip), без 999/9999. `min-w-0` на flex деца за truncation; без хоризонтален скрол на 375px.

## Anti-patterns (refuse-and-rewrite)

Генеричен Bootstrap admin look, enterprise претрупване, indigo→лилав градиент, hero-metric шаблон, идентични card-grid-ове, eyebrow/`01·02·03` над всяка секция, gradient text, side-stripe borders, glassmorphism по подразбиране, nested cards, цвят като единствен статус-сигнал. **Плюс (RPG):** неоново/тъмно gamer-edgelord, детинско геймифициране (+XP попъпи, аркаден шрифт, конфети), pay-to-win стена, числа принесени в жертва на украса.
