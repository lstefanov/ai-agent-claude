# AI Организация — Прогрес (Claude Code поддържа актуален)

> Отбелязвай ✅ когато фазата е завършена **И** проверена по „Критериите за приемане". Добавяй
> кратки бележки/решения (1 ред). **Commit след всяка фаза.** Това е resumable: ако прекъснеш,
> „продължи" от първата неотметната фаза.

## Фази (изпълни в този ред)

- [x] **Фаза 0** ✅ — Домейн модел + seed библиотеки (org-blueprints / persona-archetypes / plans) + билинг скелет
- [x] **Фаза 0.5** ✅ — Изпълнение и билинг foundation (метеринг върху `llm_requests`, кредитна резервация+идемпотентност, persona injection в runtime, generation state machine, Decision Box адаптер, member memory по `org_member_id`, code-owned keys, avatar overrides, org queue)
- [x] **Фаза 1** ✅ — Casting на Управителя + Intake + Проучване + Интервю → Бизнес профил
- [x] **Фаза 2** ✅ — Дизайн на екипа с персони + Skill Tree/Roster UI → материализация
- [ ] **Фаза 3** — Задачи = flows; генериране per асистент; ръчно пускане; Текущ поток **(край на MVP-демо)**
- [ ] **Фаза 4** — Директор-агент (рутиране/ревю/отчети/препоръки) + график + Кутия за решения + чат с членове
- [ ] **Фаза 5** — `act` задачи през конектори + интеграции рейл + политики на одобрение
- [ ] **Фаза 6** — Stripe drop-in (заменя админ top-up) + планове/overage UI + отключване по план
- [ ] **Фаза 7** — Жива организация: периодично ревю, рефлексия/памет per член, динамично наемане/уволнение, хроника

## Проверка след всяка фаза (без тестове)

`migrate:fresh --seed` · `route:list` · `about` · Horizon running · браузър happy-path (Критерии за приемане) · `pint` · `npm run build` · логове чисти

## Решения / бележки (Claude Code пише тук)

**Фаза 0 (2026-06-24):**
- 19 миграции `2026_06_24_100001..100019` (18 нови таблици + колона `companies.active_org_version_id`). FK ред: `credit_reservations` ПРЕДИ `credit_ledger` (ledger.reservation_id сочи натам).
- `credit_ledger` + `org_events` са append-only (`const UPDATED_AT = null`, само `created_at`).
- `OrgMember::allocateKey` = детерминистичен slug + суфикс `-2,-3…`; manager → фиксиран ключ `manager`. Никога LLM.
- `AssistantTask::effectiveStarTier()` = `star_tier ?? member.default_star_tier`, после cap по `Plan::max_star_tier`. Добавени `ModelLevel::rank()/cappedAt()` (additive, non-breaking).
- Планове: Starter=medium(1000кр/$29), Professional=high(5000/$99), Business=ultra(20000/$299), Enterprise=god(100000/$999). Стойностите са разумен default — подлежат на бизнес-настройка.
- `.env`: `COMFYUI_PORTRAIT_*` са **коментирани** (празен env var презаписва config default-а с '' → коментар = важи face-friendly default-ът).
- Проверка зелена: `migrate:fresh --seed` чисто; `Plan::count()=4`, fitness blueprint + 8 архетипа + 3 blueprints; tinker smoke (персона/плейсмънти/наследяване+cap/повишение→event/cascade) минава; `pint` чисто; `about`/`route:list` OK; логове чисти. (UI/`npm build` неприложимо — Фаза 0 е само схема.)

**Фаза 0.5 (2026-06-24):**
- Метеринг: миграция `100020` добавя `context_type/subject/reservation_id` към `llm_requests`; `LlmRequestRecorder` ги стампва от ambient `LlmContext`. `BillableUnit` = token формула (`base(level)×ceil(completion/1k)`) + flat config цени.
- `CreditMeterService` (reserve→settle/refund/topup): атомарен conditional UPDATE (`balance >= ?`), идемпотентност по unique RESERVE ключ + operation-scoped ledger ключове (`{res}:settle/refund`). Тествано: atomic decrement, double-reserve no-op, settle+refund остатъка, double-settle no-op, full refund, topup, insufficient→`InsufficientCreditsException`.
- `PaymentProvider` (binding → `AdminSimulatedPaymentProvider` в AppServiceProvider) + `BillingService::adminTopUp/grantMonthly`. Без Stripe.
- Persona injection в `NodeExecutorService::runOnce` (след знание): `PersonaService::compileSystemPrompt`, кеширан per run; `shouldInjectPersona` изключва `qa_verifier/bg_text_corrector/translator/human_approval/mcp_action/decision` (под на компетентност). Не-org flow → no-op.
- Билинг-атрибуция в изпълнението: node LLM повикванията четат `credit_*` от `flowRun.context`; `GraphFlowExecutor::finalize()` обвива FinalComposer в резервацията + `settleRunReservation` (success); `fail()` settle+refund (терминал, вкл. reject). `FlowPlannerService::runPhase` наследява `reservation_id` (generation атрибуция).
- Generation state machine: `AgentGenerationLauncher::launch(+assistantTaskId)`; `GenerateAgentsCommand` callback връзва `flow_id`+`ready`/`failed` + settle/refund generation резервацията. `TaskRunService` (споделен от Фаза 3/4): `requestRun` (ready→reserve task_run+пусни; иначе reserve generation+generating+run_after_generate), `launchReadyRun`, `autoRunAfterGenerate`.
- `ApprovalService::settle` = единният resume-after-approval boundary; `FlowRunController::approval` → тънка обвивка. `DecisionBoxService` агрегира `org_proposals(pending)` + паузирани runs; optimistic concurrency (остаряла `base_org_version_id` → `superseded`). Тествано.
- Queue: `supervisor-org` (queue `org`, timeout 1200, tries 1) + `redis:org` wait; `QueueHeartbeat::orgAlive()` писан от `SupervisorLooped`. Потвърдено: трите супервайзора вървят, `orgAlive`=YES.

