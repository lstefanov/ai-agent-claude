# Старт за Claude Code — AI Организация (автономно изпълнение)

> Този файл е „договорът" как Claude Code да изпълни плана: **сам, фаза по фаза, със
> самопроверка след всяка фаза, без да спира да пита** — докато целият план е завършен.

---

## 0. Мисия

Изгради новата функционалност **„AI Организация"** (Управител → Директори → Асистенти-персонажи
→ задачи = flows, с RPG/skill-tree слой и кредитен билинг) **над** съществуващото FlowAI ядро.
Следвай каноничния план **точка по точка**, проверявай след всяка точка, поправяй счупеното и
**продължавай без да чакаш потвърждение**.

---

## 1. Прочети първо (в този ред)

1. `docs/AI-ORGANIZATION-IMPLEMENTATION-PLAN.md` — **планът** (каноничен; изпълняваш точно него, фаза по фаза).
2. `docs/AI-ORGANIZATION-VISION.md` — концепцията + 10-те заключени решения (защо).
3. `CLAUDE.md` — **правилата** (задължителни, виж §2).
4. `DESIGN.md` + `resources/css/app.css` — дизайн система и токени (RPG слой).
5. `PRODUCT.md` — брандови стълбове.
6. `docs/AI-ORGANIZATION-MOCKUP.html` — визуален референс за RPG екраните.
7. **Контекст при нужда:** `docs/CLIENT-PORTAL-PLAN.md` (образец + клиентският портал, който разширяваш), `docs/MCP-CONNECTORS.md` (`act`), `docs/DYNAMIC-AGENT-PLANNER.md` (`FlowPlannerService`), `docs/HORIZON-REDIS.md` (опашки).
8. **Реалния код за преизползване** (отваряй при нужда): `app/Services/{FlowPlannerService,AgentGeneratorService,GraphFlowExecutor,NodeExecutorService,AgentGenerationLauncher,ComfyUIService,FlowMemoryService,ModelRouterService,KnowledgeService}`, `app/Support/{ModelLevel,LlmUsage,LlmRequestRecorder}`, `app/Http/Middleware/ClientAuth.php`, `app/Console/Commands/GenerateAgentsCommand.php`, `config/horizon.php`, `routes/client.php`.

---

## 2. Твърди правила (от `CLAUDE.md` — не ги нарушавай)

- **БЕЗ тестове.** Не пиши и не пускай тестове (`php artisan test`, `phpunit`, `composer test`). Проверката е ръчна/браузър/artisan — виж §4а.
- **БЕЗ legacy/back-compat.** Заменяш ли логика — трий старата пътека. БД се ресетва с `php artisan migrate:fresh --seed` (без миграция на стари данни).
- **LLM само през services** (`GeneratorService`/`AgentLoop`/`OpenAiChatService`/`OllamaService`), никога директно от контролери/агенти.
- **Планерът ПРЕДЛАГА, кодът ГАРАНТИРА** — валидирай/нормализирай всеки LLM изход (за org-плана и персоните двойно).
- **Дизайн:** само дизайн-токени + `x-*` компоненти, **никакъв hardcoded hex** в Blade; светла тема; WCAG AA; reduced-motion; `tabular-nums`. Quality bar = **frontend-design** skill (активирай го). **ui-ux-pro-max** само като референция — не override-ва `DESIGN.md`/`PRODUCT.md`.
- `vendor/bin/pint` след всяка фаза. Български коментари, английски идентификатори.

---

## 3. Среда (преди старт)

- **Redis** пуснат (`brew services start redis`).
- Пусни **`composer dev`** (server + **Horizon** + scheduler + vite едновременно). Задължително — системата е queue-базирана: генерация на flows, аватари, чат, runs минават през Horizon. Без Horizon нищо асинхронно не работи.
- По избор: Ollama (локални модели), ComfyUI (аватари), cloud API ключове (planning). Липсват ли — **деградирай меко**, не блокирай (виж §5). За MVP (Фази 0–3) **не** ти трябва Stripe.

---

## 4. Протокол на изпълнение (АВТОНОМНО — не спирай да питаш)

Работи по реда от **Приложение Б** на плана: **Фаза 0 → 0.5 → 1 → 2 → 3 → 4 → 5 → 6 → 7**.
За **ВСЯКА** фаза изпълни този цикъл:

