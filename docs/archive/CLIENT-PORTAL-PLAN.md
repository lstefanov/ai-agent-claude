# Клиентски портал — План за имплементация (за Claude Code)

> Спецификация: [`CLIENT-PORTAL-SPEC.md`](./CLIENT-PORTAL-SPEC.md). Този документ е **планът за действие** — фази, файлове, routes, миграции, сигнатури и критерии за приемане.

## Правила (от `CLAUDE.md` — спазвай ги стриктно)

- **Без тестове.** Не пиши и не пускай тестове.
- **Без legacy/back-compat.** Заменяш ли логика — трий старата пътека (собственикът reset-ва БД).
- LLM извиквания **само през services** (`GeneratorService` / `AgentLoop` / `OllamaService`), никога директно от контролери.
- Planner-ът **предлага**, кодът **гарантира** — валидирай всеки LLM изход.
- Форматирай с `vendor/bin/pint`. Стандартна Laravel структура.
- Дизайн: **само** токени и `x-*` компоненти; никакъв hardcoded hex в Blade.
- Коментари на български, идентификатори/код на английски (както в кодовата база).

## Архитектурно решение (резюме)

Нов **клиентски слой** върху съществуващото ядро. Поддомейн `clients.flowai.local.com` (конфиг). Сесийна „fake“ аутентикация. Преизползва: `flows:generate-agents`, run-poll, `AgentLoop`, `KnowledgeService`, `BraveSearchService`, дизайн-системата. Всичко клиентско живее в `App\Http\Controllers\Client\*`, `routes/client.php`, `resources/views/client/*`, `layouts/client.blade.php`.

Имплементирай **фаза по фаза**; всяка фаза е самостоятелно тестваема в браузъра.

---

## Фаза 0 — Основа: маршрутизация, auth, layout

### 0.1 Поддомейн routing
- `.env`: добави `CLIENT_DOMAIN=clients.flowai.local.com`.
- `config/app.php`: добави `'client_domain' => env('CLIENT_DOMAIN')`.
- `bootstrap/app.php`: в `withRouting(... then: function () {...})` зареди клиентските routes в domain group:
  ```php
  ->withRouting(
      web: __DIR__.'/../routes/web.php',
      api: __DIR__.'/../routes/api.php',
      commands: __DIR__.'/../routes/console.php',
      health: '/up',
      then: function () {
          Route::middleware('web')
              ->domain(config('app.client_domain'))
              ->group(base_path('routes/client.php'));
      },
  )
  ```
- В `withMiddleware`: регистрирай alias `'client_auth' => \App\Http\Middleware\ClientAuth::class`.
- **Fallback за локално без поддомейн:** ако `CLIENT_DOMAIN` е празно → регистрирай същата group с `->prefix('client')` вместо `->domain(...)`. Документирай в README, че за поддомейна трябва Valet/`dnsmasq`/`/etc/hosts` запис към `clients.flowai.local.com`.

### 0.2 Потребители ↔ фирми
- Миграция `add_company_and_role_to_users_table`: `company_id` (FK nullable, `nullOnDelete`), `role` (string, default `member`), `is_active` (bool, default true).
- Миграция `backfill_owner_users` (идемпотентна, data-миграция): за всяка фирма без `owner` създай:
  ```php
  User::create([
      'name' => 'Owner',
      'email' => "owner+{$company->id}@flowai.local",
      'password' => bcrypt(Str::random(32)),
      'company_id' => $company->id,
      'role' => 'owner',
  ]);
  ```
- `User` модел: добави `company_id, role, is_active` към `$fillable`, cast `is_active` → bool, релация `company()` (belongsTo).
- `Company` модел: релация `users()` (hasMany). Helper `owner()` (hasOne where role=owner).
- `CompanyController@store`: след създаване на фирмата създай owner потребител (същата логика). **Това е единственото пипане в админ контролер за тази фаза.**

### 0.3 Middleware `ClientAuth`
`app/Http/Middleware/ClientAuth.php`:
```php
if (! session('client_company_id')) {
    return redirect()->route('client.login');
}
// зареди фирмата/потребителя и сподели към view-тата
$company = Company::find(session('client_company_id'));
if (! $company) { session()->forget(['client_company_id','client_user_id']); return redirect()->route('client.login'); }
view()->share('currentCompany', $company);
view()->share('currentUser', User::find(session('client_user_id')));
return $next($request);
```

