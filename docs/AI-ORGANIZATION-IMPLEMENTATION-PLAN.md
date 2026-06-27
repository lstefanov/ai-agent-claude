# AI Организация — План за имплементация (за Claude Code)

> Концепция: [`AI-ORGANIZATION-VISION.md`](./AI-ORGANIZATION-VISION.md). Този документ е
> **планът за действие** — фази, файлове, routes, миграции, сигнатури и критерии за
> приемане. Следва формата на [`CLIENT-PORTAL-PLAN.md`](./CLIENT-PORTAL-PLAN.md) и
> [`MCP-CONNECTORS.md`](./MCP-CONNECTORS.md).
>
> **Какво строим:** над съществуващото ядро „агент, който създава агенти"
> (`FlowPlannerService`) добавяме **„агент, който създава организацията"** —
> **Управителя** (`OrgPlannerService`): проучва бизнеса, интервюира собственика и
> проектира **организация от Директори → Асистенти → Задачи**, където всеки член е
> **персонаж с био + RPG черти + runtime knobs**, всяка задача е **Flow** от старата
> система, а монетизацията е **кредити-мощност с реален кредитен билинг foundation от
> старта** (wallet/ledger/метеринг/резервация) — зареждането сега е **админ-симулирано**,
> а **Stripe** е по-късен drop-in зад `PaymentProvider`. Управителят **предлага**, човекът
> **одобрява** на всяко ниво.

## Правила (от `CLAUDE.md` — спазвай ги стриктно)

- **Без тестове.** Не пиши и не пускай тестове (`php artisan test`, `phpunit`, `composer test`).
- **Без legacy/back-compat.** Заменяш ли логика — трий старата пътека (собственикът reset-ва БД с `migrate:fresh --seed`). Никакви fallback-и към стар път.
- LLM извиквания **само през services** (`GeneratorService` / `AgentLoop` / `OpenAiChatService` / `OllamaService`), никога директно от контролери или агенти.
- **Планерът ПРЕДЛАГА, кодът ГАРАНТИРА** — никога не вярвай на LLM изхода за структура; валидирай/нормализирай (както `AgentGeneratorService::finalizePlannedAgents`). Това важи двойно за org-плана и за персоните.
- Форматирай с `vendor/bin/pint`. Стандартна Laravel структура и именуване.
- Дизайн: **само** дизайн-токени и `x-*` компоненти; никакъв hardcoded hex в Blade. Светла тема, WCAG AA, reduced-motion, tabular числа (виж §16 на визията).
- Коментари на български, идентификатори/код на английски — както в кодовата база.

## Архитектурно решение (резюме)

Нов **org-слой** върху ядрото. Преизползваме ~80% (виж §2/§18 на визията):

| Нужда | Преизползваме (без промяна, освен ако е казано) |
|---|---|
| Планер на **една задача** | `FlowPlannerService::plan()` + `AgentGeneratorService::finalizePlannedAgents()` |
| Пускане на генерация (token) | `AgentGenerationLauncher::launch(...)` → `flows:generate-agents` |
| Изпълнение с паралелизъм | `GraphFlowExecutor::run()` + `NodeExecutorService` + Horizon |
| Интервю/Q&A машина + структуриран JSON | патърнът на `ClientFlowWizardService` (`AgentLoop` + parse/validate) |
| Проучване | research агенти (`DeepResearcherAgent`/`ResearcherAgent`/`MultiResearcherAgent`) + `BraveSearchService::search()` + `CrawlService` + `GooglePlacesService` |
| Памет/рефлексия per член | `KnowledgeService` + `FlowMemoryService` + `node_runs` история |
| Звезди ★ = мощност/цена | `App\Support\ModelLevel` (low→god) + `ModelRouterService::assign()` |
| Метеринг за кредити | `App\Support\LlmUsage` + `node_runs.cost_usd` + `agent_generation_logs.cost_usd` |
| `act` задачи + интеграции рейл | MCP конектори (`McpClientService`, `company_connectors`, `mcp_action` node) |
| Портретни аватари на членовете | `ComfyUIService` (`buildWorkflow`→`generate`→`getResult`, стабилен път) през нов `AvatarService` |
| Чат turn (queue + token-poll) | патърнът на `AssistantTurnJob` / `BuilderAssistantService` |
| Периодична работа | Horizon scheduling (`RunScheduledFlows` като образец) |

**Новото, което пишем:** `OrgPlannerService` (Управителят), `BusinessProfilerService`,
`OrgInterviewService`, `PersonaService`, `DirectorAgentService`, `BillingService` /
`CreditMeterService`, `OrgBlueprintLibraryService`, домейн моделите + миграциите, org
UI (Roster / Skill Tree / Карта на героя / Кутия за решения / Кредити), и слой за чат с
член. Всичко живее под `App\Http\Controllers\Client\Org\*`, `routes/client.php` (нова
група `org/*`), `resources/views/client/org/*`, без да пипа админ ядрото освен където е
явно изброено.

Имплементирай **фаза по фаза**; всяка фаза е самостоятелно тестваема в браузъра. Редът и
зависимостите са в Приложение Б. MVP-демо = Фази 0–3.

> **Конвенция за миграции:** последната дата в проекта е `2026_06_16`. Новите миграции
> тръгват от `2026_06_24_HHMMSS_...` нагоре, в реда по-долу.

---

## Дизайн насоки (Design guidance)

> Източник на истината за UI: `DESIGN.md` + `resources/css/app.css` (`@theme` токени) + `PRODUCT.md`. Никакъв hardcoded hex в Blade — само token utilities. Новите RPG екрани се градят към тази система; съществуващите страници се пипат **инкрементално** (виж `docs/UI-UX-REDESIGN-PLAN.md`), НЕ big-bang.

**Quality bar — frontend-design (Anthropic) философия:** намерен, нешаблонен дизайн; типографията носи характер; restraint + self-critique; „spend boldness in one place"; copy-то е дизайн материал. Активирай bundled плъгина `frontend-design` в Claude Code и го ползвай при всеки нов екран.

**Pre-delivery чеклист (на всеки нов екран):** `cursor-pointer` на кликаемите; hover преходи 150–300ms; контраст ≥4.5:1; видим focus (keyboard nav); `prefers-reduced-motion` спазен; responsive 375/768/1024/1440; иконки = Heroicons (без emoji); статус = иконка+текст+цвят; числа `tabular-nums`.

**ui-ux-pro-max — само РЕФЕРЕНЦИЯ (guardrail):** ползвай за чеклисти, Laravel/Blade патърни и компонентни/chart идеи. НЕ го пускай да генерира/override-ва дизайн система — `DESIGN.md`/`PRODUCT.md`/токените печелят. Каталожни стилове срещу бранда (glassmorphism/aurora/градиенти/неон) са забранени. Извиквай го нарочно за конкретен компонент, не като авторитет.

**RPG слой:** оперативните повърхности (табла/таблици/run/билинг) остават control-room (azure); характерните (Roster/Skill Tree/Карта на героя/чат) ползват character-палитрата + портрети + звезди (gold) + кредити (azure) — четимо, премиум, не неоново. Нови компоненти: виж `DESIGN.md` → „Components — RPG слой".

**`docs/AI-ORGANIZATION-MOCKUP.html` = САМО визуален референс (НЕ source).** Мокъпът показва структурата/метафорите/подредбата на екраните — **никога не копирай** неговите inline `hex`/градиенти/emoji иконки/inline `<style>` в реалния код. Реалният UI се гради изключително с дизайн-токените (`@theme` в `resources/css/app.css`), `<x-*>` компонентите и **Heroicons** (без emoji). Мокъпът има банер-коментар, който повтаря това. Взимай от него идея за layout, не стойности.

---

## Фаза 0 — Домейн модел + seed библиотеки + билинг скелет

**Цел:** да съществуват всички таблици, модели и seed данни (org-blueprints + persona
архетипи + планове), върху които стъпват всички следващи фази. Никакъв UI, никаква LLM
логика — само схема + сидове.

### 0.1 Конфигурация

- `config/organization.php` (нов):
  ```php
  return [
      'manager' => [
          'level'         => env('ORG_MANAGER_LEVEL', 'ultra'),   // ModelLevel за Управителя/Директорите
          'provider'      => env('ORG_PLANNER_PROVIDER', ''),     // празно → planner default
          'model'         => env('ORG_PLANNER_MODEL', ''),
          'max_questions' => (int) env('ORG_INTERVIEW_MAX_QUESTIONS', 8),
      ],
      'director'   => ['default_level' => env('ORG_DIRECTOR_LEVEL', 'high')],
      'persona'    => ['portraits' => (bool) env('ORG_PERSONA_PORTRAITS', true)],
      // act (write конектори) са HARD-DISABLED под preview ClientAuth (draft-first),
      // докато няма реален auth — §B2 / Фаза 5. false → act задачите дават „чернова
      // на действието" без реален страничен ефект; реалният auth е предусловие за true.
      'act'        => ['enabled' => (bool) env('ORG_ACT_ENABLED', false)],
      'seed_verticals' => ['fitness', 'restaurant', 'services'],   // §11 — 3 seed вертикали
  ];
  ```
- `config/services.php` (промяна) — портретен аватар над съществуващия ComfyUI блок (`AvatarService` ги чете; **fallback към основния `checkpoint`/`negative_prompt`**, не дублира ComfyUI клиента):
  ```php
  'comfyui' => [
      // ... съществуващите url / checkpoint / negative_prompt ...
      'portrait_checkpoint'    => env('COMFYUI_PORTRAIT_CHECKPOINT', env('COMFYUI_CHECKPOINT')), // null → основният checkpoint
      'portrait_negative'      => env('COMFYUI_PORTRAIT_NEGATIVE',                                 // face-friendly негатив за портрети
          'deformed face, distorted face, extra fingers, mutated hands, asymmetric eyes, cross-eyed, blurry, watermark, text, signature, cartoon, anime, 3d render'),
  ],
  ```
- `config/billing.php` (нов) — кредитна икономика (метеринг/резервация: Фаза 0.5; §14 на визията):
  ```php
  return [
      // base(star_tier) множител в кредити за пускане; индексът = ModelLevel.
      'star_multipliers' => [
          'low' => 1, 'medium' => 3, 'high' => 6, 'ultra' => 12, 'god' => 25,
      ],
      // Flat кредитни цени за не-LLM инструменти (BillableUnit, §0.5.1) — тези
      // услуги подават явна cost_usd, не минават през token формулата.
      'flat_costs' => [
          'brave_search' => 1, 'places' => 1, 'ocr_page' => 1,
          'avatar' => 5,       // един ComfyUI портрет
          'embedding' => 0,    // локален bge-m3 ≈ безплатен
      ],
      'credit_markup'    => (float) env('BILLING_CREDIT_MARKUP', 3.0),  // надценка над реален inference
      'work_per_token'   => (float) env('BILLING_WORK_PER_KTOKEN', 1.0), // кредити / 1k predict tokens
      'overage_enabled'  => (bool) env('BILLING_OVERAGE', true),
      // Stripe — ПО-КЪСНА фаза (Фаза 6); зад PaymentProvider. Сега зареждането е
      // админ-симулирано (AdminSimulatedPaymentProvider), тези остават празни.
      'stripe' => [
          'key'      => env('STRIPE_KEY'),
          'secret'   => env('STRIPE_SECRET'),
          'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
      ],
  ];
  ```
- `.env(.example)`: `ORG_MANAGER_LEVEL=ultra`, `ORG_DIRECTOR_LEVEL=high`, `ORG_PERSONA_PORTRAITS=true`, `ORG_ACT_ENABLED=false`, `COMFYUI_PORTRAIT_CHECKPOINT=`, `COMFYUI_PORTRAIT_NEGATIVE=`, `BILLING_CREDIT_MARKUP=3.0`, `STRIPE_KEY=`, `STRIPE_SECRET=`, `STRIPE_WEBHOOK_SECRET=`.

### 0.2 Миграции (домейн — §17 на визията)

> **Хибриден модел (версии + стабилна идентичност).** `org_versions` пазят **структурния
> снапшот** (org chart-а в момента на одобрение) — за атомарно одобрение, история и
> rollback, точно както `flow_versions` държи версиите на flow. НО идентичността на всеки
> член живее в стабилния **`org_members`** (един ред „за цял живот" на компания+ключ, който
> **преживява версиите**): там висят персона, чат, памет, представяне и — за асистентите —
> задачите (с техните flows/графици/runs/кредити). `directors`/`assistants` стават
> **плейсмънт редове** в дадена версия, сочещи стабилния член (= ролята/мястото му в тази
> версия). Така реорганизация = нов `org_version` с нови плейсмънти, без да чупи героите.