1. Прочети фазата в плана + нейните **„Критерии за приемане"**.
2. Имплементирай я **изцяло**: миграции → модели → services (по сигнатурите) → routes → jobs → Blade views/компоненти. Спазвай §2.
3. `vendor/bin/pint` върху новия код.
4. **ПРОВЕРКА на функционалността** (без тестове — §4а). Поправи **всичко счупено**, преди да продължиш (§4б).
5. Отбележи прогреса в `docs/AI-ORG-PROGRESS.md` (✅ + 1 ред бележки/решения) и направи **git commit** („org Phase N: …").
6. **Продължи към следващата фаза БЕЗ да питаш „да продължа ли".**

**НЕ спирай и НЕ чакай потвърждение между фазите. Действай до завършване на целия план.**
Питай човек **САМО** ако: (а) трябва **тайна/credential**, която липсва и блокира конкретна стъпка → отбележи в PROGRESS, деградирай меко, продължи с останалото; или (б) действие е **необратимо/разрушително** извън `migrate:fresh` на dev БД. Във всеки друг случай вземай най-разумното решение, съвместимо с документите, и го **логвай** в PROGRESS (не спирай).

### 4а. Как се проверява (CLAUDE.md-съвместимо, БЕЗ тестове)

- `php artisan migrate:fresh --seed` минава без грешки; новите таблици/колони съществуват (`php artisan db:show`, `php artisan tinker`).
- `php artisan route:list` показва новите routes без грешка; `php artisan about` зарежда.
- **Boot:** server + Horizon вървят; `php artisan horizon:status` = running; tail на `storage/logs/laravel.log` (+ `run-{id}.log`) без необработени грешки.
- **Браузър:** изпълни ръчно „Критериите за приемане" на фазата (логин в клиентския портал → happy-path на новото). За queue стъпки — виж, че job-овете минават на `/horizon`.
- `vendor/bin/pint --test` = чисто (или остави pint да форматира).
- `npm run build` минава (Vite + Tailwind токени компилират; никакъв hardcoded hex).
- **Билинг (Фаза 0.5/6):** в `tinker` провери `reserve → settle` в `credit_ledger`; идемпотентност (повтори job → без двоен debit); wallet гейт (нулев баланс → пауза, не overspend).
- **Персони в runtime (Фаза 0.5):** пусни задача и виж в run лога, че **персона блокът** влиза в node prompt-а, а **QA възлите са persona-неутрални**.
- **Аватари:** с пуснат ComfyUI → портрет на стабилен път; без него → fallback инициали + по-късен retry (не чупи).

### 4б. Когато нещо е счупено

Поправи го **веднага** в текущата фаза (не натрупвай дълг). Ако корекцията засяга по-ранна фаза — поправи там и пусни проверката на двете. Чак след **зелена** проверка минаваш нататък.

---

## 5. Решения и блокери — действай, не спирай

- **Липсваща услуга/ключ** (OpenAI/Brave/ComfyUI/Stripe): кодът трябва да **деградира меко** (вече е патърн — напр. ComfyUI offline → fallback инициали; `AgentGenerationLauncher` token-poll). Имплементирай fallback-а, отбележи в PROGRESS, продължи.
- **Stripe** е Фаза 6 (drop-in зад `PaymentProvider`); засега `AdminSimulatedPaymentProvider`. MVP (0–3) не зависи от Stripe.
- **Дребна неяснота:** избери най-разумното, съвместимо с VISION/PLAN/DESIGN; запиши решението (1 ред) в PROGRESS.
- **Голяма неяснота, непокрита от документите:** отбележи в PROGRESS като „⚠ решение за човек", вземи временно разумно допускане и **продължи** (не блокирай целия план).

---

## 6. Ред, MVP и „готово"

- Следвай **Приложение Б** (ред + зависимости). Билингът (**0.5 foundation**) е предусловие за run/действия; **Stripe (6)** е по-късно.
- **MVP-демо = Фази 0–3:** наемаш Управител → проучен → интервюиран → екип с персони + skill tree → одобрен → една задача дава резултат в браузъра.
- **„Готово" = целият план (0–7)** имплементиран, всяка фаза **зелена** по критериите си, `pint` чисто, `npm run build` минава, `docs/AI-ORG-PROGRESS.md` попълнен, MVP happy-path работи end-to-end в браузъра.

---

## 7. Кратко съобщение за поставяне (paste в Claude Code)

```
Прочети в този ред: docs/AI-ORGANIZATION-IMPLEMENTATION-PLAN.md, docs/AI-ORGANIZATION-VISION.md,
CLAUDE.md, DESIGN.md + resources/css/app.css, PRODUCT.md, docs/AI-ORGANIZATION-MOCKUP.html и
docs/CLAUDE-CODE-KICKOFF.md. Контекст при нужда: docs/CLIENT-PORTAL-PLAN.md, docs/MCP-CONNECTORS.md,
docs/DYNAMIC-AGENT-PLANNER.md, docs/HORIZON-REDIS.md.

Изпълни целия план docs/AI-ORGANIZATION-IMPLEMENTATION-PLAN.md ФАЗА ПО ФАЗА (0 → 0.5 → 1 … → 7),
АВТОНОМНО, по протокола в docs/CLAUDE-CODE-KICKOFF.md. За всяка фаза: имплементирай изцяло →
vendor/bin/pint → провери функционалността по „Критериите за приемане" БЕЗ да пишеш тестове
(CLAUDE.md забранява тестове; ползвай migrate:fresh --seed, route:list, Horizon, браузър, логове,
npm run build) → поправи счупеното → отбележи в docs/AI-ORG-PROGRESS.md → git commit → продължи.

НЕ спирай да питаш „да продължа ли". Действай до завършване на целия план. Питай само при липсваща
тайна/credential или необратимо действие; иначе вземай най-разумното решение, съвместимо с
документите, и го логвай в PROGRESS. Спазвай CLAUDE.md (без тестове, без legacy, само services,
pint), DESIGN.md/токените (никакъв hardcoded hex) и активирай frontend-design skill за UI.

Преди старт увери се, че Redis върви и че е пуснат `composer dev` (за Horizon). Започни сега от Фаза 0.
```