### 0.4 Login / Logout
- `routes/client.php` (вижда се целият в Приложение А).
- `Client\AuthController`:
  - `showLogin()` → view `client.auth.login` с `companies = Company::orderBy('name')->get()`.
  - `usersForCompany(Company $company)` → JSON `users` (id, name, role) за избраната фирма (за второто падащо поле).
  - `login(Request)` → валидирай `company_id` (exists) + `user_id` (exists, принадлежи на фирмата) → `session(['client_company_id'=>…, 'client_user_id'=>…])` → redirect `client.dashboard`.
  - `logout()` → forget → redirect `client.login`.
- View `client/auth/login.blade.php`: **standalone** layout (без клиентската навигация), две Tom Select полета (фирма → users чрез Alpine fetch към `usersForCompany`), бутон „Влез“. Стил: центрирана `x-card`, токени.

### 0.5 Layout
- `resources/views/layouts/client.blade.php`: копирай скелета на `layouts/app.blade.php` (head, fonts, `@vite`, csrf, flash alerts) и смени **само навигацията**:
  - Ляво: лого → `client.dashboard`.
  - Линкове: „Табло“ (`client.dashboard`), „Моите Flows“ (`client.flows.index`).
  - Изпъкващ primary бутон `x-button` „＋ Създай нов Flow“ → `client.flows.create`.
  - Дясно: `currentCompany->name` + dropdown (Изход → `client.logout`; Смени фирма → `client.login`).
- Добави `.line-clamp-4` в `resources/css/app.css` (огледало на `.line-clamp-2`).

**Критерии за приемане (Фаза 0):**
- Отваряне на клиентски URL без сесия → redirect към login.
- Избор на фирма зарежда нейните users; „Влез“ води към таблото; навигацията показва фирмата.
- Двете съществуващи фирми имат `owner`; нова фирма от админа също получава `owner`.

---

## Фаза 1 — Табло + Моите Flows (карти)

### 1.1 Dashboard
- `Client\DashboardController@index` → view `client/dashboard.blade.php`.
- Данни: `flows_count`, `runs_count` (през фирмените flows), `last_run_at`, последни 3–5 flows.
- View: поздрав, 3 стат-`x-card`, кратък списък „Последни Flows“ с бутон „Изпълни“, голям CTA „Създай нов Flow“. Минимално.

### 1.2 My Flows (карти)
- `Client\FlowController@index` → активните (`is_archived=false`) flows на `currentCompany`, с `withCount('flowRuns')` и `latestRun`.
- View `client/flows/index.blade.php`: `grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5` от карти (огледало на `companies/index`):
  - заглавие, описание `line-clamp-4`,
  - Alpine компонент за състояния (idle/running/done/failed) — вж. Фаза 2,
  - бутони „Изпълни“ (primary) и „Детайли“ (`client.flows.show`).
- Empty state (`x-empty-state`) + CTA при липса на flows.

**Критерии:** табло и списък се рендират в клиентския layout; картите изглеждат като админ компаниите; „Детайли“ работи; „Изпълни“ е готов за закачане във Фаза 2.

---

## Фаза 2 — Изпълнение, прогрес на картата, Резултат

### 2.1 Старт на изпълнение
- `Client\FlowRunController@run(Flow $flow)`:
  - Guard: `$flow->company_id === session('client_company_id')` (иначе 403).
  - Провери `flowsWorkerAlive()` (изнеси хелпъра от `FlowRunController` в `App\Support\QueueHeartbeat` или trait, за да не дублираш).
  - Вземи активната версия (`$flow->activeVersion`); ако няма активни възли → JSON грешка.
  - Създай `FlowRun(status:'pending', triggered_by:'manual')`, извикай `GraphFlowExecutor::run($flow,'manual',$run)`.
  - Върни JSON `{ run_id, poll_url: route('client.runs.progress', $run) }`.

