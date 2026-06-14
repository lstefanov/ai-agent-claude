# FlowAI — UI/UX Redesign Plan (за Claude Code)

> Брийф за пълен редизайн на UI/UX на **всички** страници, popups и навигация.
> Стек: **Laravel 12 + Blade + Tailwind v4 (Vite) + Alpine.js + Drawflow**. Без SPA, без React.
> Посока: **Modern SaaS (Linear / Vercel)** · Тема: **само светла** · Език на UI: **български** (не се променя).

> **СТАТУС (2026-06-14):** ✅ **Фаза 0 е изпълнена и проверена** (CDN→Vite, design tokens, шрифтове, SVG лого; `app.css` компилира чисто). Започни директно от **Фаза 1**.

Как да го изпълниш: чети този файл фаза по фаза. За всяка фаза има готов prompt в края (раздел „Как да задвижиш Claude Code"). Не минавай към следваща фаза, преди текущата да е визуално проверена.

---

## 0. Препоръчани skills и инсталация

Инсталирай и трите преди да започнеш. Те са framework-agnostic и работят с Blade/HTML/Tailwind.

| Skill | Роля | Инсталация |
|---|---|---|
| **Anthropic `frontend-design`** (официален) | Естетическа посока, anti-„AI slop", типография/цвят/композиция | През Claude plugin marketplace: `/plugin marketplace` → `frontend-design`. Активира се автоматично при frontend задачи. |
| **Vercel `web-interface-guidelines`** (официален) | Одит/QA: accessibility, форми, анимации, производителност (MUST/SHOULD/NEVER) | `npx skills add https://github.com/vercel-labs/agent-skills --skill "web-design-guidelines"` |
| **Taste `redesign-existing-projects`** (Leonxlnx, MIT) | Одит на съществуващ код → план за поправка на layout/spacing/йерархия | `npx skills add https://github.com/Leonxlnx/taste-skill --skill "redesign-existing-projects"` |

Регулатори на Taste (сложи ги в началото на skill файла или в prompt-а) за Modern SaaS:
`DESIGN_VARIANCE = 4` (чисто, леко асиметрично), `MOTION_INTENSITY = 3` (фини hover/преходи), `VISUAL_DENSITY = 6` (информативни dashboards, но без претрупване).

---

## 1. Guardrails — какво да НЕ се чупи

Това са твърди ограничения. Нарушаването им чупи функционалност.

- **Drawflow графът е свещен.** В `flows/builder.blade.php` не променяй data-модела на нодовете, техните `id`/`data-*` атрибути, нито структурата, която Drawflow експортира. `GraphNormalizer` е ЕДИНСТВЕНОТО място, което разбира експорт формата (виж CLAUDE.md) — restyle-вай само „хромето" (toolbar, панели, popups, визия на нод-картите), не самия engine. След промяна тествай ръчно: генериране → запис → презареждане → run.
- **Без тестове.** Не пиши и не пускай тестове (правило от CLAUDE.md).
- **Без legacy/back-compat код.** Когато заменяш стар стил/markup — трий стария път, не оставяй fallback (правило от CLAUDE.md). Собственикът ресетва базата, не мигрира.
- **UI текстовете остават на български.** Не превеждай етикети/съобщения.
- **Backend логика не се пипа.** Само Blade, CSS, минимален Alpine. Контролери, services, маршрути — без промени (освен ако стилизирането не изисква нов Blade partial/component).
- **PHP форматиране:** `vendor/bin/pint` след промени по Blade/PHP.
- **Email шаблонът** `mail/flow-run-report.blade.php` е изключение — имейлите изискват inline стилове и table layout; tokens/utility класове там не важат. Стилизирай го отделно и ръчно.

---

## 2. Дизайн система (отправни tokens)

Това е стартовата точка; `frontend-design` ще я финализира. Целта е **един източник на истината** — без hardcode-нати hex стойности по страниците.

### 2.1 Типография — ФИНАЛНО (имплементирано във Фаза 0)
- **Заглавия (display):** `Geist` → utility `font-display`. (Избягваме Inter/Roboto/Arial/Space Grotesk — забранени от frontend-design.)
- **Основен текст (body):** `Instrument Sans` (по подразбиране през `--font-sans`).
- **Моноширинен (код, имена на модели, токени, цени):** `Geist Mono` → utility `font-mono`.
- **Числа:** ползвай `tabular-nums` за цени, QA скорове и таблици — без „подскачане" на колоните.
- Шрифтовете се зареждат през Google Fonts `<link>` с `display=swap` (+ `preconnect`) в трите layout-а.

### 2.2 Цветове (светла тема, semantic tokens)
Дефинирай като CSS променливи (не raw hex в компонентите). Неутрална zinc скала + един уверен акцент. **Избягвай клишето „лилав градиент върху бяло"** (frontend-design го маркира) — ползвай плътен акцент, не pink→purple градиенти.

```
/* имплементирано в resources/css/app.css → ползвай utilities, НЕ raw hex */
--color-canvas:         #ffffff;  /* bg-canvas          — фон */
--color-surface:        #ffffff;  /* bg-surface         — карти/панели/модали */
--color-surface-subtle: #fafafa;  /* bg-surface-subtle  — секции/zebra */
--color-line:           #e4e4e7;  /* border-line        — линии/рамки */
--color-line-strong:    #d4d4d8;  /* border-line-strong — hover/focus */
--color-ink:            #18181b;  /* text-ink           — основен текст */
--color-muted:          #52525b;  /* text-muted         — вторичен */
--color-subtle:         #a1a1aa;  /* text-subtle        — placeholder/мета */
--color-primary:        #4f46e5;  /* bg-primary/text-primary (refine с frontend-design) */
--color-primary-hover:  #4338ca;  /* bg-primary-hover */
--color-primary-fg:     #ffffff;  /* text-primary-fg    — текст върху primary */
--color-success:        #16a34a;  /* bg/text-success */
--color-warning:        #d97706;  /* bg/text-warning */
--color-danger:         #dc2626;  /* bg/text-danger  */
--color-info:           #2563eb;  /* bg/text-info    */
```
Статуси за `runs`/`node_runs` (важно за `partials/status-badge.blade.php`): mapни `pending`→subtle, `running`→info (с pulse), `success`→success, `failed`→danger, `cancelled`→muted. Цветът никога не е единственият сигнал — добавяй иконка/текст (Vercel правило).

### 2.3 Разстояния, радиуси, сенки, движение
- **Spacing:** 4/8px скала (Tailwind default). Вертикален ритъм: 16 / 24 / 32 / 48.
- **Радиуси:** ползвай вградените на Tailwind v4 (`rounded-md`=.375rem, `rounded-lg`=.5rem, `rounded-xl`=.75rem, `rounded-2xl`=1rem). НЕ ги override-ваме, за да няма регресии. Вложени радиуси: дете ≤ родител.
- **Сенки (наслоени — ambient + direct, Vercel правило)** — добавени като отделни tokens, за да не пипат default-ите:
  - `shadow-card` = `0 1px 2px rgba(0,0,0,.04), 0 4px 12px rgba(0,0,0,.06)` — карти/панели
  - `shadow-popover` = `0 4px 16px rgba(0,0,0,.08), 0 12px 32px rgba(0,0,0,.10)` — модали/dropdowns
  - Остри ръбове: полупрозрачна рамка + сянка едновременно.
- **Движение:** 150–250ms; ease-out за вход, ease-in за изход; анимирай само `transform`/`opacity` (никога `width/height/top/left`, никога `transition: all`); уважавай `prefers-reduced-motion`.

### 2.4 Tailwind v4 `@theme` — ✅ ГОТОВО
Tokens-ите вече са в `resources/css/app.css` (Фаза 0) и Tailwind v4 ги излага като utilities:
`bg-canvas`, `bg-surface`, `bg-surface-subtle`, `text-ink`, `text-muted`, `text-subtle`, `border-line`, `border-line-strong`, `bg-primary`, `bg-primary-hover`, `text-primary-fg`, `bg/text-{success,warning,danger,info}`, `font-display`, `font-mono`, `shadow-card`, `shadow-popover`.
Във Фаза 2+ ползвай ТЕЗИ utilities вместо `indigo-*` / `gray-*` / raw hex.

---

## 3. Фаза 0 — Foundation ✅ ГОТОВО (2026-06-14)

Без това редизайнът ще е непоследователен.

> **Изпълнено:** Tailwind play CDN е премахнат от `layouts/app.blade.php`, `admin/layouts/admin.blade.php` и `admin/login.blade.php`; добавен `@vite([...])` + Google Fonts (Geist / Instrument Sans / Geist Mono); design tokens добавени в `resources/css/app.css`; дублираните inline Tom Select + `x-cloak`/`line-clamp` стилове преместени в `app.css` (token-driven); emoji логата (`⚡`, `⚙`, `✓`) заменени със SVG. `app.css` компилира чисто (проверено изолирано). Билдни локално с `npm run dev` / `npm run build`.
> `welcome.blade.php` НЕ е пипан (вече ползва Vite; пренаписва се във Фаза 3).

1. **Премахни Tailwind CDN.** В `layouts/app.blade.php` ред 7 има `<script src="https://cdn.tailwindcss.com"></script>` — това е v3 play CDN и конфликтва с реалния Tailwind v4 + Vite build на проекта. Махни го и сложи:
   ```blade
   @vite(['resources/css/app.css', 'resources/js/app.js'])
   ```
   Провери, че `resources/js/app.js` съществува (ако не — създай го). Глобовете `@source` в `app.css` вече покриват `**/*.blade.php`, така че съществуващите utility класове ще продължат да работят.
2. **Същото за admin layout-а** `admin/layouts/admin.blade.php` — провери дали и той ползва CDN и го уеднакви.
3. **Alpine.js и Tom Select** могат да останат през CDN (те са JS, не Tailwind) — или ги премести на npm. Минималната безопасна промяна: махни само Tailwind CDN.
4. **Премахни emoji иконите.** Логото `⚡` (ред 61) и всякакви emoji-та като иконки → SVG (Lucide / Heroicons). Един icon set за целия проект, консистентна дебелина на щриха.
5. **Зареди шрифтовете** (раздел 2.1) и сложи `@theme` tokens (раздел 2.4).
6. **Премести inline `<style>` блока** от layout-а в `app.css` (Tom Select override стиловете → пренапиши с новите tokens вместо hardcode-нати hex).

Резултат от Фаза 0: всички страници рендерят с новата дизайн-система, без визуални регресии.

---

## 4. Фаза 1 — Генерирай и закотви дизайн-системата

1. Стартирай `frontend-design` с контекст: „B2B SaaS за изграждане на multi-agent AI workflows; бизнеси се регистрират и създават flows; посока Modern SaaS (Linear/Vercel), светла тема, отличителна но професионална." Нека предложи финални шрифтове, акцентен цвят и „характер" (1 запомнящо се нещо).
2. (По избор) Пусни `ui-ux-pro-max --design-system` еднократно за палитра/шрифтови идеи за сравнение.
3. Запиши финалните решения в `docs/DESIGN-SYSTEM.md` (или секция в `CLAUDE.md`): tokens, типография, компонентни правила, anti-patterns. Това става „source of truth" за следващите фази.

---

## 5. Фаза 2 — Общи Blade компоненти (преди отделните страници)

Изгради преизползваеми Blade компоненти (`resources/views/components/`), за да е редизайнът консистентен и DRY. Замени повтарящия се markup по страниците с тях (трий стария — без дублиране).

- **Навигация** (`layouts/app.blade.php`): запази sticky top-bar, но modern SaaS вид — SVG лого, активен таб с ясен indicator (`aria-current="page"`), hover състояния, фокус рингове (`:focus-visible`). Мобилно: hamburger + drawer (Alpine). Уеднакви и admin навигацията.
- **`<x-button>`** — варианти: `primary / secondary / ghost / danger`; размери `sm/md`; loading състояние (spinner + запазен етикет, бутонът остава disabled докато заявката тръгне — Vercel правило).
- **`<x-card>`** — surface + наслоена сянка + рамка; слотове за header/body/footer.
- **`<x-badge>`** — пренапиши `partials/status-badge.blade.php` със semantic статус токени + иконка (не само цвят).
- **Форми:** `<x-input>`, `<x-textarea>`, `<x-select>` (обвий Tom Select), `<x-field>` (видим `<label>`, helper текст, inline грешка под полето, `required` маркер, правилни `type`/`inputmode`/`autocomplete`). При submit с грешки — фокус на първото невалидно поле.
- **`<x-modal>`** — единен компонент за всички popups: scrim 40–60% черно, focus trap, `Esc` за затваряне, връщане на фокуса, `overscroll-behavior: contain`, анимация от тригера (scale+fade). Това ще обслужи builder popup-ите и confirm диалозите.
- **`<x-table>`** — sticky header, zebra редове, `tabular-nums` за числа, sortable индикатори с `aria-sort`, празно състояние.
- **Състояния:** `<x-empty-state>` (иконка + съобщение + основен CTA) и skeleton/shimmer за зареждане > 300ms.
- **Toast/flash:** запази Alpine auto-dismiss логиката от layout-а, но стилизирай със semantic токени и `aria-live="polite"`; не краде фокус.

---

## 6. Фаза 3 — Редизайн страница по страница (всичко по сайта)

Прилагай Фаза 2 компонентите. Работи по групи; след всяка група прави визуална проверка.

**A. Вход / лендинг / layouts**
- `welcome.blade.php` — в момента е **default Laravel scaffold** (Laravel лого, „Let's get started", Laracasts). Замени го ИЗЦЯЛО с истински FlowAI лендинг: hero с ясна стойностна реклама, един основен CTA, секции с „характер" (frontend-design). Вече ползва Vite.
- `admin/login.blade.php` — центрирана карта, чист форм, видими labels, focus states.
- `layouts/app.blade.php`, `admin/layouts/admin.blade.php` — навигация (Фаза 2), контейнер `max-w-7xl`, консистентни отстъпи.

**B. Companies (фирми)**
- `companies/index.blade.php` — списък като карти/таблица, празно състояние, „Нова фирма" CTA.
- `companies/create.blade.php`, `companies/edit.blade.php` — форм компоненти, групиране на полета.
- `companies/show.blade.php` — табове/секции (flows, knowledge, connectors), ясна йерархия.
- `companies/connectors.blade.php` — карти на конекторите със статус badge-ове.
- `companies/knowledge.blade.php` — knowledge base изглед (списък ресурси, ingest състояние, „Тествай знанията" чат), празни/loading състояния.
- `companies/agent-templates/{index,create,edit,_form}.blade.php` — таблица + форм.

**C. Flows**
- `flows/create.blade.php`, `flows/edit.blade.php` — описание на flow (free-text), модел-cost ниво селектор като сегментиран контрол.
- `flows/show.blade.php` — общ преглед на flow + история на run-овете (status badges, цени с `tabular-nums`).
- `flows/plan-ab.blade.php` — сравнение рамо до рамо (OpenAI vs Anthropic), таблица/карти.
- `flows/eval/{form,index,results,run-detail}.blade.php` — eval dashboard: таблици с резултати, QA скорове, drill-down с breadcrumb.
- `flows/partials/{assistant-panel,dag-preview,memory-panel,phase-picker}.blade.php` — панели в единен визуален език (виж Фаза 4).

**D. Builder (Drawflow) — виж Фаза 4 (отделно, внимателно).**

**E. Runs**
- `runs/show.blade.php` — изпълнение на run: waves/нодове, live статуси, лог панел (моноширинен, четим), цени per node, retry индикатори. Това е „operational" екран — приоритет на четимост и плътност.

**F. Models / Admin**
- `models/index.blade.php` — таблица с модели, pull/test действия, статуси.
- `admin/agent-templates/{index,_row,create,edit,_form}.blade.php` — admin CRUD.
- `admin/costs/index.blade.php` — разходи: карти с ключови числа + таблица/прост чарт (достъпни цветове, легенда, празно състояние).

**G. Споделени partials**
- `partials/status-badge.blade.php` → `<x-badge>`.
- `partials/agent-type-select.blade.php`, `partials/token-helper.blade.php` — уеднакви със select/форм компонентите.

**H. Email** — `mail/flow-run-report.blade.php` стилизирай отделно (inline CSS, table layout).

---

## 7. Фаза 4 — Builder (Drawflow), внимателно

Builder-ът е най-сложният екран и носи най-голям риск. Restyle само визуалния пласт.

- **Toolbar** (модел-cost ниво, relevel, запис): сегментиран контрол за нивото (low/medium/high/ultra/god) с preview на цената; бутони от Фаза 2.
- **Нод-карти:** нова визия (заглавие, тип иконка, provider/модел чип моноширинно, tools), ясни състояния (selected/running/error) — **без** да променяш `id`/`data-*`, които Drawflow/GraphNormalizer ползват.
- **Popups** (generation popup, relevel cost preview, per-node настройки): минете през `<x-modal>` или поне уеднаквете визуално (scrim, focus trap, Esc, анимация от тригер).
- **Странични панели** (assistant, memory, phase-picker, dag-preview): единен панелен език — header, скрол зона с `overscroll-behavior: contain`, празни/loading състояния.
- **Live run режим:** прогрес наратив, per-node статуси, лог — четимо, с `aria-live` за обновяванията.
- **Drawflow CSS:** override-вай неговите класове в отделен CSS блок със semantic токени; не пипай неговия JS.
- **Тествай ръчно след всяка промяна:** генериране на flow → ревю в графа → запис → презареждане → run. Ако някое от тези се счупи — върни последната промяна.

---

## 8. Фаза 5 — QA / одит (Vercel + Taste + достъпност)

Пусни одита върху променените файлове и поправи находките.

- **Vercel `web-interface-guidelines`:** „Review my UI against the guidelines" върху `resources/views/**`. Покрива: фокус рингове (`:focus-visible`), hit targets ≥24px (моб. ≥44px), `<input>` ≥16px на мобилно (без iOS zoom), Enter подава фокусирания инпут, грешки до полето + фокус на първата, `aria-live` за toasts, навигация с `<a>` (не `<div onClick>`), `prefers-reduced-motion`, само `transform/opacity` анимации, CLS (размери на изображения), контраст (APCA), `tabular-nums`, icon-only бутони с `aria-label`.
- **Taste `redesign-existing-projects`:** втори минаване за layout/spacing/йерархия/„характер".
- **Достъпност:** контраст ≥ 4.5:1 (основен текст), цветът не е единствен сигнал, заглавна йерархия `h1→h6`, „Skip to content" линк, keyboard nav в реда на визуалния.
- **Responsive:** тествай на 375px (малък телефон), лаптоп, ultra-wide (симулирай на 50% zoom). Без хоризонтален скрол; `min-w-0` на flex деца за truncation.

---

## 9. Финален verification checklist

- [ ] Tailwind CDN е премахнат; всичко рендерира през Vite build (`npm run build` минава чисто) в **двата** layout-а.
- [ ] Няма emoji като иконки; един SVG icon set, консистентен щрих.
- [ ] Всички цветове/шрифтове идват от tokens — няма hardcode-нат hex в Blade.
- [ ] Само светла тема, консистентна навсякъде; контраст проверен.
- [ ] Всяка страница от раздел 6 е редизайнирана (companies, flows, builder, runs, models, admin, eval, knowledge, auth, welcome).
- [ ] Всички popups минават през `<x-modal>` (scrim, focus trap, Esc, анимация).
- [ ] Формите: видими labels, inline грешки, focus на първата грешка, loading бутони.
- [ ] Drawflow: генериране → запис → презареждане → run работят без регресия.
- [ ] Празни и loading състояния навсякъде, където има динамични данни.
- [ ] Vercel guidelines одитът минава; `prefers-reduced-motion` се уважава.
- [ ] `vendor/bin/pint` минат; UI текстовете са на български; без нови тестове; без legacy fallback код.

---

## 10. Как да задвижиш Claude Code (готови prompt-ове)

Пускай по един на фаза, в реда отдолу. Между фазите преглеждай визуално.

1. **Foundation:** ✅ вече изпълнено (2026-06-14). Само за локална проверка: `npm run dev` (или `npm run build`) на твоята машина.
2. **Дизайн-система:**
   `Активирай frontend-design skill. Изпълни Фаза 1 от плана за посока Modern SaaS, светла тема. Запиши финалните tokens в docs/DESIGN-SYSTEM.md.`
3. **Общи компоненти:**
   `Изпълни Фаза 2 — изгради Blade компонентите от плана в resources/views/components и замени повтарящия се markup (трий стария, без дублиране).`
4. **Страници (по групи):**
   `Изпълни Фаза 3, група B (Companies) от плана, използвайки компонентите от Фаза 2.` (после група C, E, F, G... една по една)
5. **Builder:**
   `Изпълни Фаза 4 (Drawflow builder). Спазвай guardrails — не променяй data модела на нодовете. След промяна опиши как ръчно да тествам генериране→запис→run.`
6. **QA:**
   `Изпълни Фаза 5 — пусни Vercel web-interface-guidelines и Taste redesign одит върху resources/views и поправи находките. Накрая мини през checklist-а в раздел 9.`