| # | Миграция | Таблица | Ключови колони |
|---|---|---|---|
| 1 | `create_business_profiles_table` | `business_profiles` | `id`, `company_id` FK→companies cascade **unique**, `research` json (сайт/ревюта/уеб синтез), `interview_answers` json, `situational_analysis` text, `pain_points` json, `status` string default `draft` (`draft\|researching\|interviewing\|ready`), timestamps |
| 2 | `create_org_versions_table` | `org_versions` | `id`, `company_id` FK cascade, `version` uint, `status` string default `draft` (`draft\|approved\|archived`), `summary` text, `blueprint_key` string null (кой org_blueprint е бил прайър), `approved_at` datetime null, `created_by` FK→users null, timestamps; index `(company_id, status)` |
| 3 | `add_active_org_version_to_companies_table` | `companies` | `active_org_version_id` FK→org_versions nullOnDelete null |
| 4 | `create_org_members_table` | `org_members` | `id`, `company_id` FK→companies cascade, `kind` string (`manager\|director\|assistant`), `key` string (стабилен машинен ключ), `display_name` string, `status` string default `active` (`proposed\|active\|retired`), `retired_at` datetime null, `default_star_tier` string default `medium` (валидно `ModelLevel` — **рангът/нивото на члена**; стабилен, преживява версиите; задачите му без явен override наследяват това ниво — виж §6.1 „Наследяване на нивото"), `avatar_url` string null (денормализиран от `personas.avatar_url` за бързи roster заявки — източникът на истината остава персоната), timestamps; **unique `(company_id, key)`**, index `(company_id, kind, status)` (стабилната идентичност — „служителят за цял живот"; тук висят персона/чат/памет/представяне, **нивото (`default_star_tier`)** и — за асистенти — задачите) |
| 5 | `create_directors_table` | `directors` | `id`, `org_version_id` FK cascade, `org_member_id` FK→org_members cascade, `title` string, `domain` string (operations/marketing/finance/...), `mandate` text, `kpi` json null, `position` json null (x/y за графа), `status` string default `active`, timestamps; unique `(org_version_id, org_member_id)` (плейсмънт ред = ролята/мястото на този член В ТАЗИ версия). **Бел.:** нивото на директора живее на `org_members.default_star_tier` (рангът на члена), НЕ тук — за да няма дублиран източник на истина. |
| 6 | `create_assistants_table` | `assistants` | `id`, `org_version_id` FK cascade, `org_member_id` FK→org_members cascade, `director_id` FK→directors cascade, `title` string, `mandate` text, `kpi` json null, `position` json null, `status` string default `active`, timestamps; unique `(org_version_id, org_member_id)`, index `director_id` |
| 7 | `create_assistant_tasks_table` | `assistant_tasks` | `id`, `org_member_id` FK→org_members cascade (асистент-членът — задачите висят на стабилния член, НЕ на per-version assistant ред, за да преживеят реорганизациите), `current_director_member_id` FK→org_members nullOnDelete null (по избор — текущото подчинение), `flow_id` FK→flows nullOnDelete null, `title` string, `description` text, `trigger` string default `manual` (`manual\|scheduled\|event`), `schedule` string null (cron), `act_mode` string default `draft` (`draft\|act\|mixed`), `approval_policy` string default `approve_each` (`auto\|approve_each\|approve_first_then_auto`), `star_tier` string **nullable** (null = наследява `org_members.default_star_tier` на члена-собственик; non-null = явен per-task override; накрая cap по `plans.max_star_tier` — виж §6.1 „Наследяване на нивото"), `kpi` string null, `status` string default `proposed` (`proposed\|generating\|ready\|disabled\|failed`), `gen_token` string null, **`run_after_generate` bool default false** (persisted намерение „пусни щом стане `ready`" — lazy-gen gate, §A3), timestamps; index `(org_member_id, status)` |
| 8 | `create_personas_table` | `personas` | `id`, `org_member_id` FK→org_members cascade **unique** (една персона на член; mutable, промените се логват), `name` string, `ethnicity` string null, `age` uint null, `gender` string null, `background` string null, `education` string null, `bio` text null, `traits` json (риск/креативност/прецизност/автономност/темпо/тон, 0–100), `tone` string null, `derived_knobs` json null (`temperature`, `star_tier`, `tool_bias`, `parallelism` — резултатът от §5), `archetype_key` string null, **`avatar_url` string null, `avatar_prompt` text null, `avatar_seed` unsignedBigInteger null (фиксиран per член → стабилен ре-рендер), `avatar_status` string default `pending` (`pending\|ready\|failed`), `avatar_meta` json null** (портретният аватар се **извежда от демографията** на персоната — pol/възраст/етнос/роля/тон; чисто козметичен, виж §5.3 на визията), timestamps |
| 9 | `create_member_chats_table` | `member_chats` | `id`, `company_id` FK cascade, `org_member_id` FK→org_members cascade (членът — чатът преживява версиите), `title` string null, `last_message_at` datetime null, timestamps; index `org_member_id` |
| 10 | `create_member_messages_table` | `member_messages` | `id`, `member_chat_id` FK cascade, `role` string (`user\|assistant`), `content` text null, `payload` json null (предложено действие → за Кутията), `status` string default `completed` (`pending\|completed\|failed`), `error` text null, `cost_usd` decimal(10,6) null, timestamps; index `member_chat_id` |
| 11 | `create_org_events_table` | `org_events` | `id`, `company_id` FK cascade, `org_version_id` FK nullOnDelete null, `type` string (`hire\|fire\|reassign\|mandate_change\|approval\|action\|review`), `org_member_id` FK→org_members nullOnDelete null (когато субектът е член), `subject_type` string null + `subject_id` uint null (за нечленски субекти), `summary` text, `meta` json null, `actor` string null (`manager\|director\|human`), `created_at` (append-only, без updated_at); index `(company_id, created_at)` |
| 12 | `create_org_blueprints_table` | `org_blueprints` | `id`, `vertical` string index, `name` string, `structure` json (директори + типични асистенти + типични задачи), `embedding` json null, `proven` bool default false, `source_company_id` FK nullOnDelete null, timestamps |
| 13 | `create_persona_archetypes_table` | `persona_archetypes` | `id`, `vertical` string null index, `role` string (director/assistant), `name` string (напр. „млад growth маркетинг"), `traits` json, `tone` string null, `bio_template` text null, `embedding` json null, timestamps |
| 14 | `create_plans_table` | `plans` | `id`, `key` string unique (`starter\|professional\|business\|enterprise`), `name` string, `price_cents` uint, `monthly_credits` uint, `max_star_tier` string (макс. позволено `ModelLevel`), `features` json null, `stripe_price_id` string null, `is_active` bool default true, timestamps |
| 15 | `create_subscriptions_table` | `subscriptions` | `id`, `company_id` FK cascade, `plan_id` FK→plans restrict, `status` string default `active` (`active\|past_due\|canceled`), `stripe_subscription_id` string null, `current_period_end` datetime null, timestamps; unique `company_id` |
| 16 | `create_credit_wallets_table` | `credit_wallets` | `id`, `company_id` FK cascade **unique**, `balance` integer default 0 (кредити), `included_this_period` uint default 0, `overage_used` uint default 0, `period_start` datetime null, timestamps |
| 17 | `create_credit_ledger_table` | `credit_ledger` | `id`, `credit_wallet_id` FK cascade, `company_id` FK cascade (денормализирано за справки), **`reservation_id` FK→credit_reservations nullOnDelete null** (към коя резервация принадлежи редът — §A1), `type` string (**`reserve\|settle\|refund\|topup\|grant`** — носещото поле за reserve/settle машината, §0.5.2), `idempotency_key` string **unique** null — **operation-scoped** (напр. `"{reservation}:settle"`, `"{reservation}:refund"`; reserve/settle/refund са РАЗЛИЧНИ редове с РАЗЛИЧНИ ключове — без collision, §A1), `direction` string (`debit\|credit`, само за справки), `amount` integer (кредити, винаги ≥0; знакът се чете от `type`), `reason` string (`run\|monthly_grant\|top_up\|overage\|refund`), `flow_run_id` FK nullOnDelete null, `node_run_id` FK nullOnDelete null, `cost_usd` decimal(10,6) null (реалният inference зад дебита), `meta` json null, `created_at` (append-only); index `(company_id, created_at)` |
| 18 | `create_credit_reservations_table` | `credit_reservations` | `id`, `company_id` FK→companies cascade, `context_type` string (`org_planning\|interview\|research\|member_chat\|avatar\|embedding\|generation\|task_run`), `subject_type` string null + `subject_id` uint null (полиморфният субект: `org_member_id` / `assistant_task_id` / `flow_run_id` според контекста), `estimated_credits` uint, `spent_credits` uint default 0, `status` string default `reserved` (`reserved\|settled\|refunded\|expired`), `idempotency_key` string **unique** (на RESERVE intent, напр. `"{context}:{subject}:reserve"` — пази от двоен резерв при retry/паралел), timestamps; index `(company_id, context_type, status)`. **Резервацията носи mutable състоянието на една билинг-операция; `credit_ledger` е append-only журналът зад нея (reserve/settle/refund редове сочат `reservation_id`).** |
| 19 | `create_org_proposals_table` | `org_proposals` | `id`, `company_id` FK→companies cascade, `type` string (`org_change\|hire\|fire\|task\|mandate\|act_action`), `payload` json, `status` string default `pending` (`pending\|approved\|rejected\|superseded`), `base_org_version_id` FK→org_versions nullOnDelete null (срещу коя активна версия е изготвено предложението — за optimistic concurrency), `decided_by` FK→users nullOnDelete null, `decided_at` datetime null, timestamps; index `(company_id, status)`. **`org_events` остава append-only одит; `org_proposals` носи MUTABLE решението** (виж §A7 — DecisionBox boundary). При approve, ако `base_org_version_id` != активната версия → `superseded` + ре-ревю (две паралелни одобрения не материализват върху остаряла версия). |

### 0.3 Модели (релации + casts)

- `BusinessProfile` (`company` belongsTo; casts `research`,`interview_answers`,`pain_points`→array).
- **`OrgMember`** (стабилната идентичност — „служителят за цял живот"): `company` belongsTo; `persona` hasOne (на `org_member_id`); `chats` hasMany `MemberChat`; `tasks` hasMany `AssistantTask` (за `kind=assistant`); `placements()` — директор/асистент плейсмънт редовете на члена през версиите. Helpers `isActive(): bool`, `currentPlacement()` (плейсмънтът му в активната версия на компанията); **`static allocateKey(Company $company, string $kind, string $displayName): string`** — детерминистичен код-алокатор на стабилен `key` за НОВ член (slug на името + суфикс за уникалност по `(company, key)`); единственото място, което ражда ключове — **никога LLM** (виж §9 на плана / `finalizeOrganization`). **Ниво/ранг:** `defaultStarTier(): \App\Support\ModelLevel` (от колоната `default_star_tier`); `setDefaultStarTier(\App\Support\ModelLevel $tier): void` — **повишение/понижение** на члена: записва новото ниво (стабилен ранг) и логва `org_event` (`mandate_change`). Промяната веднага мени effective tier на всичките му задачи **без явен override** (рецена на кредитите). За директор: `applyTierToDepartment(\App\Support\ModelLevel $tier): void` — **опционално** (само през UI действието „повиши целия отдел", не тихо/автоматично) сетва `default_star_tier` на асистентите си **без ръчен override** → каскадира към техните задачи.
- `OrgVersion` (`company`, `directors` hasMany, `assistants` hasMany; cast `approved_at`→datetime; scope `approved()`).
- `Director` (`orgVersion`, `orgMember` belongsTo, `assistants` hasMany; casts `kpi`,`position`→array). Персоната се чете през `orgMember->persona`.
- `Assistant` (`orgVersion`, `orgMember` belongsTo, `director`; casts `kpi`,`position`→array). Задачите/персоната се четат през `orgMember`.
- `AssistantTask` (`orgMember` belongsTo — асистент-членът; `currentDirectorMember()` belongsTo `OrgMember` null; `flow` belongsTo; casts none extra; helper `isWriteAct(): bool`). **Наследяване на нивото:** `inheritsTier(): bool` (= `star_tier` е `null` → наследява от члена); `effectiveStarTier(): \App\Support\ModelLevel` — **каноничното правило**: `star_tier ?? orgMember.default_star_tier`, после **cap** по абонамента (`min` спрямо `Subscription→Plan::max_star_tier` на компанията). Това е ЕДИНСТВЕНИЯТ източник на „с кое ниво се пуска задачата" — суровият `star_tier` НЕ се ползва директно при оценка/изпълнение.
- `Persona` (`orgMember` belongsTo; casts `traits`,`derived_knobs`,`avatar_meta`→array). Helper `hasReadyAvatar(): bool` (`avatar_status==='ready' && avatar_url`).
- `MemberChat` (`company`, `orgMember` belongsTo, `messages` hasMany; cast `last_message_at`→datetime).
- `MemberMessage` (`chat` belongsTo; casts `payload`→array, `cost_usd`→decimal:6).
- `OrgEvent` (`company`; `orgMember` belongsTo null; `$timestamps=false` или само `created_at`; cast `meta`→array). Append-only.
- `OrgBlueprint` (casts `structure`,`embedding`→array; scope `proven()`).
- `PersonaArchetype` (casts `traits`,`embedding`→array).
- `Plan` (`subscriptions` hasMany; cast `features`→array). `Subscription` (`company`,`plan`; cast `current_period_end`→datetime).
- `CreditWallet` (`company`, `ledger` hasMany; helper `hasCredits(int $n): bool`).
- `CreditLedgerEntry` (table `credit_ledger`; `company`,`wallet`, `reservation` belongsTo `CreditReservation` null; casts `meta`→array,`cost_usd`→decimal:6; `created_at` only).
- **`CreditReservation`** (`company` belongsTo; `ledgerEntries` hasMany `CreditLedgerEntry`; `subject()` morphTo по `subject_type`/`subject_id`; casts none extra). Helpers `isOpen(): bool` (`status==='reserved'`), `remaining(): int` (`estimated_credits - spent_credits`). **Mutable състоянието на една билинг-операция** (§A1) — `CreditMeterService` го движи (reserve → settle/refund/expired).
- **`OrgProposal`** (`company` belongsTo; `baseOrgVersion` belongsTo `OrgVersion` null; `decidedBy` belongsTo `User` null; cast `payload`→array, `decided_at`→datetime; scope `pending()`). **Mutable решението** зад DecisionBox (§A7) — `org_events` остава отделният append-only одит.
- **`Company`** (промяна): добави `members()` hasMany `OrgMember`, `manager()` hasOne `OrgMember` (where `kind=manager`), `businessProfile()` hasOne, `orgVersions()` hasMany, `activeOrgVersion()` belongsTo, `subscription()` hasOne, `creditWallet()` hasOne, `orgEvents()` hasMany. Добави `active_org_version_id` към `$fillable`.

### 0.4 Сидове (`database/seeders/`)

- `OrgBlueprintSeeder` — 3 seed вертикала (`fitness`, `restaurant`, `services`) от §23 на визията: за всеки — масив от директори (operations/marketing/finance/customer/data/training, всеки с подсказка за `default_star_tier`) + типични асистенти (с `default_star_tier` по роля) + типични задачи (заглавие + draft/act; `star_tier` се задава САМО когато задачата умишлено се различава от нивото на члена — иначе остава `null` = наследява). `proven=false`.
- `PersonaArchetypeSeeder` — типови персони по роля×вертикал (напр. „млад визионер growth", „ветеран финансов директор", „прецизен операции") с `traits` стойности.
- `PlanSeeder` — 4 плана с **фиксиран `max_star_tier` ∈ `ModelLevel`** (`low` е универсалният под за всички — никой план не го „забранява"): **Starter = `medium`, Professional = `high`, Business = `ultra`, Enterprise = `god`** (виж B3). `monthly_credits` + `price_cents`. Стойностите са каноничните `ModelLevel` ключове (low/medium/high/ultra/god), НЕ брой звезди — звездите са презентационен слой над тях.
- Закачи и трите в `DatabaseSeeder::run()` (наред с LlmModel + AgentTemplate сидовете).

**Критерии за приемане (Фаза 0):**
- `php artisan migrate:fresh --seed` минава чисто; всичките 19 миграции на §0.2 (18 нови таблици — вкл. `credit_reservations` + `org_proposals` — + 1 колона на `companies`) са приложени.
- `OrgBlueprint::where('vertical','fitness')->exists()` е true; `Plan::count()===4`; persona архетипи са сидвани.
- `tinker`: създаване на `OrgMember` + `Persona` (на `org_member_id`) + `OrgVersion` + `Director`/`Assistant` плейсмънт редове, сочещи члена, работи с релациите (`$member->currentPlacement()`, `$member->persona`).

---

## Фаза 0.5 — Изпълнение и билинг (foundation)

**Цел:** да съществува **реалната** кредитна машина (метеринг → резервация → реконсилиация),
персона-инжекцията в изпълнението, жизненият цикъл на задачите и унифицираната Кутия —
**преди** която и да е фаза да пуска агенти, да дебитира кредити или да действа. Никакъв
UI тук; това е инфраструктурният слой, който всяка следваща фаза ползва. **Билингът е
кредитен от старта, но БЕЗ Stripe** — зареждането е **админ-симулирано** (виж §0.5.4);
Stripe е по-късен drop-in зад `PaymentProvider` (Фаза 6). Решенията „реален процесор от
старта" се преформулират като **„кредитен foundation от старта; админ-симулирано зареждане
сега; Stripe по-късно"**.

> **Защо преди UI фазите:** изпълнението е паралелно (`GraphFlowExecutor`, до 3 `flows`
> Horizon процеса) и метерингът тече през съществуващите `llm_requests` (`LlmUsage` +
> `LlmRequestRecorder`) — НЕ само през `FlowRun`. Без атомарна резервация + идемпотентност
> два паралелни/повторени job-а биха дебитирали два пъти. Тази фаза прави wallet-а
> авторитетния гейт на всяко пускане.

### 0.5.1 Метеринг върху `llm_requests` (billable-unit слой)

**Източникът на истината за разхода = съществуващите `llm_requests`** (всеки ред пишан от
`LlmRequestRecorder::record` до `LlmUsage::record`, атрибутиран към ambient `LlmContext` —
`company_id`/`flow_run_id`/`node_run_id`/`agent_name`/`agent_type`/`purpose`), а НЕ само
`FlowRun`. `FlowRun` е **един** billable контекст, не единственият.

- **`App\Support\BillableUnit`** (нов pure helper) — превежда употреба → кредити:
  - LLM request → `credits = base(level) × ceil(predict_tokens / 1000)` (същата канонична
    формула като §14.2 / §6.1; `predict_tokens` = `completion_tokens` от реда), където
    `base` идва от `config('billing.star_multipliers')`.
  - Не-LLM инструменти (Brave search, Google Places, OCR, **ComfyUI аватар**, embeddings) →
    **flat кредитни цени** от config (`config('billing.flat_costs')`, нов блок — напр.
    `'brave_search' => 1, 'places' => 1, 'ocr_page' => 1, 'avatar' => 5, 'embedding' => 0`).
    Тези услуги вече подават явна `cost_usd` (виж `LlmRequestRecorder::$costOverride` за
    flat-priced повиквания) — billable слоят ги мапва към фиксиран кредитен разход, не през
    token формулата.
- **Billable контексти (`context_type`):** `org_planning`, `interview`, `research`,
  `director_tick`, `member_chat`, `avatar`, `embedding`, `task_run` (FlowRun). Всеки LLM
  request се атрибутира към `(company_id, context_type, subject)` — `context_type` се
  извежда от `LlmContext.purpose`/`agent_type`, `subject` сочи `org_member_id` /
  `assistant_task_id` / `flow_run_id` според контекста (разширяваме `LlmContext` с
  опционални `context_type` + `subject_type`/`subject_id`, които `LlmRequestRecorder`
  персистира — без да чупи runtime пътя; миграция `add_billing_context_to_llm_requests_table`
  добавя `context_type`/`subject_type`/`subject_id` към `llm_requests`).
- **Атрибуция към резервацията (`reservation_id`, §A6).** Същата миграция
  `add_billing_context_to_llm_requests_table` добавя и **`reservation_id`** (FK→credit_reservations,
  null) към `llm_requests`. `LlmContext` пренася `reservation_id` на текущата резервация, а
  `LlmRequestRecorder` го **стампва** на всеки записан ред — така `actualFor(reservation)`
  (§0.5.2) сумира точно requests-ите, направени ПОД тази резервация (а не по груб `flow_run_id`
  филтър, който не покрива не-flow контекстите: chat/research/avatar/interview/generation).
  **Всеки billable контекст обвива работата си в `LlmContext` с `reservation_id`** преди да
  пусне LLM/инструмент — това е инвариантът, който прави settle точен за ВСИЧКИ контексти.
- **Гаранция (планерът предлага, кодът гарантира):** `BillableUnit` чете САМО персистираните
  редове (`llm_requests.completion_tokens` / `cost_usd`, филтрирани по `reservation_id`) —
  никаква оценка не се вярва за крайния дебит; реконсилиацията (§0.5.2) винаги взима реалните токени.

### 0.5.2 Кредитна резервация + идемпотентност + атомарен decrement + reconciliation

Понеже изпълнението е паралелно, наивното estimate→debit (както го описваше Фаза 6) е
race-prone. Заменяме го с **reserve → settle/refund** двуфазен протокол с idempotency,
**унифициран за ВСИЧКИ контексти** (chat/research/avatar/interview/generation/task_run) през
модела `CreditReservation` (§A1), а НЕ само за `FlowRun`:

- **Reservation модел + operation-scoped keys (§A1).** Резервацията (`credit_reservations`,
  таблица #18 на §0.2) е **mutable състоянието** на една билинг-операция: `context_type`,
  полиморфен `subject`, `estimated_credits`, `spent_credits`, `status`
  (`reserved\|settled\|refunded\|expired`) + **unique `idempotency_key` на RESERVE intent**
  (`"{context}:{subject}:reserve"`). `credit_ledger` е append-only **журналът** зад нея: всеки
  ред носи `reservation_id` и **operation-scoped** `idempotency_key` — reserve / settle / refund
  са **РАЗЛИЧНИ редове с РАЗЛИЧНИ ключове** (`"{reservation}:settle"`, `"{reservation}:refund"`),
  така че няма collision между фазите (старият модел делеше един ключ за reserve и settle).
  `type` остава носещото поле (`reserve\|settle\|refund\|topup\|grant`); `direction`/`amount`
  остават за справки. **Без legacy fallback** — старата „наивно estimate→debit" пътека се
  **трие** (виж §6.1).
- **`CreditMeterService` е context-agnostic (§A2)** — settle е по `CreditReservation`, не по
  `FlowRun`. Премахваме всякакъв `settle(FlowRun $run)`-only вид. Сигнатури:
  - `reserve(int $companyId, string $contextType, $subject, int $estimate): CreditReservation`
    — **атомарен wallet decrement на DB ниво**: единичен
    `UPDATE credit_wallets SET balance = balance - ? WHERE company_id = ? AND balance >= ?`
    (условният `balance >= ?` гарантира, че два паралелни/повторени run-а не свалят баланса под
    нула); при 0 засегнати реда → **блок** (недостиг — хвърля/връща сигнал, контролерът прави
    402+upsell). При успех създава `CreditReservation(status=reserved)` + ledger ред `type=reserve`
    (`reservation_id` + ключ `"{context}:{subject}:reserve"`). **Двоен опит със същия RESERVE ключ
    → no-op** (unique constraint на резервацията + на ledger реда — retry/паралел не дебитира два пъти).
  - `actualFor(CreditReservation $r): int` — реалният разход = сума на `BillableUnit` над
    **`llm_requests`, атрибутирани към тази резервация** (`reservation_id`, §0.5.1) + **flat tool
    costs** (Brave/Places/OCR/ComfyUI аватар/embedding от `config('billing.flat_costs')`). Чете
    САМО персистирани редове — никаква оценка.
  - `settle(CreditReservation $r, int $actualSpent): void` — реконсилиация: записва `spent_credits`,
    добавя ledger ред `type=settle` (ключ `"{reservation}:settle"`) и **refund-ва остатъка**
    `estimate - actual`, ако е положителен (ledger `type=refund`, ключ `"{reservation}:refund"`),
    връщайки кредитите в баланса; ако actual > estimate → допълнителен атомарен decrement.
    Маркира `status=settled`. Идемпотентен по operation-scoped ключовете.
  - `refund(CreditReservation $r): void` — пълно връщане на резервирания остатък (операцията
    изобщо не стартира / провал преди първи request); ledger `type=refund`, `status=refunded`.
  - `topup(Company $company, int $credits, string $type, array $meta = []): CreditLedgerEntry`
    — зареждане (`type=topup|grant`) → атомарен increment + ledger ред (§0.5.4 го вика; няма резервация).
- **Източникът на истината за разхода остава `LlmUsage`/`llm_requests`/`cost_usd`** —
  `CreditMeterService` само превежда персистираните редове (`actualFor`) в кредити и движи баланса.

### 0.5.3 Wallet гейтва изпълнението

Всяко пускане проверява **баланс ≥ оценка** и резервира ПРЕДИ старт; недостигът блокира/паузира.
Всеки контекст вика `CreditMeterService::reserve($companyId, $contextType, $subject, $estimate)`
(§0.5.2) → получава `CreditReservation`, обвива работата си в `LlmContext` с нейния `reservation_id`
(§0.5.1/§A6), после `settle($reservation, $actual)` на завършване:

- **Ръчен run** (`AssistantTaskController@run`, §3.2) → `reserve(company, 'task_run', $flowRun,
  BillableUnit::estimate($task))`; при недостиг → 402/приятелски блок + upsell, run-ът НЕ стартира.
- **Scheduled tick / задача** (`DirectorTickJob`, `ScheduledTaskJob`, §4.2) → същата `task_run`
  резервация; при недостиг → **пропуска** изпълнението + `org_event` (`type=review`,
  summary „пропуснато: недостатъчно кредити") + известие, без да фейлва тика.
- **Чат с член** (`MemberChatTurnJob`, §4.4 → `'member_chat'`) и **avatar**
  (`GenerateMemberAvatarJob`, §1.1 → `'avatar'`) и **research/интервю/org планиране**
  (§1.3 → `'research'`/`'interview'`/`'org_planning'`) → лека резервация (flat estimate) преди да
  пуснат LLM/ComfyUI; при недостиг → дружелюбно „недостатъчно кредити" вместо повикване.
- **Памет/embeddings** (`DistillFlowMemoryJob`, §A6) са **ОТДЕЛЕН** billable контекст (`'embedding'`)
  със своя reserve/settle, async СЛЕД run-а — не се смесват с `task_run` резервацията.
- На нула баланс → агентите **спират до зареждане/ъпгрейд** (§Бизнес модел на визията).

> **FinalComposer usage не бива да изтича (§A6).** `GraphFlowExecutor::finalize()` (виж кода —
> вика `FinalComposerService::compose()`, който прави **собствен LLM ход** за форматиране) се
> изпълнява СЛЕД последната вълна, в Horizon worker. Затова `finalize()` ТРЯБВА да отвори
> `LlmContext` с **резервацията на run-а** (`task_run`, `reservation_id` от
> `FlowRun.context['credit_reservation_id']`), за да се атрибутира композиращият request към
> правилната резервация — иначе той се записва в `llm_requests` без `reservation_id` и settle
> го пропуска (изтекъл разход). Settle на `task_run` става в `finalize()` (success) и в `fail()`
> (терминал — §A4), след като `actualFor` вече вижда и FinalComposer реда. **`DistillFlowMemoryJob`
> остава ОТДЕЛЕН** (`embedding`) — диспечира се от `finalize()` както сега, но със собствена
> резервация/контекст, не под `task_run`.

> **Terminal settlement — failed/rejected/cancelled НЕ изтичат резервирани кредити (§A4).**
> Резервацията на `task_run` трябва да се закрие на ВСЕКИ терминален изход, симетрично на
> success-а:
> - **Success** — `GraphFlowExecutor::finalize()`: `settle($reservation, actualFor($reservation))`
>   (реалното до момента + refund на остатъка).
> - **Провал / cancel** — `GraphFlowExecutor::fail()` (терминалната провал-пътека) + cancel
>   пътят: добави settle/refund хук — `settle` реално изхарченото до провала + **refund на
>   резервирания остатък** (агентът може да е похарчил нещо преди да фейлне; остатъкът се връща).
> - **Human-approval REJECT** — `FlowRunController::approval` при `decision=reject` **минава през
>   `fail()`** (виж кода — `$executor->fail(...)` на отхвърляне), значи покрива се от същия хук;
>   няма нужда от отделна reject-логика, стига `fail()` да settle-ва.
>
> Така failed/rejected/cancelled run **никога не задържа резервираните кредити** — балансът се
> възстановява до реално похарченото. (Идемпотентно по operation-scoped ключовете — двоен
> `fail`/повторен callback не дебитира/refund-ва повторно.)

### 0.5.4 Админ-симулирано зареждане (зад `PaymentProvider`)

Платежният слой е интерфейс от старта; **БЕЗ Stripe код сега**:

- **`App\Services\Org\Billing\PaymentProvider`** (интерфейс) — `charge(...)` / `grantCredits(...)`.
  Имплементация сега: **`AdminSimulatedPaymentProvider`** (нищо външно — просто записва).
  Stripe е по-късен drop-in (Фаза 6) — сменя се само binding-ът в service provider-а.
- **Админ действие** (в съществуващия `is_admin`-гейтнат админ панел, `Admin/*`): „Зареди
  кредити + сложи план" за фирма → `BillingService::adminTopUp(Company, int $credits, ?Plan $plan)`
  → `CreditMeterService::topup(..., type: 'topup')` (или `grant` за безплатни) + сетва/сменя
  `subscriptions.plan_id` и `plans.max_star_tier` тавана. Ledger `type=topup|grant`.
- **Месечен grant** (`grantMonthly(Subscription)` от §6.1) остава — извиква се ръчно/планирано;
  при Stripe фазата го дърпа webhook-ът. Сега няма webhook.

### 0.5.5 Persona injection contract за Flow runtime

Всяко пускане на Flow носи **своя член-собственик**, за да „действа като себе си" в
изпълнението — НЕ само в чата:

- **Резолюция на собственика:** `FlowRun` се пуска с org-контекст
  (`context['assistant_task_id']` + произтичащият `context['org_member_id']`) — задаваме го
  в `AssistantTaskController@run` / директорския tick, който вика
  `GraphFlowExecutor::run($flow, 'manual', $run)` с вече попълнен `$run->context`. От
  `assistant_task` → `org_member` (собственик) → `persona`.
- **Инжекция:** разшири сглобяването на промпта в `NodePromptBuilder::renderPrompt` (или
  тънка добавка в `NodeExecutorService::runOnce`, до блоковете „ПАМЕТ"/„ЗНАНИЕ") така, че за
  възлите на org-flow да се добави **ПЕРСОНА БЛОК** = `PersonaService::compileSystemPrompt`
  на члена-собственик (характер/ценности/стил — НЕ компетентност). Блокът пътува през
  ambient контекста (`FlowRun.context['org_member_id']`), резолвиран веднъж и кеширан в run-а,
  за да не удря БД на всеки възел.
- **ИЗКЛЮЧЕНИЕ (под на компетентност, §5.3):** persona-неутралните възли НЕ получават
  персона — `qa_verifier`, `bg_text_corrector`, `translator`, `human_approval`, `mcp_action`,
  `decision` (същият excluded списък като `NodeExecutorService::shouldInjectKnowledge`). Така
  „импулсивен" член мени КАК, не дали QA минава.
- **Гаранция:** ако `FlowRun` няма `org_member_id` (обикновен админ/клиентски flow извън
  org-слоя), инжекцията е no-op — нулева промяна в съществуващото изпълнение.

### 0.5.6 Generation state machine за задачите (+ lazy-gen billing gate)

Задачата има **явен жизнен цикъл**: `proposed → generating → ready → running` (+ `disabled`/`failed`).
Run-ът изисква `ready` (т.е. има `flow_id`); липсва ли — **асинхронна** генерация, не синхронно чакане:

- Разшири **`AgentGenerationLauncher::launch(...)`** да приема `?int $assistantTaskId = null`
  (сега само `?int $draftId`) + персистира го в `agent_gen_request_{token}`.
- Разшири **`GenerateAgentsCommand`** (`flows:generate-agents`): след успешен persist, ако
  заявката носи `assistant_task_id` → **callback**, който връзва новосъздадения `flow_id` към
  задачата и сетва `assistant_tasks.status='ready'` (огледало на `draft → completed` реда).
  При провал → `status='failed'`.
- **Генерацията е billable контекст (§A3).** Самият дизайн на pipeline-а е реален LLM разход
  (FlowPlanner — три фази). Затова **ПРЕДИ да диспечираш генерация** на flow за задача без
  `flow_id`: `CreditMeterService::reserve(company, 'generation', $task, BillableUnit::estimateGeneration($task))`;
  при **недостиг → блокирай** (не генерирай — 402/known-блок), settle след callback по
  `actualFor` (реалните planner `llm_requests` под тази резервация). **Никакво харчене при нулев
  баланс** — нито за генерация, нито за run.
- **`run_after_generate` (§A3).** Persisted bool на `assistant_tasks` (миграция #7). Когато run
  бъде поискан за задача без `flow_id`, той сетва `run_after_generate=true` и пуска генерацията.
  Щом генерацията върже `flow_id` и сетне `status=ready`, callback-ът проверява: ако
  `run_after_generate` **И** има баланс за `task_run` резервация (§0.5.3) → **пуска** run-а
  автоматично; иначе само остава `ready` (нищо не се харчи). Това замества всякакво синхронно
  чакане — намерението е durable, не държано в HTTP заявка.
- **Run без `flow_id`** (`AssistantTaskController@run`, §3.2; директорски tick, §4.1) →
  (резервира `generation`, §горе) диспечира генерация (АСИНХРОННО през launcher-а) и **НЕ
  блокира** — задачата минава `generating` с `run_after_generate=true`, а run-ът се пуска от
  callback-а при `ready` (ако има баланс). **Никакво синхронно `sleep`-чакане в HTTP заявка.**
  Директорският tick спазва същото (lazy/by-request).

### 0.5.7 Унифициран Decision Box адаптер (+ `ApprovalService` boundary + durable proposal state)

Кутията за решения **НЕ дублира** одобренията — тя **АГРЕГИРА** два съществуващи източника:

- **(а) Org предложения** — durable редове в **`org_proposals`** (таблица #19) със
  `status=pending` (§A7). DecisionBox чете тук **mutable решението** (не `member_messages.payload`
  като носещ запис); `org_events` остава **отделният append-only одит** на взетото решение.
  `DirectorAgentService`/`OrgReviewService`/чатът създават `org_proposals(pending)`.
- **(б) Паузирани `FlowRun` `human_approval`** — run-овете, спрени на approval gate
  (`NodeExecutorService::pauseForApproval` → `FlowRun.status='waiting_approval'`,
  `NodeRun.status='paused'`, `context['approvals'][nodeKey]`). **Преизползва изцяло**
  съществуващия pause/resume.
- **`ApprovalService` (нов boundary, §A7).** Извади resume-after-approval логиката от
  `FlowRunController::approval` (днес контролерът сам прави audit-в `context['approvals']`,
  маркира `NodeRun` completed/failed и вика `GraphFlowExecutor::resumeAfterApproval`/`fail`) в
  **`App\Services\Org\ApprovalService::settle(FlowRun, string $nodeKey, bool $approved, ?string $comment)`**.
  И `FlowRunController::approval`, И `DecisionBoxService` викат `ApprovalService` — **никакъв
  controller→controller call** (старият план караше DecisionBox да вика
  `FlowRunController::approval`, което е анти-патърн). `FlowRunController::approval` става тънка
  обвивка над `ApprovalService`; resume-логиката е една, в service-а.
- **`DecisionBoxService` = адаптер/агрегатор** (НЕ нова одобрителна машина):
  - `pending(Company $company): Collection` — обединява `org_proposals(pending)` + паузираните
    `human_approval` runs в общ списък „чакащи решения".
  - `approve(...)` / `reject(...)`:
    - **org предложение** → ъпдейтва `org_proposals.status` + записва `org_event` (`approval`).
      **Optimistic concurrency (§A7):** при approve, ако `base_org_version_id` **≠** активната
      версия на компанията → маркирай предложението `superseded` и **поискай ре-ревю** (НЕ
      материализирай) — две паралелни одобрения не бива да материализват върху остаряла версия.
      Само когато базата съвпада → продължава към материализация (нов `org_version` /
      hire/fire/mandate) или `act` (Фаза 5).
    - **паузиран run** → `ApprovalService::settle(...)` (резюме/reject през единния boundary).

### 0.5.8 Queue topology (рутиране на org jobs)

Днешният `config/horizon.php` има два супервайзора: **`supervisor-flows`** (`flows` queue,
1–3 процеса, `timeout=1200`, `tries=1`) и **`supervisor-default`** (`default` queue, **1 проц,
`timeout=900`**). Дългите org/research/avatar jobs **НЕ бива** да висят на `default` (1 проц,
900s) — един бавен research/tick би запушил единствения процес и би гладувал чата/поллинга — и
**НЕ бива** да висят на `flows` (тя е само за node execution, оразмерена точно за `ExecuteNodeJob`).

- **Стандартизирано рутиране (§B1) — без двусмислие:**
  - **`flows`** = **САМО** node execution → `ExecuteNodeJob`. Нищо org не влиза тук.
  - **`org`** (нов queue, `supervisor-org`) = **ВСИЧКИ дълги org LLM jobs**: org planning
    (`ProposeOrganizationJob`), interview (`OrgInterviewTurnJob`), research (`ResearchBusinessJob`),
    `DirectorTickJob` / review (`OrgReviewJob`), avatar (`GenerateMemberAvatarJob`),
    member chat (`MemberChatTurnJob`), `ScheduledTaskJob`. **`DirectorTickJob` е на `org`, НЕ на
    `flows`** — премахваме противоречието „DirectorTickJob на flows" от по-долните фази (§4.2).
    Самата генерация на flow върви през `AgentGenerationLauncher` (фонов artisan процес, не
    Horizon job) — без промяна.
  - `default` остава за инфраструктурни/token-poll status ъпдейти, не за org LLM работа.
- **Нов supervisor (`config/horizon.php`):** добави **`supervisor-org`** с `queue=['org']`,
  `timeout` ≥ 1200 (като `flows`), `maxProcesses` 1–2, `tries=1` (org services правят собствени
  retry-та — същият принцип като `supervisor-flows`). Регистрирай го в `defaults` +
  `environments.local` + `environments.production`. Добави и `'redis:org' => 60` в `waits`.
- **`org` heartbeat (§B1).** Днес `QueueHeartbeat` пази само `flows` (`FLOWS_KEY`/`flowsAlive()`,
  обновяван от Horizon `SupervisorLooped` в AppServiceProvider). Добави симетричен **`org`
  heartbeat** (`ORG_KEY`/`orgAlive()`/`markOrgAlive()`) и го обновявай от същия `SupervisorLooped`
  hook; контролерите, които диспечират org работа, проверяват `QueueHeartbeat::orgAlive()` (както
  `FlowRunController` проверява `flowsAlive()` преди run) и показват приятелски „Horizon не върви"
  вместо тиха задръжка.

> **Auth бележка (преходна).** Клиентската preview аутентикация (`ClientAuth` — `session('client_company_id')`,
> без парола) **остава засега**: плащанията са админ-симулирани, а действията са draft-first
> (§13). **Реален auth е ПРЕДУСЛОВИЕ** за Stripe фазата (Фаза 6) и за масов автономен `act` —
> не за тази foundation фаза.

**Критерии за приемане (Фаза 0.5):**
- `credit_reservations` + `org_proposals` съществуват; `credit_ledger` има `reservation_id` +
  `type` + **operation-scoped** `idempotency_key`; `llm_requests` носи
  `context_type`/`subject`/**`reservation_id`** колоните; миграциите минават.
- `BillableUnit` връща кредити за LLM request (token формула) и за flat инструмент (config цена);
  чете САМО персистирани `llm_requests` (филтрирани по `reservation_id`).
- `CreditMeterService::reserve($companyId, $contextType, $subject, $estimate)` връща
  `CreditReservation` и сваля баланса атомарно (`balance >= ?`); два паралелни/повторени `reserve`
  със същия RESERVE ключ дебитират **веднъж**; недостатъчен баланс → блок.
- `settle(CreditReservation, actual)` реконсилира реалния разход срещу резерва (delta debit или
  refund), записва `spent_credits`, маркира `settled`; идемпотентен по operation-scoped ключовете.
  `settle` работи за ВСИЧКИ контексти (chat/research/avatar/interview/generation/task_run), не само
  за `FlowRun`.
- `GraphFlowExecutor::finalize()` атрибутира FinalComposer request към резервацията на run-а (не
  изтича); `fail()`/cancel/human-approval **REJECT** settle-ват реално похарченото + refund-ват
  остатъка (failed/rejected/cancelled run не задържа резервирани кредити).
- Ръчен run при нулев баланс е блокиран (402 + upsell); scheduled tick го пропуска + известие;
  **генерация** при нулев баланс е блокирана; никой не дебитира.
- Админ „Зареди кредити + план" вдига баланса (ledger `type=topup`) и сетва плана; **никакъв
  Stripe код**.
- Org-flow run инжектира ПЕРСОНА БЛОК в content възлите, но НЕ в `qa_verifier`/`human_approval`/
  трансформъри; flow без `org_member_id` е непроменен.
- Задача без `flow_id` при run → `generating` с `run_after_generate=true` (асинхронно); пуска се
  при `ready` само ако има баланс; HTTP заявката не блокира.
- `ApprovalService` е единственият resume-after-approval boundary; `FlowRunController::approval` и
  `DecisionBoxService` го викат (никакъв controller→controller call).
- Кутията показва `org_proposals(pending)` + паузиран `human_approval` run; одобрение на org
  предложение върху **остаряла** `base_org_version_id` → `superseded` + ре-ревю; одобрение на
  паузиран run продължава run-а през `ApprovalService`.
- `supervisor-org` обработва `org` queue; `QueueHeartbeat::orgAlive()` работи; нито един org LLM
  job не е на `flows`/`default`.

---

## Фаза 1 — Casting на Управителя + Intake + Проучване + Интервю → Бизнес профил

**Цел:** собственикът „наема" Управител (персона), системата проучва бизнеса и провежда
интервю, докато си състави **ясна представа**; резултатът е `business_profiles` запис,
готов за дизайн.

### 1.1 Услуги (нови) + преизползване

- **`PersonaService`** (`app/Services/Org/PersonaService.php`) — двигателят на персоните (§5):
  - `seedTraitsFromDemographics(?int $age, ?string $gender, ?string $background): array` — демография → стартови черти (24 г. → +креативност/+риск/−формалност; 59 г. → +прецизност/−риск). Връща `traits` 0–100. Само **подсказва** (§5.3).
  - `compileSystemPrompt(Persona $persona): string` — черти + био + тон → характер/ценности/стил блок за system prompt (НЕ пипа компетентност).
  - `deriveKnobs(Persona $persona): array` — черти → runtime: `temperature` (от креативност/прецизност), `star_tier` **подсказка** (прецизност → по-високо за критични — само сигнал, който `finalizeOrganization` ползва при сетването на `org_members.default_star_tier`; авторитетното ниво е на члена, не тук), `tool_bias`, `parallelism` (от темпо), `approval_aggressiveness` (от риск/автономност). Записва се в `personas.derived_knobs`.
  - `attachTo(OrgMember $member, array $fields): Persona` — създава/обновява персоната на члена (upsert по `org_member_id`; промените се логват); ако `config('organization.persona.portraits')` и демографията (`gender`/`age`/`ethnicity`) е нова/променена → нулира `avatar_status='pending'` и диспечира `GenerateMemberAvatarJob` (виж `AvatarService`).
  - `archetypes(string $vertical, string $role): Collection` — кандидати от `persona_archetypes` (embedding-match по вертикал/роля) за casting.
- **`AvatarService`** (`app/Services/Org/AvatarService.php`) — портретният аватар на члена (§5.1 „самоличност (козметика)"; **изцяло козметичен — не влияе на поведението**, решение 7 / §5.3). **Преизползва `ComfyUIService`, не го преимплементира.** Аватарите са на **измислени персони** (без прилика с реални хора).
  - `portraitPrompt(Persona $p): string` — **детерминистичен** английски SD портретен промпт **САМО от демографията** `gender/age/ethnicity` (по избор неутрален `look`) — **БЕЗ `role`/`tone`** (§B4): промптът трябва да зависи от точно същите полета, които задействат регенерация (§5.3/§7.5), иначе портретът би „трябвало" да се сменя при промяна на титла/тон, но seed-ът и regen-тригерът не реагират на тях → разсинхрон. Без LLM (евтино/стабилно; фотореалистичен стил като `ImagePromptAgent::buildSdSystemPrompt`), напр.:
    `"professional corporate headshot portrait of a {age}-year-old {ethnicity} {gender}, neutral confident expression, soft studio lighting, neutral background, photorealistic, sharp focus, 4K, DSLR portrait"`. Празните полета се пропускат елегантно. Записва се в `personas.avatar_prompt`. (Изражението е фиксирано/неутрално — не идва от `tone`.)
  - `seedFor(Persona $p): int` — **стабилен seed per член** = детерминистичен хеш на
    `org_member_id` + **демографския подпис** (`gender|age|ethnicity`), за да е портретът
    стабилен при ре-рендер, но **да се смени щом демографията се смени** (тогава PersonaService
    нулира и seed-ът се преизчислява от новите полета). Записва се в `personas.avatar_seed`.
  - `generateFor(Persona $p): void` — оркестрира през `ComfyUIService`: ако `! isAvailable()` → `avatar_status='pending'` (UI fallback инициали) и изход. Иначе **строи workflow-а с детерминистични overrides** (виж бележката за `ComfyUIService` по-долу): `buildWorkflow(portraitPrompt(...), ['seed' => seedFor($p), 'checkpoint' => config('services.comfyui.portrait_checkpoint'), 'negative' => config('services.comfyui.portrait_negative')])` → `generate(...)` → `getResult(...)`. При успех **копира** резултата (от `generated/{promptId}.png`) към **стабилен път** `avatars/member_{org_member_id}.png` (Storage `public`), за да е URL-ът постоянен между ре-рендери; сетва `avatar_url` (= `Storage::disk('public')->url(...)`), `avatar_seed`, `avatar_meta` (checkpoint/prompt_id/размери) и `avatar_status='ready'`; денормализира `avatar_url` и в `org_members`. При timeout/грешка → `avatar_status='failed'`. Идемпотентен (повторно извикване със същата демография презаписва същия стабилен файл).
  - **Гаранция (планерът предлага, кодът гарантира):** демографските стойности минават през whitelist/санитизация преди да влязат в промпта; never trust LLM/потребителски вход в SD промпта.
  - > **Техническа поправка — `ComfyUIService::buildWorkflow` overrides.** Днешният
    > `buildWorkflow(string $positivePrompt)` **рандомизира seed-а на всяко повикване**
    > (`rand(1, 999_999_999)`) и **няма** начин да подаде portrait checkpoint / negative
    > override — затова „фиксиран seed" е невъзможен със сегашната сигнатура и портретът би се
    > сменял при всеки ре-рендер. Поправка: разшири
    > **`buildWorkflow(string $positivePrompt, array $overrides = [])`** — когато
    > `$overrides['seed']` е подаден, го инжектира **детерминистично** (вместо `rand`); когато
    > `$overrides['checkpoint']`/`$overrides['negative']` са подадени, ги ползва вместо
    > конструкторните `$this->checkpoint`/`$this->negativePrompt`. Празни overrides → пълна
    > обратна съвместимост с image-агентите (текущото поведение, вкл. рандом seed). **Без
    > legacy fallback** в org пътя — `AvatarService` винаги подава seed/checkpoint/negative.
    > (Алтернатива, ако не пипаме `ComfyUIService`: `AvatarService` редактира декодирания
    > workflow масив детерминистично преди `generate()` — но overrides сигнатурата е по-чистото
    > решение и се преизползва.) Това е единствената допустима промяна по съществуващ
    > runtime service в org-слоя; описана е и в Приложение А.
  - **Retry на висящи/провалени аватари (§B4).** При спрян ComfyUI `generateFor` оставя
    `avatar_status='pending'`; при timeout/грешка — `'failed'`. Тези НЕ остават залепнали:
    добави **`redispatchPending(?Company $company = null): int`** — намира членове с
    `avatar_status ∈ {pending, failed}` и (ако `ComfyUIService::isAvailable()`) пуска нов
    `GenerateMemberAvatarJob` за тях. Викай го (а) **периодично** (лек schedule, напр. на
    `org:review` тика или отделен лек cron) и (б) **при следващо отваряне на члена**
    (`MemberController@show` — best-effort, неблокиращо: ако аватарът виси и ComfyUI вече е
    наличен, диспечни регенерация). Идемпотентно (стабилен файл + demography-seed), безопасно за
    повторно викане; без баланс/без ComfyUI → no-op (остава fallback инициали).
- **`BusinessProfilerService`** (`app/Services/Org/BusinessProfilerService.php`) — Фаза 2 на потока на Управителя (§7):
  - `research(Company $company): array` — оркестрира: сайт/уеб през research агенти (`DeepResearcherAgent`/`ResearcherAgent`/`MultiResearcherAgent`) + `CrawlService` + `BraveSearchService::search()`, ревюта през `GooglePlacesService`, „чести практики в бранша". Връща структуриран синтез; пише в `business_profiles.research`. **Тежката работа е през съществуващите services/агенти** (никакви директни HTTP извиквания тук).
  - `analyze(Company $company): string` — синтезен LLM ход (през `GeneratorService`) над research → ситуационен анализ + `pain_points`. Резултатът — пинван факт-блок (преизползва `KnowledgeService::ownProfileBlock()` като контекст).
- **`OrgInterviewService`** (`app/Services/Org/OrgInterviewService.php`) — **директно по модела на `ClientFlowWizardService`** (същият parse/validate/JSON-схема патърн, не нов):
  - `turn(BusinessProfile $profile, string $userInput, ?callable $onStage): array` — `AgentLoop` ход с read-only инструменти (`knowledge_search`, `web_search`); връща `{phase: interview|ready, reply, question|null, answers_patch, situational_recap, cost_usd}`. Спира при `max_questions` или когато намерение/болки/приоритети са ясни (`forceReady`, точно като wizard-а).
  - Системният промпт стъпва на `situational_analysis` + `research` — пита за болките от анализа (напр. „пълнота на класовете vs задържане?", §23 пример).

### 1.2 Routes (клиентски, нова `org` група в `routes/client.php`)

```php
Route::middleware('client_auth')->prefix('org')->group(function () {
    // Casting + onboarding
    Route::get('start',                 [Client\Org\OnboardingController::class, 'start'])->name('client.org.start');
    Route::get('casting',               [Client\Org\OnboardingController::class, 'casting'])->name('client.org.casting');
    Route::post('casting',              [Client\Org\OnboardingController::class, 'hireManager'])->name('client.org.casting.hire');
    Route::post('research/start',       [Client\Org\OnboardingController::class, 'startResearch'])->name('client.org.research.start');
    Route::get('research/status/{token}',[Client\Org\OnboardingController::class, 'researchStatus'])->name('client.org.research.status');
    // Интервю (чат, token-poll — като wizard-а)
    Route::get('interview',             [Client\Org\InterviewController::class, 'show'])->name('client.org.interview');
    Route::post('interview/send',       [Client\Org\InterviewController::class, 'send'])->name('client.org.interview.send');
    Route::get('interview/status/{token}',[Client\Org\InterviewController::class, 'status'])->name('client.org.interview.status');
});
```

### 1.3 Jobs

- `app/Jobs/Org/ResearchBusinessJob.php` (**`org` queue** — дълготрайно, §0.5.8; НЕ `default`) — вика `BusinessProfilerService::research()` + `analyze()`, ъпдейтва `org_research_{token}` cache (`{status, stage, result}`); огледало на token-poll патърна.
- `app/Jobs/Org/OrgInterviewTurnJob.php` (**`org` queue**, §B1 — интервю ход = LLM работа) — **огледало на `AssistantTurnJob`**: чете профил+вход, вика `OrgInterviewService::turn` (billable контекст `interview`, reserve/settle), ъпдейтва кеша, записва `member`-стил съобщения (тук — във временна интервю опашка на профила).
- `app/Jobs/Org/GenerateMemberAvatarJob.php` (**`org` queue**, retries — ComfyUI рендерът е бавен, §0.5.8) — вика `AvatarService::generateFor(Persona)`; **идемпотентен** (стабилен файл + стабилен demografия-обвързан seed). При спрян ComfyUI оставя `avatar_status='pending'` за по-късен retry. Това е портретният аватар на члена (преизползва `ComfyUIService` с overrides — §1.1).

### 1.4 Контролери + Views

- `Client\Org\OnboardingController` — `casting` (избор/генерация на персона на Управителя от архетипи), `hireManager` (upsert-ва `OrgMember` с `kind=manager` + `Persona` през `PersonaService::attachTo`), `startResearch`/`researchStatus`.
- `Client\Org\InterviewController` — `show`/`send`/`status` (token-poll).
- Views: `client/org/casting.blade.php` (карти-кандидати с портрет/черти-барове + „Наеми" + „Създай свой"), `client/org/research.blade.php` (прогрес на проучването), `client/org/interview.blade.php` (чат + форма-въпрос радио/checkbox + „Друго", точно като `client/flows/wizard.blade.php`).
- Layout: преизползвай `layouts/client.blade.php`; добави в навигацията линк „Моята организация" (`client.org.*`).

### 1.5 Персона двигател в тази фаза

- Casting: `seedTraitsFromDemographics` дава стартови черти от избраната демография; човек ги override-ва с барове (Alpine). Каскадата (Управителят → екип) идва във Фаза 2; тук само Управителят получава персона.

**Критерии за приемане (Фаза 1):**
- „Наеми Управител" създава `org_members` (`kind=manager`) + `personas` запис (на `org_member_id`); портретен аватар, съответстващ на демографията, се появява (ако ComfyUI е вдигнат) — иначе `avatar_status='pending'` + инициали.
- „Стартирай проучване" пуска job, прогресът тече, `business_profiles.research` + `situational_analysis` се попълват.
- Интервюто задава по един въпрос с готови опции; спира при „ясна представа"; `interview_answers` се натрупват; `status` става `ready`.
- 20- vs 60-годишен Управител звучи видимо различно в `reply` (черти → тон), но не и по-некомпетентен.

---

## Фаза 2 — Дизайн на екипа с персони + Skill Tree/Roster UI → материализация

**Цел:** Управителят **предлага** организация (Директори/Асистенти **с персони** + стартов
skill tree) върху `business_profiles`, човек я ревюира/доуточнява и одобрява; одобрението
записва нов `org_version` и неговите членове.

### 2.1 `OrgPlannerService` (ядрото на тази фаза)

`app/Services/Org/OrgPlannerService.php` — мета-планерът. **Планерът предлага, кодът
гарантира:** LLM дизайнът минава през детерминистична хардинг стъпка (огледало на
`AgentGeneratorService::finalizePlannedAgents`).

- `proposeOrganization(Company $company, ?callable $onProgress = null, ?string $logToken = null): array`
  — три-фазно (като `FlowPlannerService::plan`): (1) избор на org-blueprint прайър
  (`OrgBlueprintLibraryService::bestMatch`), (2) дизайн на директори/асистенти/задачи +
  **персони** (структуриран JSON през `GeneratorService`, ниво `config('organization.manager.level')`),
  (3) критика/репар. Логва всяка фаза в `agent_generation_logs` (cost през `LlmUsage`).
  Връща нормализиран масив `{directors:[{...persona...}], assistants:[...], tasks:[...], skill_tree:[...], quests:[...]}`.
- `finalizeOrganization(array $proposed, ?OrgVersion $current = null): array` — **гаранциите**:
  валиден директор→асистент граф (без сираци), legal `domain`/`act_mode`/`approval_policy`,
  всяка персона минава през `PersonaService::deriveKnobs`, dedupe. **Нива:** сетва разумен
  `default_star_tier` per член (по роля/работа — рангът на члена; ∈ `ModelLevel`, cap по
  `plans.max_star_tier`); задачен `star_tier` се задава САМО като явен override, когато
  умишлено се различава от нивото на члена — иначе остава `null` (= наследява). Никога не
  вярва на LLM за структурата.
  - **Код-притежавани идентичностни ключове (НЕ вярвай на LLM `key`).** При re-plan/нова
    версия `$current` (активната версия) подава **съществуващите членове като КАНДИДАТИ** с
    техния immutable `id` + стабилен `key`; промптът кара Управителя да ги реферира **по `id`**
    (keep / modify / retire), а не да измисля ключове. За **НОВИ** членове `key`-ът се алокира
    от **детерминистичен код-алокатор** (`OrgMember::allocateKey(company, kind, displayName)` —
    slug + суфикс за уникалност по `(company, key)`), НЕ от LLM. Така идентичностната
    реконсилация в `materialize` е **upsert по immutable `id`** (за реферираните) или нов ред с
    код-алокиран `key` (за новите) — никога fuzzy match по LLM-измислен `key`.
- `materialize(Company $company, array $finalized, User $approver): OrgVersion` — (след
  одобрение) **хибридът в действие**:
  1. **Идентичностна реконсилация по immutable `id` (НЕ по fuzzy LLM `key`)**: членовете,
     които Управителят реферира по `id` (keep/modify), се **upsert-ват по този `id`** —
     запазват идентичност/персона/чатове/задачи; **новите** членове получават
     **код-алокиран** `key` (`OrgMember::allocateKey`, виж `finalizeOrganization`) и се
     създават (`status=active`); член от активната версия, който Управителят е маркирал
     `retire` (или липсва в новата организация), → `status=retired` (+ `retired_at`). Unique
     `(company, key)` остава предпазен инвариант, но **съвпадението е по `id`**, не по
     прегенериран ключ.
  2. Създай нов `OrgVersion(status=approved)` + per-version `Director`/`Assistant`
     **плейсмънт** редове за него, всеки сочещ съответния `OrgMember`.
  3. Персона = **upsert върху `OrgMember`** (`PersonaService::attachTo`); не се пресъздава —
     запазва се през версиите.
  4. **Портретни аватари (batch):** за всеки **нов или с променена демография** член →
     `GenerateMemberAvatarJob` (диспечнат от `attachTo`, събран в `Bus::batch` за паралелизъм
     през Horizon); съществуващите членове с непроменена демография запазват аватара си.
  5. Сетва `companies.active_org_version_id`; записва `org_events`: `hire` за новите членове,
     `reassign`/`mandate_change` за променените плейсмънти, `fire` за пенсионираните.
  **Не** генерира flows тук — това е Фаза 3 (lazy/by-request).

### 2.2 `OrgBlueprintLibraryService` (паметта на Управителя — като `PlanLibraryService`)

`app/Services/Org/OrgBlueprintLibraryService.php`:
- `bestMatch(Company $company, int $k = 3): array` — embedding similarity (`EmbeddingService`) на бизнес профила срещу `org_blueprints` → топ-k few-shot прайъри за дизайна.
- `snapshot(OrgVersion $version): OrgBlueprint` — одобрена версия → blueprint (учеща библиотека).
- `markProven(OrgVersion $version): void` — след първа успешна задача на версията (викано от Фаза 3/7), `proven=true` (огледало на `plan_library` proven логиката).

### 2.3 Персона двигател — каскадата (§5.4)

- В `proposeOrganization`: персоната на Управителя (`PersonaService::compileSystemPrompt`) се инжектира като контекст → дизайнът отразява характера му (млад визионер → по-смел маркетинг екип). Това е **подсказка в промпта**, а кодът пак нормализира.
- Всеки предложен член носи `traits` (seed-нати от демография + ролята); `finalizeOrganization` ги прекарва през `deriveKnobs`.
- **QA възли persona-неутрални:** при материализация задачите получават стандартния `qa_verifier`/`bg_text_corrector` от `AgentGeneratorService` — персоната на члена НЕ влияе на тях (§5.3 предпазител).

### 2.4 „Един граф, няколко лещи" (§6) — UI

Едни и същи `director`/`assistant`/`task` записи, три изгледа (Alpine табове над общ JSON
от `OrgGraphController@graph`):
- **Roster (Екип)** — карти на герои (портретен аватар с **fallback цветни инициали** докато е `pending`/`failed`, име, титла, мини стат-барове, статус, текущ фокус) + линии „кой кого управлява / на кого отчита".
- **Skill Tree (Способности)** — клон = Директор, възел = Асистент/умение; **звезди ★→★★★★★ = `ModelLevel`** на задачата (= `effectiveStarTier` — наследеното от члена, освен ако е override-нато); възлите с явен per-task override носят **различен индикатор** от наследените (напр. „пинната" звезда vs приглушена/наследена); отключено/заключено; куестове от Управителя (приоритизирани от `pain_points`).
- **Карта на героя** — портретен аватар (fallback инициали), био, статове (радар/барове), текущ куест, последна активност, релации, бутони „Чат / Възложи / Преназначи / Освободи" (последните → Кутия за решения) + **„Регенерирай аватар"** (диспечира `GenerateMemberAvatarJob`). **Контрол за ниво на члена** (`default_star_tier`, low→god — „повишение/понижение") — смяната **реценообразува всичките му задачи без явен override** (preview на кредитите); per-task контрол със състояние **„(наследява)"** по подразбиране + явен override бутон. За **Директор** допълнително: бутон **„повиши целия отдел"** (прилага нивото на директора към асистентите без ръчен override → каскадира към задачите им).

### 2.5 Routes / Контролери / Views

```php
Route::middleware('client_auth')->prefix('org')->group(function () {
    Route::post('design/propose',      [Client\Org\DesignController::class, 'propose'])->name('client.org.design.propose');
    Route::get('design/status/{token}',[Client\Org\DesignController::class, 'status'])->name('client.org.design.status');
    Route::get('design/review',        [Client\Org\DesignController::class, 'review'])->name('client.org.design.review');
    Route::post('design/approve',      [Client\Org\DesignController::class, 'approve'])->name('client.org.design.approve');
    // Персона Q&A авторство (доуточняване — преизползва интервю патърна)
    Route::post('personas/{persona}/refine', [Client\Org\PersonaController::class, 'refine'])->name('client.org.personas.refine');
    Route::put('personas/{persona}',   [Client\Org\PersonaController::class, 'update'])->name('client.org.personas.update');
    // Изгледи на графа
    Route::get('roster',               [Client\Org\OrgGraphController::class, 'roster'])->name('client.org.roster');
    Route::get('skill-tree',           [Client\Org\OrgGraphController::class, 'skillTree'])->name('client.org.skill-tree');
    Route::get('graph.json',           [Client\Org\OrgGraphController::class, 'graph'])->name('client.org.graph');
    Route::get('members/{member}',     [Client\Org\MemberController::class, 'show'])->name('client.org.member');  // {member} = OrgMember
    Route::post('members/{member}/avatar/regenerate', [Client\Org\MemberController::class, 'regenerateAvatar'])->name('client.org.member.avatar'); // ръчно → GenerateMemberAvatarJob
    Route::post('members/{member}/tier',     [Client\Org\MemberController::class, 'setTier'])->name('client.org.member.tier');        // повишение/понижение → setDefaultStarTier (рецена на задачите без override)
    Route::post('members/{member}/promote-department', [Client\Org\MemberController::class, 'promoteDepartment'])->name('client.org.member.promote-dept'); // само директор → applyTierToDepartment
    Route::post('tasks/{task}/tier',         [Client\Org\AssistantTaskController::class, 'setTier'])->name('client.org.tasks.tier'); // per-task override (или nullиране = „наследява")
});
```

- Job: `app/Jobs/Org/ProposeOrganizationJob.php` (**`org` queue**, token-poll — дълъг три-фазен дизайн, §0.5.8) — вика `OrgPlannerService::proposeOrganization` + `finalizeOrganization`, кешира предложението под `org_design_{token}` за ревю преди одобрение.
- Views: `client/org/design-review.blade.php` (предложеният екип + skill tree, редактируем; „Одобри"), `client/org/roster.blade.php`, `client/org/skill-tree.blade.php`, `client/org/member.blade.php` (Карта на героя; бутон „Регенерирай аватар"), `client/org/_persona-card.blade.php` (компонент — портретен аватар + fallback инициали).

**Критерии за приемане (Фаза 2):**
- „Проектирай екип" връща Директори+Асистенти с персони (име/възраст/черти/тон) + skill tree с куестове, обосновани от `pain_points`.
- Редакция на персона (барове / Q&A refine / създаване нов член) работи; промените оцеляват.
- „Одобри" реконсилира `org_members` **по immutable `id`** (нови → код-алокиран `key`), създава `org_version(approved)` + плейсмънт редове + персони (на члена) + `org_events`; `companies.active_org_version_id` сочи новата версия. Повторно одобрение преизползва съществуващите членове **по `id`** (персона/чат/задачи/аватар оцеляват) — не зависи от LLM-измислен `key`.
- При вдигнат ComfyUI материализацията генерира **фотореалистични портрети, съответстващи на демографията** (pol/възраст/етнос/роля), видими в Roster/Карта; при спрян ComfyUI — цветни инициали + по-късен retry. „Регенерирай аватар" пресъздава портрета.
- Roster, Skill Tree и Карта на героя са три изгледа на **същите** записи; звездите отразяват `star_tier`.

---

## Фаза 3 — Задачи = flows; генериране per асистент; ръчно пускане; Текущ поток

**Цел:** всяка `assistant_task` става реален Flow през съществуващия планер; асистентите
пускат задачи ръчно; „Текущ поток" показва живите вълни.

### 3.1 Материализация на задача → Flow (lazy)

- `AssistantTaskController@generate(AssistantTask $task)` — създава `Flow` за фирмата (`name`=task.title, `description`=task.description, `status='draft'`), после **`AgentGenerationLauncher::launch(companyId, flowId, title, description, $task->effectiveStarTier()->value, [], minimalQa: true, persist: true, assistantTaskId: $task->id)`** (сигнатурата на клиентския wizard, разширена с `assistant_task_id` — §0.5.6; подава се **effective** нивото, НЕ суровият `star_tier`). Записва `gen_token` + `status='generating'`; **callback-ът на `GenerateAgentsCommand`** (§0.5.6) при край връзва `flow_id` към задачата и сетва `status='ready'` (при провал `failed`). **Никаква дублирана генерационна логика** — минава през launcher-а; **никакво синхронно чакане** — статусът тече през state machine-а.
- Нивото на задачата (`effectiveStarTier()` = override на задачата ИНАЧЕ `default_star_tier` на члена, cap по плана — §6.1) се подава като `$level` → `ModelRouterService::assign` пинва моделите според нивото. Преизползва се целият builder-relevel/cost механизъм. **При пускане:** ако задачата е `tier_stale` (§6.1) → re-pin server-side към `effectiveStarTier()` и `tier_stale=false` **преди** изпълнение (lazy re-pin).

### 3.2 Ръчно пускане + Текущ поток

- `AssistantTaskController@run(AssistantTask $task)` — guard по фирма; **lazy генерация (асинхронна, §0.5.6):** ако задачата **няма `flow_id`** → сетва `run_after_generate=true`, резервира `generation` (§A3) и диспечира `@generate` (§3.1) и връща „генерира се" — run-ът се пуска от генерационния callback при `status='ready'` (ако има баланс), **без синхронно чакане** в HTTP заявката. Когато е `ready`:
  1. **Wallet гейт (§0.5.3):** `$reservation = CreditMeterService::reserve(company, 'task_run', $flowRun, BillableUnit::estimate($task))` — при недостиг → 402 + upsell, run-ът НЕ стартира.
  2. Създава `FlowRun(status='pending', triggered_by='manual')` с **org-контекст** `context['assistant_task_id']=$task->id` + `context['org_member_id']` на члена-собственик (за persona injection — §0.5.5) + **`context['credit_reservation_id']=$reservation->id`** (за settle + за `LlmContext` атрибуцията на `finalize()` — §A6).
  3. Вика `GraphFlowExecutor::run($flow,'manual',$run)`, връща `{run_id, poll_url}`.
  4. **СЛЕД завършване / на всеки терминален изход** (в `GraphFlowExecutor::finalize()` за success и `fail()`/cancel/REJECT за провал — §A4/§6.3): `CreditMeterService::settle($reservation, CreditMeterService::actualFor($reservation))` реконсилира реалния разход срещу резерва (delta debit или refund на остатъка). (Огледало на `Client\FlowRunController::run`, плюс org-контекст + wallet.)
- **Текущ поток** — преизползва клиентския run-progress (`Client\FlowRunController::progress`) на ниво задача; org изгледът показва кои асистенти имат активни runs (вълни/статуси) — Леща 3 от §6.

### 3.3 Routes / Views

```php
Route::middleware('client_auth')->prefix('org')->group(function () {
    Route::post('tasks/{task}/generate', [Client\Org\AssistantTaskController::class, 'generate'])->name('client.org.tasks.generate');
    Route::get('tasks/{task}/gen-status/{token}', [Client\Org\AssistantTaskController::class, 'genStatus'])->name('client.org.tasks.gen-status');
    Route::post('tasks/{task}/run',       [Client\Org\AssistantTaskController::class, 'run'])->name('client.org.tasks.run');
    Route::get('quests',                  [Client\Org\QuestController::class, 'index'])->name('client.org.quests');   // Дневник на куестове
    Route::get('live',                    [Client\Org\OrgGraphController::class, 'live'])->name('client.org.live');    // Текущ поток
});
```

- Views: `client/org/quests.blade.php` (задачите като куестове: статус/KPI/изпълнител/прогрес), `client/org/live.blade.php` (live pipeline). Картата на героя получава активни „Възложи" / „Изпълни" бутони.

**Критерии за приемане (Фаза 3):**
- „Генерирай" на задача създава Flow през launcher-а на **effective** нивото (`effectiveStarTier()` — наследено от члена, освен ако е override-нато; cap по плана); popup с прогрес; задачата става `ready` с `flow_id`.
- **Повишаване/понижаване на член** (смяна на `default_star_tier`) мени effective tier на всичките му задачи **без явен override**; задача с override остава фиксирана.
- Задача с явен per-task override се пуска на override нивото независимо от нивото на члена; нулиране на override → задачата отново наследява.
- „Изпълни" **резервира кредити** (§0.5.3 — при недостиг 402+upsell), пуска `FlowRun` с org-контекст (`org_member_id` за persona injection), прогресът тече, резултатът се отваря (преизползва клиентския Резултат екран); след завършване `settle` реконсилира разхода.
- Текущ поток показва активните runs по асистент.
- **MVP-демо е завършено тук** (Фази 0–3): наемаш Управител → проучен → интервюиран → екип с персони и skill tree → одобрен → една задача дава резултат.

---

## Фаза 4 — Директор-агент (рутиране, ревю, отчети, препоръки) + график + Кутия за решения + чат с членове

**Цел:** Директорите стават реални разсъждаващи supervisor-агенти (§8); добавяме график,
централна Кутия за решения и персона-консистентен чат с всеки член.

### 4.1 `DirectorAgentService` (§8)

`app/Services/Org/DirectorAgentService.php` — Директорът като агент (персоната му **обагря**
преценката, не компетентността):
- `tick(Director $director, string $trigger = 'scheduled'): array` — цикълът от §8:
  1. чете състоянието (асистенти, последни `node_runs`, `qa_score` тренд, цели, празноти);
  2. планира **през персоната си** (`PersonaService::compileSystemPrompt` в system prompt; `AgentLoop` с read-only инструменти) — кои одобрени задачи да пусне, в какъв ред;
  3. ако промяната е структурна → `proposeDecision` (Кутия), иначе пуска одобрените задачи (`GraphFlowExecutor`) — **ако одобрена задача още няма `flow_id`, директор-tick-ът я авто-генерира (§3.1) преди run, не я прескача/блокира** (lazy/by-request, същото поведение като ръчния run в §3.2);
  4. ревюира изходите (persona-неутрален QA);
  5. отчита към Управителя + препоръка (`org_events` тип `review`).
  Връща `{ran:[task_ids], proposals:[decision_ids], report}`.
- `proposeDecision(Company $company, string $type, array $payload, string $rationale): OrgProposal` — структурно предложение → durable `org_proposals(pending)` ред (с `base_org_version_id` = активната версия — §A7) → влиза в Кутията. Изисква човешко одобрение (§13).

### 4.2 График

- `app/Console/Commands/Org/RunDirectorTicks.php` (`org:director-ticks`) — образец `RunScheduledFlows`; обхожда активните Директори по техния cron и диспечира `DirectorTickJob`. Регистрирай в `routes/console.php` schedule.
- `app/Jobs/Org/DirectorTickJob.php` (**`org` queue**, §0.5.8/§B1 — НЕ `flows`) — `DirectorAgentService::tick`.
- `app/Jobs/Org/ScheduledTaskJob.php` (**`org` queue**, §0.5.8/§B1) — `assistant_tasks.trigger='scheduled'` по `schedule` (cron); **преди старт резервира кредити** (§0.5.3 `task_run` — при недостиг пропуска + известие, не фейлва тика), пуска `GraphFlowExecutor` директно за `draft` задачи; за `act` → политика на одобрение (Фаза 5) **И** act hard gate (§B2 — в preview само чернова).

### 4.3 Кутия за решения (§13)

- `app/Services/Org/DecisionBoxService.php` — **адаптер/агрегатор, дефиниран в §0.5.7** (НЕ
  нова одобрителна машина). Не дублира одобренията — **агрегира** (а) `org_proposals(pending)`
  (durable mutable решения, §A7; `org_events` остава отделен одит) и (б) паузираните
  `human_approval` `FlowRun`-ове:
  - `pending(Company $company): Collection` — обединява двата източника в общ списък „чакащи".
  - `approve(...)` / `reject(...)` — за **org предложение** ъпдейтва `org_proposals.status` +
    записва `org_event` (`approval`): org-промяна → нов `org_version` (наемане/уволнение/мандат);
    действие → пуска `act` (Фаза 5, **под act hard gate §B2**). **Optimistic concurrency:** ако
    `base_org_version_id` ≠ активната версия → `superseded` + ре-ревю (§A7). За **паузиран run**
    → **`ApprovalService::settle(...)`** (единният boundary от §0.5.7) — **никакъв
    controller→controller call**; resume-логиката е една, в service-а.
- Route: `org/decisions` (index/approve/reject); View `client/org/decisions.blade.php` — едно място за всички чакащи (§16 „Кутия за решения").

### 4.4 Чат с всеки член (§12) — преизползва token-poll/AgentLoop

- `app/Services/Org/MemberChatService.php` — **по модела на `BuilderAssistantService`**:
  - `turn(MemberChat $chat, MemberMessage $userMessage, ?callable $onStage): array` — чатът виси на `OrgMember` (преживява версиите); system prompt = персоната на члена (`PersonaService::compileSystemPrompt` от `$member->persona`) + **scope** според `kind` + текущия плейсмънт (Управител=стратегия/целия профил; Директор=домейн/неговите асистенти+runs; Асистент=задачите му+последни runs) + релевантно знание (`KnowledgeService::search`). Read-only инструменти. Може да **предложи действие** → създава `org_proposals(pending)` ред (§A7) за Кутията. Чатът е billable контекст (`member_chat`, §0.5.3) — reserve/settle около хода. Връща `{reply, proposal|null, cost_usd}`.
- `app/Jobs/Org/MemberChatTurnJob.php` (**`org` queue**, §B1 — НЕ `default`) — **огледало на `AssistantTurnJob`**: записва assistant `MemberMessage`, ъпдейтва `member_chat_{token}` cache за поллинг.
- Routes: `org/members/{member}/chat` (show — `{member}` = OrgMember), `org/chat/send`, `org/chat/status/{token}`, `org/chat/{chat}/history`.
- View: `client/org/chat.blade.php` (персона-консистентен чат с **портретния аватар на члена** в хедъра/балончетата, fallback инициали; „предложи действие" бутон → Кутия). Картата на героя получава работещ „Чат".

**Критерии за приемане (Фаза 4):**
- Ръчен „tick" на Директор чете състояние, пуска одобрена задача, ревюира, отчита; структурно предложение влиза в Кутията.
- Schedule пуска `draft` задачите по техния cron (с вдигнат Horizon).
- Кутия за решения показва чакащите; „Одобри" наема/изпълнява; `org_events` се записват.
- Чат с Управител/Директор/Асистент е в техния тон, scope-нат; „предложи действие" се появява в Кутията.

---

## Фаза 5 — `act` задачи през конектори + интеграции рейл + политики на одобрение

**Цел:** задачите с `act_mode ∈ {act, mixed}` извършват реални действия през MCP
конекторите със степенувано одобрение. **Преизползва изцяло `MCP-CONNECTORS.md`** —
тук само свързваме org-слоя към него.

### 5.1 Свързване org → MCP

- При материализация на задача с `act_mode != draft`: `OrgPlannerService`/`FlowPlannerService`
  вмъкват `mcp_action` node(s) + задължителен `human_approval` predecessor за write tools
  (правилото вече съществува в `FlowPlannerService::designPipeline` / §6.5 на MCP плана —
  **не го преписвай**, само го задействай чрез наличните `company_connectors`).
- `approval_policy` на задачата мапва към изпълнението: `auto` (само за `draft`), `approve_each` (всеки `act` минава Кутията), `approve_first_then_auto` (първото действие на нов конектор → Кутия, после авто) — §13. `DecisionBoxService::approve` на `act` предложение → продължава паузирания `FlowRun` (approval gate на `McpActionAgent`) през `ApprovalService` (§A7).
- **`act` HARD GATE под preview (§B2).** Докато е активна само preview `ClientAuth` (passwordless
  сесия — `session('client_company_id')`, без реален auth) и `config('organization.act.enabled')`
  (`ORG_ACT_ENABLED`) е **false** (default), **`act` write конекторите са HARD-DISABLED**: реален
  страничен ефект не се изпълнява. Гейт на изпълнението на `mcp_action` write стъпка: ако act НЕ е
  enabled → стъпката произвежда **„чернова на действието"** (структуриран draft — какво БИ
  направила: tool, аргументи, очакван ефект) за човека, без да удря конектора. Реалният `act`
  изисква **реален auth** (свързано с auth бележката във §0.5.8 / предусловието на Фаза 6) — едва
  тогава `ORG_ACT_ENABLED=true` отключва истинските write-ове. `draft`/четене не са засегнати.

- View `client/org/integrations.blade.php` — конекторите на фирмата (`company_connectors`) като видим инвентар: свързани/налични, статус (active/expired/error), кои задачи ги ползват. Връзка към съществуващия `CompanyConnectorController` (OAuth flow вече съществува).
- Route: `org/integrations` (index — чете `company->connectors`).

### 5.3 Първо действие / висок риск

- `gmail.send_email`, масов имейл, плащане → **винаги** Кутия за първото действие (§13), независимо от `approval_policy`. `DecisionBoxService` маркира „висок риск" по списъка write-tools от MCP плана.

**Критерии за приемане (Фаза 5):**
- `act` задача с write tool има `human_approval` преди MCP node-а; пускането я паузира до одобрение.
- **С `ORG_ACT_ENABLED=false` (preview):** одобрена `act` стъпка дава **чернова на действието** без
  реален страничен ефект (§B2); няма ред в `connector_tool_logs`. С `ORG_ACT_ENABLED=true` (реален
  auth) — действието се извършва и се логва.
- Одобрение от Кутията продължава run-а през `ApprovalService`; при включен act действието се извършва (логва се в `connector_tool_logs`).
- `approve_first_then_auto` пита само за първото действие на нов конектор.
- Интеграции рейл показва конекторите и кои задачи ги ползват.

---

## Фаза 6 — Stripe drop-in (заменя админ top-up) + планове/overage UI + отключване по план

**Цел:** да закачим **реален процесор (Stripe)** зад вече готовия `PaymentProvider` интерфейс
от Фаза 0.5 — **заменяйки** админ-симулираното зареждане с истинско плащане. Кредитната
машина (метеринг → резервация → реконсилиация → ledger) и таванът на плана **вече работят**
от Фаза 0.5; тук добавяме само платежния канал, абонаментния UI и overage. **Реален auth е
ПРЕДУСЛОВИЕ** за тази фаза (§0.5.8 auth бележка). Кредитната формула / `effectiveStarTier`
правилото е дефинирано веднъж по-долу и важи за цялата система.

### 6.1 Каноничното правило за нивото (една дефиниция) + кредитна машина (от Фаза 0.5)

> ### Наследяване на нивото (каноничното правило — една дефиниция за цялата система)
>
> **Effective tier на задача** = (1) явният override на задачата (`assistant_tasks.star_tier`,
> ако е non-null) ИНАЧЕ (2) `org_members.default_star_tier` на члена-собственик; накрая (3)
> **cap** по абонамента (`plans.max_star_tier` през `Subscription`). Реализирано **на едно
> място**: `AssistantTask::effectiveStarTier()`. Никъде в билинга/изпълнението не се ползва
> суровият `star_tier` — винаги `effectiveStarTier()`.
>
> - Нивото на члена живее на `org_members.default_star_tier` (стабилен **ранг**, преживява версиите).
> - **Повишаване/понижаване на член** (смяна на `default_star_tier` през `OrgMember::setDefaultStarTier`)
>   → всичките му задачи **без явен override** веднага ползват новото ниво за **кредитната оценка**.
>   Задача с override остава фиксирана, докато override-ът не се махне.
> - **Re-pin на вече материализирани flows = LAZY (решение).** Моделите се пиннат във `flow_nodes`
>   при генерация; смяната на ниво **не** ги пре-пинва веднага. Засегнатите задачи без override се
>   маркират **`assistant_tasks.tier_stale=true`** (сетва се от `setDefaultStarTier`/`applyTierToDepartment`).
>   При **следващото пускане** на stale задача flow-ът се **re-pin-ва server-side** към `effectiveStarTier()`
>   (`ModelRouterService::assign` върху активната версия, записано — server-side relevel, не preview/client-apply),
>   после `tier_stale=false`, после изпълнение. Така няма изненадваща цена при повишаване; ефектът върху
>   моделите е от следващото пускане. Опционално ръчно „Приложи нивото сега" (regenerate). **Схема/UI:**
>   добави `tier_stale` bool default false към `assistant_tasks` (#7) + Приложение А; индикатор „ново ниво —
>   при следващо пускане" на stale задачите.
> - **Директор:** неговият `default_star_tier` е негов собствен; UI действието **„повиши целия отдел"**
>   (`OrgMember::applyTierToDepartment`) **опционално** прилага нивото на директора към асистентите му
>   (само тези **без** ръчен override) → каскадира към техните задачи. **Не е тихо/автоматично** — само
>   през действието.
> - Управителят/планирането ползва `config('organization.manager.level')` — **отделно** от `default_star_tier`.

**`app/Services/Org/CreditMeterService.php` вече е реализиран във Фаза 0.5** — context-agnostic
`reserve(companyId, contextType, subject, estimate): CreditReservation` / `settle(CreditReservation,
actual)` / `refund(CreditReservation)` / `actualFor(CreditReservation)` / `topup()` (метеринг върху
`llm_requests` през `BillableUnit`, атомарен DB decrement, operation-scoped `idempotency_key`,
реконсилиация — §A1/§A2). **Старата „наивна" `estimate()→debit()` пътека НЕ съществува** (изтрита по
правилото „без legacy" — заменена от reserve/settle по `CreditReservation` за ВСИЧКИ контексти). Тук
Фаза 6 добавя само липсващото около абонамента:
- `grantMonthly(Subscription $sub): void` — месечен кредит по плана (`plans.monthly_credits`,
  ledger `type=grant`). Извиквано ръчно/планирано във Фаза 0.5; тук го дърпа Stripe webhook-ът.
- Overage гард в `reserve()`: при недостиг → overage (ако `billing.overage_enabled` + планът
  позволява), иначе блок с приятелско съобщение. (Самият атомарен `balance >= ?` остава.)

### 6.2 `BillingService` (Stripe — заменя `AdminSimulatedPaymentProvider`)

Stripe е **drop-in зад `PaymentProvider`** (Фаза 0.5): сменяме binding-а
`PaymentProvider → StripePaymentProvider` в service provider-а; останалата кредитна логика не
се пипа. `app/Services/Org/BillingService.php`:
- `subscribe(Company $company, Plan $plan): Subscription` — Stripe Checkout/subscription; записва `subscriptions` + `stripe_subscription_id`.
- `topUp(Company $company, int $credits): CreditLedgerEntry` — еднократна покупка кредити (Stripe payment) → `CreditMeterService::topup(..., type:'topup')`. **Заменя** админ-симулираното зареждане от §0.5.4 (което остава за вътрешен/тестов употреба).
- `handleWebhook(array $payload): void` — Stripe webhook: подновяване (→ `grantMonthly`), неуспешно плащане (→ `status=past_due`), отказ. Това е моментът, в който се появява реалният webhook (§0.5.4 нямаше такъв).

### 6.3 Закачане към изпълнението (вече направено във Фаза 0.5)

- Гейтът е **вече закачен** от Фаза 0.5: `AssistantTaskController@run` резервира преди старт
  (`reserve`, §0.5.3) и `settle`-ва след завършване (§3.2 стъпки 1 и 4). Тук Фаза 6 **не пипа**
  изпълнението — само сменя откъде идват кредитите (Stripe вместо админ) и добавя overage в
  `reserve`. Никаква промяна в `NodeExecutorService` — `cost_usd`/`llm_requests` вече се пишат там.

### 6.4 Отключване по готовност/план (§14.4)

- `max_star_tier` на плана ограничава кои нива са позволени (UI disable + сървърна проверка). Тъй като cap-ът е вграден в `AssistantTask::effectiveStarTier()` (трета стъпка), той важи и за наследеното ниво на члена (`default_star_tier`), и за per-task override-а, и при relevel/`OrgPlannerService::finalizeOrganization` — задача никога не се пуска над тавана на плана, без значение откъде идва нивото.
- „Заключено" = (1) по-висок план (флагман/цели отдели), (2) реална неготовност — Управителят гейтва („отключи след свързване на календар"). Поднася се като **куест/препоръка**, не платена стена. Никакво изкуствено заключване в рамките на плана.

### 6.5 Routes / Views

```php
Route::middleware('client_auth')->prefix('org')->group(function () {
    Route::get('billing',            [Client\Org\BillingController::class, 'index'])->name('client.org.billing');
    Route::post('billing/subscribe', [Client\Org\BillingController::class, 'subscribe'])->name('client.org.billing.subscribe');
    Route::post('billing/top-up',    [Client\Org\BillingController::class, 'topUp'])->name('client.org.billing.top-up');
});
// Stripe webhook (извън client_auth, валидиран по подпис) — в routes/web.php или api.php
Route::post('stripe/webhook',        [Client\Org\BillingController::class, 'webhook'])->name('stripe.webhook');
```

- View `client/org/billing.blade.php` — кредити/звезди, разход (от `credit_ledger`), бюджет, планове, ъпгрейд — професионално, с реални лв (§16 „Кредити & планове").

**Критерии за приемане (Фаза 6):**
- (Foundation от Фаза 0.5, проверено отново тук) пускане резервира предварително (по `effectiveStarTier()`) и реконсилира реалния разход (видим в `credit_ledger`, `type=reserve`+`settle`/`refund`); балансът пада коректно.
- Повишаване на член сменя effective tier + **кредитната оценка/резерв** на всичките му задачи без override; задача с override остава фиксирана.
- Cap по плана важи: задача наследила/override-нала ниво над `plans.max_star_tier` се пуска свито до тавана (сървърно + UI disable).
- „Повиши целия отдел" каскадира нивото на директора към асистентите без override и оттам към задачите им.
- **Stripe** subscribe/top-up работят зад `PaymentProvider` (binding сменен от `AdminSimulated` на `Stripe`); webhook подновява месечния кредит (`grantMonthly`). Админ-симулираното зареждане продължава да работи за вътрешен употреба.
- Бюджетен таван блокира при изчерпване (или предлага overage по плана).
- Starter не може да пусне god-задача (сървърно + UI); ъпгрейдът я отключва.

---

## Фаза 7 — Жива организация: периодично ревю, рефлексия/памет per член, динамично наемане/уволнение, хроника

**Цел:** организацията се развива с бизнеса — периодично ревю на Управителя, памет/рефлексия
per член, наемане/уволнение по всяко време, хроника на фирмата (§10/§15).

### 7.1 Ревю на Управителя (`OrgReviewService` + job)

- `app/Services/Org/OrgReviewService.php` → `review(Company $company): array` — KPI, отчети,
  празноти, `qa_score` трендове → **предложения** (нов член/задача, пенсиониране на слаб
  асистент, нов отдел, ескалация) през персоната на Управителя. Всичко → Кутия (одобрение).
- `app/Jobs/Org/OrgReviewJob.php` (**`org` queue**, §0.5.8) + `org:review` команда (schedule, седмично) — образец `RunScheduledFlows`.

### 7.2 Памет и рефлексия per член (§5.6)

- `app/Services/Org/MemberMemoryService.php` — **обвива `FlowMemoryService` + `KnowledgeService` + `node_runs` история**: паметта виси на стабилния `OrgMember` (преживява реорганизациите); per член поток от памет; периодична рефлексия синтезира поуки (директор забелязва слаб асистент → препоръчва смяна). Преизползва `DistillFlowMemoryJob` патърна (digest + embedding); не дублира embedding логика.
  - **OWNER scope (защо е нужен мост).** Съществуващият `FlowMemoryService` е **`flow_id`-scoped**
    (`flow_memories.flow_id`, всичките му заявки филтрират по flow) — той помни какво е произвел
    ЕДИН flow, не какво е научил ЕДИН човек. За памет/рефлексия **per ЧЛЕН** добавяме **owner
    scope**: или (а) колона `owner_member_id` (nullable) към `flow_memories` + четене през члена
    (всичките му задачи-flows), или (б) тънка `member_memories` мост-таблица на `org_member_id`,
    която агрегира digest-ите от flow-овете на члена. `MemberMemoryService` чете през стабилния
    `OrgMember` (= всичките негови `assistant_tasks → flows`), за да се трупа поука **per човек**,
    не per flow — и да оцелява при реорганизация. Рефлексията пише owner-scoped поуки тук.
- **Persona памет/рефлексия местата.** Персоната (на члена), пинната в system prompt (§0.5.5
  persona injection + чат §4.4) + owner-scoped рефлексията тук, пазят от persona drift (§20
  риск). Където §4.4/§0.5.5 четат „памет на члена", източникът е owner-scope-ът по-горе, НЕ
  суровият `flow_id`-scoped `FlowMemoryService`.

### 7.3 Динамично наемане/уволнение

- `OrgVersionController` — наемане/уволнение/преназначаване по всяко време → **мутира
  `org_members`** (нов член = нов ред `status=active`; уволнен = `status=retired` + `retired_at`,
  без да трие историята/паметта му; преназначаване = ъпдейт на плейсмънта/`current_director_member_id`)
  И създава **нова `org_version`** със съответните плейсмънт редове (immutable снапшот; старата
  → `archived`); `companies.active_org_version_id` се пренасочва. Така идентичността,
  персоната, чатът, паметта и (за асистенти) задачите на оцелелите членове остават
  непроменени. Всяка промяна минава Кутията и записва `org_events`
  (`hire`/`fire`/`reassign`/`mandate_change`), сочещи `org_member_id`.
- `OrgBlueprintLibraryService::markProven` — успешна версия → `proven` blueprint (учеща библиотека).

### 7.4 Хроника (§16)

- View `client/org/chronicle.blade.php` — `org_events` като лента/история на фирмата („кой какво свърши"). Route `org/chronicle`.

### 7.5 Редакция на персона → регенерация на аватара

- `Client\Org\PersonaController@update` (§2.5): при запис **diff проверка** върху демографията —
  ако се сменят `gender`/`age`/`ethnicity` (полетата, от които се извежда портретът), `PersonaService::attachTo`
  нулира `avatar_status='pending'` (+ нов `avatar_seed` от новите полета) и диспечира `GenerateMemberAvatarJob`.
  Промяна само на име/тон/био **не** регенерира (портретът остава стабилен). Това подсилва „демографията =
  козметична визуална идентичност" (§5.3 на визията).
  - **Само визуалните полета задействат регенерация.** Смяна на произход/образование (`background`/`education`)
    **НЕ** засяга портрета — те не са визуални характеристики; регенерация се прави **единствено** при промяна
    на `gender`/`age`/`ethnicity`.
- Ръчно „Регенерирай аватар" в Картата на героя (route `members/{member}/avatar/regenerate`) форсира пресъздаване
  дори при непроменена демография (напр. след вдигане на спрян ComfyUI).

**Критерии за приемане (Фаза 7):**
- Ръчно/планирано ревю предлага конкретни org-промени в Кутията (обосновани от KPI/qa_score).
- Член „помни" минали runs и рефлектира (поука се появява в препоръка); паметта оцелява при реорганизация.
- Наемане/уволнение мутира `org_members` (нов/`retired`) + създава нова `org_version` с плейсмънти, пренасочва активната, записва `org_events`; оцелелите членове запазват персона/чат/памет/задачи.
- Смяна на възраст/пол/етнос на персона регенерира портретния аватар (нов seed); смяна само на име/тон не. „Регенерирай аватар" форсира пресъздаване.
- Хрониката показва наеманията/уволненията/действията хронологично.

---

## Приложение А — Нови/променени файлове (чеклист)

**Конфигурация:** `config/organization.php` (нов — вкл. **`act.enabled`** = `ORG_ACT_ENABLED` default false, §B2), `config/billing.php` (нов — вкл. **`flat_costs`** за не-LLM инструменти; Stripe блокът остава празен до Фаза 6), `config/services.php` (промяна — `comfyui.portrait_checkpoint` + `comfyui.portrait_negative` за портретните аватари), `config/horizon.php` (промяна — **нов `supervisor-org` queue** за ВСИЧКИ дълги org LLM jobs + `'redis:org'` в `waits`, §0.5.8/§B1), `.env(.example)` (ORG_* вкл. `ORG_ACT_ENABLED` + COMFYUI_PORTRAIT_* + STRIPE_* + BILLING_*).

**Миграции (по реда от §0.2 + Фаза 0.5):** `create_business_profiles_table`, `create_org_versions_table`, `add_active_org_version_to_companies_table`, `create_org_members_table` (вкл. денормализиран `avatar_url` + **`default_star_tier`** string default `medium` — нивото/рангът на члена), `create_directors_table` (**без** `default_star_tier` — мести се на `org_members`), `create_assistants_table`, `create_assistant_tasks_table` (**`star_tier` nullable** — null = наследява `org_members.default_star_tier`, non-null = override; **`status` вкл. `generating\|ready\|failed`** — state machine §0.5.6; **`run_after_generate` bool default false** — lazy-gen gate §A3), `create_personas_table` (вкл. `ethnicity` + аватар полета `avatar_url`/`avatar_prompt`/`avatar_seed`/`avatar_status`/`avatar_meta`), `create_member_chats_table`, `create_member_messages_table`, `create_org_events_table`, `create_org_blueprints_table`, `create_persona_archetypes_table`, `create_plans_table` (**`max_star_tier`:** Starter=medium, Professional=high, Business=ultra, Enterprise=god — §B3), `create_subscriptions_table`, `create_credit_wallets_table`, `create_credit_ledger_table` (**вкл. `reservation_id` FK + `type` + operation-scoped `idempotency_key` unique** — reserve/settle машината, §A1/§0.5.2), **`create_credit_reservations_table`** (mutable билинг-операция: context_type/subject/estimated/spent/status + RESERVE `idempotency_key` unique, §A1), **`create_org_proposals_table`** (durable mutable решение за DecisionBox: type/payload/status/base_org_version_id, §A7). **Фаза 0.5 (билинг foundation):** `add_billing_context_to_llm_requests_table` (`context_type`/`subject_type`/`subject_id` + **`reservation_id`** за billable атрибуция, §0.5.1/§A6); по избор `create_member_memories_table` ИЛИ `add_owner_member_id_to_flow_memories_table` (owner-scope памет, §7.2/B.8).

**Модели (нови):** `BusinessProfile`, `OrgMember` (вкл. `static allocateKey()` — код-алокатор на идентичностни ключове, §9), `OrgVersion`, `Director`, `Assistant`, `AssistantTask`, `Persona`, `MemberChat`, `MemberMessage`, `OrgEvent`, `OrgBlueprint`, `PersonaArchetype`, `Plan`, `Subscription`, `CreditWallet`, `CreditLedgerEntry`, **`CreditReservation`** (mutable билинг-операция, §A1), **`OrgProposal`** (durable решение за DecisionBox, §A7). **Променен:** `Company` (нови релации + `active_org_version_id` във fillable).

**Services (нови, под `app/Services/Org/`):** `PersonaService`, `AvatarService` (вкл. `redispatchPending` — retry на висящи/провалени аватари, §B4), `BusinessProfilerService`, `OrgInterviewService`, `OrgPlannerService`, `OrgBlueprintLibraryService`, `DirectorAgentService`, **`ApprovalService` (единният resume-after-approval boundary — извлечен от `FlowRunController::approval`; ползван от ДВАТА контролера и от `DecisionBoxService`; БЕЗ controller→controller call, §A7)**, **`DecisionBoxService` (адаптер/агрегатор — НЕ дублира одобрения; агрегира `org_proposals(pending)` + паузирани runs; делегира паузираните runs към `ApprovalService`, §0.5.7/§A7)**, `MemberChatService`, **`CreditMeterService` (context-agnostic `reserve(companyId, contextType, subject, estimate): CreditReservation` / `settle(CreditReservation, actual)` / `refund(CreditReservation)` / `actualFor(CreditReservation)` / `topup` — атомарен decrement + operation-scoped `idempotency_key`, §A1/§A2)**, `BillingService` (Фаза 6 — Stripe), `OrgReviewService`, `MemberMemoryService` (owner-scope памет). **Билинг рейл (нов, под `app/Services/Org/Billing/`):** `PaymentProvider` (интерфейс) + **`AdminSimulatedPaymentProvider`** (сега) → `StripePaymentProvider` (Фаза 6 drop-in). **Support (нов):** **`App\Support\BillableUnit`** (употреба → кредити: LLM token формула + flat config цени, чете `llm_requests` по `reservation_id`, §0.5.1).

**Преизползвани services:** `FlowPlannerService`, `AgentGeneratorService`, `KnowledgeService`, `FlowMemoryService` (**flow_id-scoped** — owner-scope се добавя отвън, §7.2/B.8), `BraveSearchService`, `GooglePlacesService`, `CrawlService`, `ModelRouterService`, `EmbeddingService`, `McpClientService`, `GeneratorService`, `AgentLoop`, `FinalComposerService` (**без промяна по самия service** — но `finalize()` вече отваря `LlmContext` около `compose()`, §A6), `DistillFlowMemoryJob` (**отделен `embedding` billable контекст** със своя reserve/settle, §A6). **Support:** `ModelLevel`, `LlmUsage`, `LlmContext` (**разширен** с `context_type`/`subject` + **`reservation_id`** за billable атрибуция, §A6), `LlmRequestRecorder` (**персистира новите billable колони вкл. `reservation_id`**), `PaidModel`, `QueueHeartbeat` (**+ `org` heartbeat** — `ORG_KEY`/`orgAlive()`/`markOrgAlive()`, §B1). **Променени (минимални, изброени по фази):** `AgentGenerationLauncher` (+ `assistantTaskId` param, §0.5.6), `GenerateAgentsCommand` (+ task-callback → `status=ready` + lazy-gen `run_after_generate` пускане, §0.5.6/§A3), `NodePromptBuilder`/`NodeExecutorService` (+ persona injection за org-flow възли, §0.5.5), **`GraphFlowExecutor`** (носи `org_member_id` + `credit_reservation_id` контекста; `finalize()` отваря `LlmContext` + settle на success; **`fail()`/cancel + human-approval REJECT settle/refund** на терминал — §A4/§A6), **`FlowRunController::approval`** (тънка обвивка над `ApprovalService`; resume-логиката се мести в service-а, §A7), **`ComfyUIService` (`buildWorkflow(string, array $overrides = [])` — seed/checkpoint/negative, §1.1/B.10)**, **`BackfillCostLogCommand` (GUARD/премахни, §B5)** — командата truncate-ва `llm_requests`, който вече е billing source of truth; затова трябва да **ОТКАЗВА**, ако `credit_ledger` има редове (риск от загуба на билинг история) — или да се **премахне** по правилото „без legacy" (наследствените rollup-и вече не са нужни щом DB-то се reset-ва с `migrate:fresh`).

**Jobs (нови, под `app/Jobs/Org/`):** **ВСИЧКИ на `org` queue** (§B1 — `flows` е само за `ExecuteNodeJob`): `ResearchBusinessJob`, `OrgInterviewTurnJob`, `GenerateMemberAvatarJob`, `ProposeOrganizationJob`, `DirectorTickJob`, `ScheduledTaskJob`, `MemberChatTurnJob`, `OrgReviewJob`. Рутиране §0.5.8.

**Команди (нови, под `app/Console/Commands/Org/`):** `RunDirectorTicks` (`org:director-ticks`), `OrgReview` (`org:review`). Регистрация в `routes/console.php` schedule.

**Контролери (нови, под `app/Http/Controllers/Client/Org/`):** `OnboardingController`, `InterviewController`, `DesignController`, `PersonaController`, `OrgGraphController`, `MemberController`, `AssistantTaskController`, `QuestController`, `DecisionController` (Кутия), `IntegrationController`, `BillingController`, `OrgVersionController`.

**Сидове (нови):** `OrgBlueprintSeeder`, `PersonaArchetypeSeeder`, `PlanSeeder` (+ закачане в `DatabaseSeeder`).

**Views (нови, под `resources/views/client/org/`):** `casting`, `research`, `interview`, `design-review`, `roster`, `skill-tree`, `member`, `_persona-card` (портретен аватар с fallback цветни инициали при `pending`/`failed`), `quests`, `live`, `chat`, `decisions`, `integrations`, `billing`, `chronicle`. **Променен:** `layouts/client.blade.php` (навигация „Моята организация").

**Routes:** `routes/client.php` (нова `org/*` група, фаза по фаза); `routes/web.php` или `routes/api.php` (Stripe webhook — **само Фаза 6**).

**Админ панел (промяна — §0.5.4):** в съществуващия `is_admin`-гейтнат `Admin/*` — действие „Зареди кредити + сложи план" за фирма (вика `BillingService::adminTopUp` зад `AdminSimulatedPaymentProvider`). Това е единствената нова админ повърхност; реален auth/Stripe идват по-късно.

> **Без промяна по админ ядрото** освен админ-симулираното зареждане (§0.5.4) и където фаза
> изрично го изброи. MCP плана и клиентският wizard се **задействат/преизползват**, не се преписват.
> Одобрителната resume логика се **извлича** в `ApprovalService` (§A7) — `FlowRunController::approval`
> остава, но като тънка обвивка над него (нулева промяна в pause/resume семантиката, само едно място
> за нея, ползвано и от Кутията без controller→controller call).

---

## Приложение Б — Ред на изпълнение и зависимости

1. **Фаза 0** (домейн + сидове + билинг скелет) — блокира всичко.
2. **Фаза 0.5** (изпълнение + билинг foundation) — след Фаза 0, **преди всяко пускане/действие**: `BillableUnit`, `CreditMeterService` (generic reserve/settle/refund/actualFor по `CreditReservation`, §A1/§A2), reservation атрибуция в `LlmContext`/`LlmRequestRecorder` (§A6), terminal settlement в `finalize()`/`fail()`/REJECT (§A4), lazy-gen billing gate + `run_after_generate` (§A3), `PaymentProvider`+`AdminSimulated`, persona injection contract, generation state machine, `ApprovalService` boundary + Decision Box адаптер с `org_proposals` (§A7), queue topology (`org` queue/heartbeat, §B1). Предусловие за run/billing/action на следващите фази.
3. **Фаза 1** (Casting + Intake + Проучване + Интервю) — след Фаза 0.5; въвежда `PersonaService`, `BusinessProfilerService`, `OrgInterviewService`.
4. **Фаза 2** (Дизайн + Roster/Skill Tree + материализация) — след Фаза 1; въвежда `OrgPlannerService`, `OrgBlueprintLibraryService`. Леща-изгледите зависят от материализираните записи.
5. **Фаза 3** (Задачи=flows + пускане + Текущ поток) — след Фаза 2; зависи от `AgentGenerationLauncher` + `GraphFlowExecutor` + **wallet гейта от Фаза 0.5**. **MVP-демо приключва тук (Фази 0–3, с реален кредитен foundation + админ-симулирано зареждане).**
6. **Фаза 4** (Директор-агент + график + Кутия + чат) — след Фаза 3; чатът преизползва token-poll патърна; Кутията (адаптер от Фаза 0.5) е предпоставка за Фаза 5/7.
7. **Фаза 5** (`act` + интеграции рейл) — след Фаза 4 (Кутия) + наличен MCP слой; само свързва org→MCP, без да го преписва.
8. **Фаза 6** (**Stripe drop-in**) — кредитната машина вече работи от Фаза 0.5; тук само сменяме `PaymentProvider` binding-а на Stripe (заменя админ top-up) + абонаментен UI/overage/webhook. **Реален auth е предусловие.** Може да дойде късно (след като има какво да се продава).
9. **Фаза 7** (жива организация) — последна; зависи от Фази 2 (версии), 3 (runs), 4 (Кутия/чат).

Изпълнявай и проверявай в браузъра след всяка фаза, преди да минеш нататък. След всяка
фаза: `vendor/bin/pint`. Reset при нужда: `php artisan migrate:fresh --seed` (без миграция
на стари данни — §CLAUDE.md).