### 2.2 Опростен прогрес (poll)
- `Client\FlowRunController@progress(FlowRun $run)` (guard по фирма):
  - Изчисли: `total` = брой активни възли в версията; `done` = `nodeRuns` със `status=completed`; `current` = първият `running` възел (display name).
  - Върни JSON:
    ```json
    {
      "status": "pending|running|completed|failed|waiting_approval",
      "percent": 0-100,
      "step_index": 2, "step_total": 5,
      "step_label": "Пишем съдържанието",     // приятелско име на текущия възел
      "done": false, "failed": false,
      "result_url": null | "/runs/{id}/result",
      "error": null | "съобщение"
    }
    ```
  - **Не** връщай вътрешни детайли (модели, цени, raw, prompts). `step_label` = `flowNode->name` (човешко), без config.
- Преизползвай идеята на `parseRunProgress`, но дръж клиентския изход опростен. Ако възел е по име технически (`bg_text_corrector`, `qa_verifier`) — мапни към приятелски етикет или ги скрий от брояча (по избор; минимум: ползвай `name`).

### 2.3 Alpine компонент на картата
- В `client/flows/index.blade.php` всяка карта = `x-data` с `state: 'idle'`, `percent`, `label`, `resultUrl`.
- „Изпълни“ → POST `client.flows.run` → стартира `setInterval` poll на `progress` на 2s → update бар/етикет → при `completed` покажи голям бутон „Резултат“ (`resultUrl`), при `failed` покажи грешка + „Опитай пак“.
- Прогрес барът: `<div>` с `width: percent%`, цвят `bg-primary`/`accent` (токени). Етикет: „Стъпка {step_index}/{step_total} · {step_label}“.

### 2.4 Резултат
- `Client\FlowRunController@result(FlowRun $run)` (guard) → view `client/runs/result.blade.php`.
- Рендирай `final_output` като Markdown → HTML **с вече ползвания в проекта подход**: `marked` + `DOMPurify` от CDN (виж `resources/views/companies/knowledge.blade.php` и `runs/show.blade.php`: `marked.parse(text, { breaks:true, gfm:true })`, после `DOMPurify.sanitize`). Не добавяй нова сървърна библиотека.
- Покажи изображения, ако `final_output`/контекстът съдържа такива.
- Бутони: „Изпълни пак“ (POST `client.flows.run`), „Назад към Flow-а“ (`client.flows.show`).

**Критерии:** „Изпълни“ от карта показва жив прогрес с „коя стъпка/агент“; при край се появява „Резултат“; страницата с резултат рендира финалния изход чисто, без технически детайли; всичко е scope-нато към фирмата.

---

## Фаза 3 — Детайли на Flow + редакция на описанието

- `Client\FlowController@show(Flow $flow)` (guard) → view `client/flows/show.blade.php`:
  - хедър: заглавие, статус (`x-badge`), голям бутон „Изпълни“ (същият Alpine прогрес или редирект към lightweight run view),
  - пълно описание + „Редактирай описанието“ (inline `x-textarea` → PUT `client.flows.update-description`),
  - история на runs (дата/статус/продължителност) — всеки ред → `client.runs.result`,
  - предишни резултати (успешни runs) — бърз достъп.
- `Client\FlowController@updateDescription(Request, Flow $flow)`: валидирай `description`, `$flow->update(['description'=>…])`, back с success.

**Критерии:** детайлите показват описание + история + резултати; редакцията на описанието работи; никакъв граф/агент не се вижда.

---

## Фаза 4 — ⭐ Разговорен създател (Wizard)

Това е най-голямата фаза. Преизползва queue+token-poll шаблона на Builder Copilot (`FlowAssistantController` + `AssistantTurnJob` + `AgentLoop`) и патърна за структуриран изход на planner-а.

### 4.1 Конфигурация
- `config/services.php` → нов блок:
  ```php
  'client_wizard' => [
      'provider' => env('CLIENT_WIZARD_PROVIDER', 'openai'),
      'model'    => env('CLIENT_WIZARD_MODEL', 'gpt-4o'),
      'max_steps'=> (int) env('CLIENT_WIZARD_MAX_STEPS', 6),
      'max_questions' => (int) env('CLIENT_WIZARD_MAX_QUESTIONS', 8),
      'history_limit' => 20,
  ],
  ```
- `.env`: `CLIENT_WIZARD_PROVIDER=openai`, `CLIENT_WIZARD_MODEL=gpt-4o`.