**Фаза 1 (2026-06-24):**
- `PersonaService` разширен: `seedTraitsFromDemographics` (24г→+риск/+креативност; 59г→+прецизност), `deriveKnobs` (temperature/star_tier-hint/approval), `attachTo` (upsert + regen на портрета само при смяна на gender/age/ethnicity), `archetypes`. Тествано: 20-vs-60 различни черти; regen-guard.
- `AvatarService` (преизползва `ComfyUIService`): `portraitPrompt` (детерминистичен, само демография — без role/tone), `seedFor` (стабилен demography-hash), `generateFor` (overrides workflow → стабилен файл `avatars/member_{id}.png`; спрян ComfyUI → pending+инициали), `redispatchPending`. `ComfyUIService::buildWorkflow(+$overrides)` — обратно-съвместимо (image-агентите непокътнати).
- `BusinessProfilerService` (services, не директни HTTP): `research` (сайт/Brave/Places, мек деградейшън) + `analyze` (Ollama синтез → анализ+pain_points). `OrgInterviewService` (по модела на wizard-а: chatJson + normalize + forceReady).
- Jobs (`org` queue): `ResearchBusinessJob`, `OrgInterviewTurnJob`, `GenerateMemberAvatarJob` — best-effort билинг за онбординга.
- UI: routes `org/*` в `routes/client.php`; nav „Моята организация"; контролери `Client\Org\{Onboarding,Interview}`; views `casting/research/interview` (token-poll чат като wizard-а). Всички рендерват валиден HTML; `npm build` минава.
- **Runtime verify:** реален `ResearchBusinessJob` мина end-to-end през `supervisor-org` (Ollama) → анализ 894 знака, 6 болки, sources=website,web_search,google_reviews.
- **Решение (§15 ambiguity — кой плаща онбординга):** онбордингът (research/interview) е **best-effort billable** — таксува при наличен баланс, иначе продължава безплатно (нова фирма се онбордва без top-up); само task runs + generation са hard-gated.
- Браузър click-through изисква MAMP поддомейна `clients.flowai.local.com` + Ollama — проверено е чрез server-side render + реален job, не през хедлес браузър.

**Фаза 2 (2026-06-24):**
- `OrgPlannerService` (ПЛАНЕРЪТ ПРЕДЛАГА, КОДЪТ ГАРАНТИРА): `proposeOrganization` (закотвен на blueprint скелет + LLM персони/куестове, fallback при слаб модел), `finalizeOrganization` (валиден директор→асистент граф без сираци, legal act_mode/trigger, deriveKnobs, тавани), `materialize` (by-id реконсилация, нов OrgVersion + плейсмънти + персони upsert + задачи + org_events + active_org_version_id; retire на липсващите).
- `OrgBlueprintLibraryService` (`bestMatch` по вертикал/proven, `snapshot`, `markProven`).
- `ProposeOrganizationJob` (`org` queue, best-effort org_planning билинг). UI: `design-review` (auto-propose→poll→редактируем екип→одобри), `roster`, `skill-tree`, `member` (Карта на героя: ниво/повиши отдел/регенерирай аватар/per-task tier), `_persona-card` + `_lens-tabs`; контролери `Design/OrgGraph/Member/Persona/AssistantTask`.
- **Char палитра safelist** в `app.css` (`@source inline(...)` за 7-те тона × bg/soft/strong/ring) — иначе динамичните `bg-char-{{ $c }}` се purge-ват. `npm build`=84KB OK.
- **Runtime verify:** реален `proposeOrganization` (Ollama) → 6 директори/4 асистенти/3 задачи/2 куеста с LLM персони; `materialize` → version+плейсмънти+персони+задачи+4 hire events+active; **re-materialize пази члена по immutable id** (kept reused, dropped→retired); 3 лещи + design-review рендерват валиден HTML (звездите отразяват tier). pint/view:cache чисти.

## ⚠ Решения за човек / блокери (липсващи credentials/услуги)

- ~~Уеб проучване (Фаза 1) деградира~~ → **ОТМЕНЕНО (2026-06-24):** грешен env var в pre-flight. Истинският ключ е `BRAVE_SEARCH_API_KEY` (set) + Crawl4AI върви на :8189 + Google Places set. Реалният research job ползва и трите източника — пълно проучване работи, без деградация.
- **Stripe (Фаза 6):** `STRIPE_*` празни — по план; ползва се `AdminSimulatedPaymentProvider`. Реален (парола) auth = задача на собственика, предусловие за Фаза 6.