### 4.2 Данни (миграции + модели)
- `flow_drafts`: `id, company_id (FK), user_id (FK nullable), session (uuid, index), status (string default 'interviewing'), title (nullable), description (text nullable), answers (json nullable), flow_id (FK nullable nullOnDelete), timestamps`.
- `flow_draft_messages`: `id, flow_draft_id (FK cascade), role (string), content (text nullable), payload (json nullable), status (string default 'completed'), error (text nullable), cost_usd (decimal nullable), timestamps`.
- Модели `FlowDraft`, `FlowDraftMessage` с релации и casts (`answers`,`payload` → array).

### 4.3 Service — `ClientFlowWizardService`
`app/Services/ClientFlowWizardService.php`. По модел на `BuilderAssistantService`, но изходът е **структуриран JSON**, не graph ops.

- `providerModel(): array` — от `config('services.client_wizard')`; fallback към cloud provider с tool calling (като copilot-а).
- `isAvailable(): bool` — има ли API ключ.
- Инструменти (read-only) през `AgentLoop`:
  - `search_company_knowledge(query)` → `KnowledgeService` hybrid search → кратък текст с факти/чанкове.
  - `web_search(query)` → `BraveSearchService` → топ резултати (заглавие+snippet).
- `turn(FlowDraft $draft, FlowDraftMessage $userMessage, ?callable $onStage): array` :
  1. Сглоби messages: системен промпт (роля + правила за въпроси + чеклист за пълнота + ИНСТРУКЦИЯ да върне само JSON по схемата) + история (`history_limit`) + текущия user вход (или избрания отговор от формата).
  2. `LlmUsage::take()`; `LlmContext::set([...company/flow_draft...])`.
  3. Пусни `AgentLoop::run(provider, model, messages, tools, executor, maxSteps, options, onToolCall:$onStage, wrapUpPrompt: "...върни валиден JSON по схемата...")`.
  4. Парсни финалния текст като JSON; **валидирай схемата** (phase, question.input_type ∈ {radio,checkbox}, options[].value/label, allow_other bool). При невалиден — един retry с nudge; пак невалиден → graceful fallback (свободен текстов въпрос).
  5. Принудителна чернова: ако зададените въпроси за тази draft сесия ≥ `max_questions` → инжектирай в промпта „СПРИ да питаш, върни phase=ready с финално описание“.
  6. Върни `{ phase, reply, question|null, description_draft|null, recap|null, suggested_title|null, cost_usd }`.

**Системен промпт (скица — постави в service-а):** ролята от §8.3 на спецификацията + изрични правила: по един въпрос на стъпка; radio/checkbox + „Друго“ при неизчерпателност; не питай за тялото, питай за структурата/добавките; преди тема — изследвай знания→уеб и предложи 3–5; води `description_draft` на български; премини на `ready`, когато намерение+платформа+тема+компоненти+тон+език са ясни; върни **само** JSON по схемата.

### 4.4 Job + контролер (queue + token poll)
- `app/Jobs/ClientWizardTurnJob.php` (огледало на `AssistantTurnJob`): чете draft+message, вика `ClientFlowWizardService::turn`, ъпдейтва кеша по `token` с `{status, stage, result}` и записва assistant `FlowDraftMessage` (payload=question/recap, content=reply).
- `Client\FlowWizardController`:
  - `create()` → стартира/намира draft за (company,user) сесия, view `client/flows/wizard.blade.php` (вж. 4.5).
  - `send(Request)` → валидирай `message` (текст) **или** `answer` (structured: key + values[] + other), `session`. Запиши user `FlowDraftMessage` (payload=answer при форма), създай pending assistant message, генерирай `token`, `Cache::put`, dispatch `ClientWizardTurnJob`, върни `{token, draft_id}`. (Ако `!isAvailable()` → 503 с приятелско съобщение, като copilot-а.)
  - `status(token)` → върни кеша (`pending|completed|failed` + при completed: reply, question, description_draft, recap, suggested_title).
  - `history(FlowDraft)` → съобщенията на draft-а (за reload).
  - `build(FlowDraft)` → „Готово, Генерирай“: вж. 4.6.

### 4.5 View `client/flows/wizard.blade.php`
Един Alpine компонент с две зони:
- **Чат панел:** списък съобщения; bot съобщение може да съдържа **форма-въпрос** (radio/checkbox по `question.input_type`, опции от `question.options`, и при `allow_other` — radio/checkbox „Друго“ + textarea). „Изпрати отговор“ → `send` с structured `answer`. Има и свободно текстово поле за уточнения.
- **Платно:** `x-input` заглавие (prefill от `suggested_title`) + голяма `x-textarea` описание, която на всеки `status=completed` се **дописва** от `description_draft` (ако клиентът не я е пипал ръчно — пази „dirty“ флаг). Textarea-та е свободно редактируема.
- **Стартови чипове** (при празен чат): «Пост за социална мрежа», «Одит на бизнес», «Анализ на конкуренция», «SEO», «Друго…» → попълват първото съобщение.
- **Бутон „Готово, Генерирай“** (изпъкващ): enabled винаги, но **подчертан**, когато последният `status` е `ready`. Натискане → `build`.
- Polling на `status` на ~1.5–2s (като copilot-а), стейдж етикети („Мисля…“, „Проверявам знанията…“, „Търся в интернет…“).

### 4.6 Билд стъпка (преизползва pipeline-а)
- Изнеси ядрото на `FlowController::generateAgents` в споделен service `AgentGenerationLauncher::launch(company_id, flow_id, name, description, level, phases=[]): string $token` (връща token; пуска фоновата команда). Рефакторирай **и** админ `generateAgents` да го вика (без дублирана логика — спазва „no legacy“).
- `FlowWizardController@build(FlowDraft $draft)`:
  1. Валидирай, че има title+description (от draft или подадени от формата).
  2. `$flow = $company->flows()->create(['name'=>title,'description'=>description,'status'=>'draft'])`.
  3. `$level = ModelLevel::fromRequest($company->settings['model_level'] ?? null)` (връща `Medium` при липса — вече съществуващ хелпър).
  4. `$token = AgentGenerationLauncher::launch(company->id, flow->id, title, description, $level->value, [])`.
  5. Маркирай draft `building`, запиши `flow_id`.
  6. Върни `{token, flow_id, status_url: route('flows.generation-status',$token), redirect_url: route('client.flows.show',$flow)}`.
- Клиентски popup (в wizard view-то) polls `flows/generation-status/{token}` (съществуващия endpoint), показва етапите (като админ popup-а). При `completed` → redirect към `client.flows.show`. Маркирай draft `completed`.

**Критерии (Фаза 4):**
- Кратко „искам пост“ → ботът пита платформа (radio+Друго) → тема (3–5 предложения от знания/уеб) → компоненти (checkbox, без тялото) → попълва описанието → пита „достатъчно ли е“.
- Клиентът може да редактира textarea-та и/или да пише още съобщения (нов Q&A цикъл).
- „Готово, Генерирай“ създава Flow + пуска генерирането + popup с прогрес + редирект към детайлите.
- Работи и за друга сфера (одит/конкуренция/SEO) без код-промени.
- Невалиден JSON/липсващ ключ деградира меко, не чупи UX.

---

## Фаза 5 — Per-фирма ниво на модела (админ)

- Миграция не е нужна (`companies.settings` JSON вече съществува).
- Админ форма за фирма (`companies/create`, `companies/edit`): добави `x-select` „Ниво на модела за клиентски flows“ с `ModelLevel` стойностите (low/medium/high/ultra/god), default `medium`. Запазвай в `settings['model_level']`.
- `CompanyController@store/@update`: мерджни `model_level` в `settings`.
- Клиентската билд стъпка (4.6) вече чете това ниво.

**Критерии:** админ може да зададе ниво per-фирма; новите клиентски flows на тази фирма се генерират на това ниво.

---

## Фаза 6 — Полиране и приемане

- `vendor/bin/pint` върху всичко ново.
- Проверка на guard-овете: клиент от фирма A не вижда/стартира flow на фирма B (ръчно: смени `client_company_id`).
- Приятелски съобщения при: няма Horizon, няма API ключ за wizard-а, flow без активни възли, изтекъл token.
- Достъпност: фокус-стилове, `aria-current`, reduced-motion (наследява се от токените).
- Кратък запис в `docs/` или README как се вдига поддомейнът локално.

---

## Приложение А — `routes/client.php` (скелет)

```php
<?php
use App\Http\Controllers\Client;
use Illuminate\Support\Facades\Route;

// Публични (без сесия)
Route::get('login', [Client\AuthController::class, 'showLogin'])->name('client.login');
Route::post('login', [Client\AuthController::class, 'login'])->name('client.login.post');
Route::get('login/companies/{company}/users', [Client\AuthController::class, 'usersForCompany'])->name('client.login.users');
Route::post('logout', [Client\AuthController::class, 'logout'])->name('client.logout');

// Защитени (client_auth)
Route::middleware('client_auth')->group(function () {
    Route::get('/', [Client\DashboardController::class, 'index'])->name('client.dashboard');

    // Flows
    Route::get('flows', [Client\FlowController::class, 'index'])->name('client.flows.index');
    Route::get('flows/create', [Client\FlowWizardController::class, 'create'])->name('client.flows.create');
    Route::get('flows/{flow}', [Client\FlowController::class, 'show'])->name('client.flows.show');
    Route::put('flows/{flow}/description', [Client\FlowController::class, 'updateDescription'])->name('client.flows.update-description');

    // Изпълнение + прогрес + резултат
    Route::post('flows/{flow}/run', [Client\FlowRunController::class, 'run'])->name('client.flows.run');
    Route::get('runs/{run}/progress', [Client\FlowRunController::class, 'progress'])->name('client.runs.progress');
    Route::get('runs/{run}/result', [Client\FlowRunController::class, 'result'])->name('client.runs.result');

    // Wizard (чат)
    Route::post('wizard/send', [Client\FlowWizardController::class, 'send'])->name('client.wizard.send');
    Route::get('wizard/status/{token}', [Client\FlowWizardController::class, 'status'])->name('client.wizard.status');
    Route::get('wizard/{draft}/history', [Client\FlowWizardController::class, 'history'])->name('client.wizard.history');
    Route::post('wizard/{draft}/build', [Client\FlowWizardController::class, 'build'])->name('client.wizard.build');
});
```
> Бел.: при поддомейн group няма `/client` префикс — пътищата са от корена на поддомейна.

---

## Приложение Б — Нови/променени файлове (чеклист)

**Нови контролери** (`app/Http/Controllers/Client/`): `AuthController`, `DashboardController`, `FlowController`, `FlowRunController`, `FlowWizardController`.
**Нов middleware:** `ClientAuth`.
**Нови services:** `ClientFlowWizardService`, `AgentGenerationLauncher` (изнесен от `FlowController`).
**Нов job:** `ClientWizardTurnJob`.
**Нови модели:** `FlowDraft`, `FlowDraftMessage`.
**Нови миграции:** `add_company_and_role_to_users_table`, `backfill_owner_users`, `create_flow_drafts_table`, `create_flow_draft_messages_table`.
**Нов layout:** `layouts/client.blade.php`.
**Нови view-та:** `client/auth/login`, `client/dashboard`, `client/flows/{index,show,wizard}`, `client/runs/result`.
**Променени:** `bootstrap/app.php` (routing+alias), `config/app.php` (client_domain), `config/services.php` (client_wizard), `resources/css/app.css` (`.line-clamp-4`), `User`/`Company` модели, `CompanyController` (owner при create + model_level), админ company форма, `FlowController::generateAgents` (рефактор към `AgentGenerationLauncher`), `.env`(.example).

---

## Приложение В — Ред на изпълнение и зависимости

1. **Фаза 0** (основа) — блокира всичко.
2. **Фаза 1** (табло/карти) и **Фаза 5** (per-фирма ниво) — независими, могат паралелно.
3. **Фаза 2** (run/прогрес/резултат) — след Фаза 1.
4. **Фаза 3** (детайли) — след Фаза 2.
5. **Фаза 4** (wizard) — след Фаза 0; билд стъпката (4.6) зависи от `AgentGenerationLauncher` и Фаза 5 (ниво на фирмата); редиректът ѝ зависи от Фаза 3.
6. **Фаза 6** — накрая.

Изпълнявай и проверявай в браузъра след всяка фаза, преди да минеш нататък.
