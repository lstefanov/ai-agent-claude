# Динамичен Agent Planner — „агентът, който създава агенти"

> Дизайн + имплементационен план за пълно динамично генериране на агенти от
> описанието на Flow-а: **Създаване на Flow → автоматично планиране на агенти →
> preview/одобрение в builder-а → изпълнение → краен резултат.**
>
> Статус: **Фази 1–4 имплементирани** (раздели 7–8). Отворените идеи са в
> раздел 9.

---

## 1. Идеята (от заданието) → как я постигаме

Твоята идея, преформулирана като архитектура:

| Твоя формулировка | Архитектурен компонент |
|---|---|
| „Промптът ще се разбива на отделни ключови думи" | **Фаза А: Intent Analysis** — GPT извлича структуриран intent (deliverable, източници, entities, регион, подзадачи) |
| „Агенти on the fly, всеки с една конкретна задача" | **Фаза Б: Pipeline Design** — GPT проектира DAG от single-responsibility агенти; нов тип `custom` (GenericAgent) позволява агенти извън каталога |
| „Да настрои промптовете, токените, тона, температурата" | Planner-ът връща пълна спецификация per агент (prompts, temperature, output size, tools, provider); кодът налага guard-rails |
| „Агентът, който създава агенти — ChatGPT, после Claude" | `GeneratorService` вече е provider-agnostic (`GENERATOR_PROVIDER=openai|anthropic|ollama`) — Claude се включва само с env промяна |
| „Перфектният flow с идеално настроени агенти" | **Фаза В: Plan Critique** — втора GPT проверка на плана + детерминистична валидация (цикли, зависимости, задължителни агенти) |

Това е утвърденият в индустрията **plan-and-execute / orchestrator-workers**
модел: planner LLM проектира DAG от задачи, executor-и ги изпълняват
паралелно, а изходите се събират (fan-in). Виж източниците в раздел 11.

---

## 2. Какво вече имаше в кода (и се преизползва)

Кодовата база беше по-напред от CLAUDE.md описанието:

- **DAG изпълнение с реален паралелизъм** — `GraphFlowExecutor` (топологични
  „вълни", `Bus::batch`, fan-in, best_effort/fail_fast политика).
- **Граф модел** — `flow_nodes` + `flow_edges` + Drawflow builder
  (`GraphNormalizer` е единственото място, което разбира Drawflow формата).
- **Provider switch за генериране** — `GeneratorService` (ollama | anthropic |
  openai) + `AgentGenerationLog` одит на всяка генерация.
- **Генератор на агенти** — `AgentGeneratorService`: LLM генерация с
  `uid`/`depends_on`, plus детерминистичен „скелет" за сайт-анализ (въведен,
  защото малките локални модели бъркаха DAG структурата; **премахнат** при
  legacy чистката — planner-ът пое всичко).
- **Изпълнение на възел** — `NodeExecutorService` (вход от преките
  predecessors, QA gate с retry, namespaced изходи — без загуба на информация).
- **23 конкретни агент класа + 5 tool-а** (`web_search`, `scrape_page`,
  `crawl_site`, `discover_urls`, `google_reviews`) + ComfyUI за изображения.

**Какво липсваше:** умен planner (двуфазно „мислене"), гарантирано валиден
JSON, агенти извън фиксирания каталог от типове, и възможност отделен агент да
се изпълни през OpenAI вместо Ollama.

---

## 3. Новата архитектура на планирането

```
Описание на Flow
      │
      ▼
┌───────────────────────────────────────────────────────────┐
│ FlowPlannerService (новият „агент, който създава агенти") │
│                                                           │
│  Фаза А: analyzeIntent()      → структуриран intent JSON  │
│  Фаза Б: designPipeline()     → DAG план с пълни спецове  │
│  Фаза В: critiquePlan()       → проверка + 1 repair pass  │
│  (всяка фаза: Structured Outputs, логва се в              │
│   agent_generation_logs → панел „Лог на генерирането")    │
└───────────────────────────────────────────────────────────┘
      │  агенти (същият формат като досега: uid, depends_on…)
      ▼
AgentGeneratorService::finalizePlannedAgents()
  → normalizeAgent (модели, num_predict guard-rails)
  → ensureQaVerifierLast / ensureBgTextCorrectorBeforeQa
  → dedupeAgents → finalizeDependencyGraph (Kahn, без цикли)
      │
      ▼
Builder (Drawflow) — PREVIEW: потребителят вижда графа,
редактира промпти/връзки, запазва → чак тогава Run
      │
      ▼
GraphFlowExecutor → вълни → NodeExecutorService → Ollama / OpenAI
```

### 3.1 Фаза А — Intent Analysis („мисленето")

Една евтина GPT заявка със **strict JSON schema** (Structured Outputs —
моделът физически не може да върне невалиден JSON). Извлича точно това, което
описваш в примерите си:

```json
{
  "deliverable": "report",
  "deliverable_description": "пълен репорт за сайта",
  "language": "bg",
  "entities": [{"name": "primelaser.bg", "kind": "website", "url": "https://primelaser.bg/"}],
  "information_sources": ["target_website", "google_reviews"],
  "region": null,
  "key_tasks": ["обхождане страница по страница", "секциониране по тип съдържание",
                 "цени в таблица", "контакти дословно", "правописна проверка"],
  "needs_image": false, "needs_hashtags": false, "competitor_focus": false,
  "improvement_suggestions": false,
  "complexity": "medium"
}
```

### 3.2 Фаза Б — Pipeline Design

Втора GPT заявка: intent + **capability registry** (виж 3.4) + правила за
проектиране + компактни few-shot примери (твоите Пример 1 и Пример 3 като
еталони). Изход: масив от агенти, всеки с:

- `uid`, `name`, `type` (от каталога **или** `custom`), `custom_tools[]`
- `role` (вкл. *защо* агентът съществува — вижда се в builder-а)
- `system_prompt`, `prompt_template` (с `{{url}}`, `{{input}}`, `{{node:Име}}`)
- `depends_on[]` — DAG с разклонения и fan-in
- `provider` (`ollama` | `openai`) + `model_category` (bulgarian_text /
  structured / reasoning / fast_qa) — конкретният модел се избира от код
- `temperature`, `output_size` (short/medium/long/unlimited → num_predict)
- `qa_custom_prompt` — конкретна QA проверка за изхода на агента

### 3.3 Фаза В — Plan Critique (evaluator-optimizer)

Трета, евтина заявка: „Ти си QA на pipeline планове. Ето intent + план.
Намери дефекти: липсващи стъпки спрямо key_tasks, грешни зависимости,
агенти без смисъл, неправилни tools." При проблеми — една поправка
(repair pass). После кодът налага твърдите гаранции (детерминистично):
точно един `qa_verifier`, един `bg_text_corrector` предпоследен, валидни
`depends_on`, без цикли, num_predict по тип.

> Принципът навсякъде: **LLM предлага, кодът гарантира.**

### 3.4 Capability Registry

Planner-ът получава машинно-четим каталог какво реално умее системата:

- **Типове агенти** — от активните `AgentTemplate` записи (както досега).
- **Tools** — `web_search` (Brave), `scrape_page`, `crawl_site`,
  `discover_urls`, `google_reviews` + `image_prompt`/ComfyUI за картинки.
- **Модели** — инсталираните Ollama модели + OpenAI runtime опция.

Така GPT никога не „измисля" несъществуващ tool, а когато му трябва
комбинация извън каталога — създава `custom` агент с изброени tools.

### 3.5 On-the-fly агенти: тип `custom` (GenericAgent)

Нов агент клас, конфигуриран изцяло от плана:

```
type: custom
config:
  tools: ["web_search", "scrape_page"]
  tool_params: { search_query: "{{topic}} новини 2026", max_results: 10 }
```

`GenericAgent` изпълнява tools-а детерминистично (търсене → скрейп на
намерените URL-и → събира материал), подава материала към LLM промпта и
връща изхода. Това покрива „може и да не са от съществуващите — нови,
конкретно за този flow", без да пишем нов PHP клас за всяка идея.

### 3.6 Hybrid execution: cloud планира + опционално изпълнява

Per-agent provider, кодиран в полето `model` с префикс
(`App\Support\PaidModel` е единственото място, което разбира префиксите
и разделението на евтини/premium провайдъри):

- `mistral-nemo` → Ollama (както досега, безплатно, локално)
- `gemini/gemini-3.1-flash-lite` → Google Gemini (free tier / почти безплатно)
- `deepseek/…`, `qwen/…`, `xai/…` → евтини cloud провайдъри
- `openai/gpt-4o-mini` → OpenAI Chat Completions (premium)
- `anthropic/claude-haiku-4-5` → Anthropic Messages (premium)

Routing-ът е на едно място (`OllamaService::chat()` делегира префикснатите
модели на `OpenAiChatService`/`AnthropicChatService`), така че **нито един
агент клас не се променя**.

Колко „скъпо" се сглобява pipeline-ът се избира per генерация чрез
**ниво на моделите** (`App\Support\ModelLevel`, default `medium`) — опция в
генериращия popup на builder-а и в A/B страницата (`--level` на
`flows:plan-ab`). Промптът насочва планера към разпределението на нивото, а
`FlowPlannerService::resolveProviderPins()` го налага детерминистично:

| Ниво | Разпределение |
|---|---|
| `low` (Ниско) | Основно Ollama; до 3 евтини cloud pin-а за стъпките с най-голям fan-in. |
| `medium` (Средно) | Евтин cloud (gemini/deepseek/qwen/xai) за повечето агенти; поне 3 остават на Ollama; без premium. |
| `high` (Високо) | Всички агенти на евтин cloud; до 3-те най-критични стъпки на OpenAI. |
| `ultra` (Ултра) | Всички агенти на OpenAI (runtime модела); до 2-те най-критични на Anthropic. |
| `god` (GOD) | Всеки агент на най-скъпия ФЛАГМАН — `gpt-4o` или `claude-sonnet-4-6` (`PaidModel::pinTop`), task-aware split между OpenAI и Anthropic, БЕЗ лимит за нито един. |

На всички нива: vision агентите остават на локален multimodal модел, а
агентите, които пишат български текст за краен потребител, остават на BgGPT
(`AgentGeneratorService` маха платен pin) — освен на `ultra`/`god`, където и те
отиват на платен модел. Моделът per провайдър се контролира с
`<PROVIDER>_RUNTIME_MODEL` (а флагманът на `god` — с `OPENAI_FLAGSHIP_MODEL` /
`ANTHROPIC_FLAGSHIP_MODEL`).

Нивото се **персистира на шаблона** (`flow_versions.model_level`) и се вижда
като цветен badge в toolbar-а на builder-а. Оттам може и да се **сменя**:
`POST flows/{flow}/graph/relevel` преизчислява модела на всеки нод за новото
ниво и връща приблизителен разход на run (реалните токени от последния успешен
run, иначе допускания) + причина per нод, които builder-ът показва ПРЕДИ запис.
Ръчна смяна на модел на нод + запис → ниво `custom`.

### Model Router — кой точно евтин провайдър за кой агент

`ModelRouterService` е единственото място, което превръща „този агент, това
ниво" в конкретен pin — и при генерация, и при смяна на ниво:

1. **Профил на задачата** per агент: фасетни тежести 0–10 (research,
   extraction, analysis, synthesis, json_strict, bg_language, long_context,
   creative, speed), изведени детерминистично от типа, tools-овете, конфига
   (map_reduce/num_predict/temperature), fan-in и ключови думи в промпта.
2. **Smart режим** (`MODEL_ROUTING=smart`, default): безплатен LLM
   (`MODEL_ROUTER_PROVIDER`, default gemini) чете ролята/промпта на всеки агент
   в ЕДНА batched заявка и прецизира тежестите + дава причина на български.
   Логва се като фаза `model_routing` в agent_generation_logs (с цена).
   Провал → тих fallback към детерминистичния профил.
3. **Скоринг** срещу capability матрицата (`config/model_router.php`):
   `Σ тежест × сила − цена × penalty(ниво) − spread decay + глас на планера +
   история`. Spread decay-ят пази free tier квотите (анти-монопол); гласът на
   планера е бонус, не решение. Hard filters: API ключ + контекстен прозорец.
4. **Историческо учене**: `node_runs.qa_score` (пише се от NodeExecutorService
   при всеки step-QA) + fail rate per (провайдър, тип агент) от последните 30
   дни дават бонус/малус — рутерът се самокоригира по реалното представяне.
5. Premium слотовете на high/ultra се раздават по **synthesis тежест** (не по
   гол fan-in); verifier-ът никога не взима premium слот. BG/vision
   изключенията важат както при нивата.
6. **GOD**: всеки нод се пин-ва на флагман на OpenAI или Anthropic
   (`PaidModel::pinTop` → gpt-4o / claude-sonnet-4-6) — по-силният по задачата
   (`cost_penalty['god']=0` → чист task-fit, spread е само лек tiebreaker),
   без квоти; само vision остава локално.

---

## 4. Поток от гледна точка на потребителя

1. **Създаване на Flow** — име + описание на свободен текст (както сега).
2. **Генериране** — builder-ът показва на живо фазите: „Анализирам
   заданието…" → „Проектирам pipeline…" → „Проверявам плана…".
3. **Preview + одобрение** — графът се появява в Drawflow builder-а.
   Всеки възел носи `role` с обосновка *защо съществува*. Пълните intent /
   plan / critique JSON-и са в панела „Лог на генерирането". Потребителят
   редактира каквото иска и **запазва** — това е одобрението (без запазен
   граф няма run).
4. **Run** — както досега: вълни, паралелни клонове, QA gates, финален изход.

## 5. Поведение при грешка (без legacy fallback-и)

> Решение от 2026-06-06 (втора итерация): **целият legacy код е премахнат** —
> детерминистичният сайт-скелет, едно-промптовата Ollama генерация, старото
> последователно изпълнение по `agents.order` и Agent CRUD-ът. Planner-ът е
> ЕДИНСТВЕНИЯТ път за генериране.

1. `GENERATOR_PROVIDER=openai|anthropic` (cloud, най-добри планове) или
   `ollama` (БЕЗПЛАТНО локално планиране през Ollama structured outputs на
   `OLLAMA_PLANNER_MODEL`) → **FlowPlannerService** за всички flows. Без
   валиден ключ/работещ Ollama UI-ят връща ясна грешка (503) още преди старта.
2. Провалена planner фаза → генерацията се маркира failed с точната грешка
   (вижда се в попъпа и в „Лог на генерирането"). Без тихи деградации.
3. Изпълнение: `openai/*` модел без ключ → node fail с логната грешка →
   смяна на модела в builder-а. Ollama моделите се auto-pull-ват както досега.

---

## 6. Как планът покрива четирите ти примера

**Пример 1 — „Пълен репорт за https://primelaser.bg/"**
Intent: report + target_website + google_reviews. План: `site_context` →
fan-out (`deep_researcher` обхожда страниците + `review_analyzer`) →
анализатори по клон → fan-in `report_writer` (цени дословно в таблица,
контакти непокътнати, описания резюмирани — инструкциите идват от
key_tasks) → `bg_text_corrector` → `qa_verifier`. Същата топология като
доказания скелет — но вече проектирана динамично, не хардкодната.

**Пример 2 — „…и какво да се подобри"**
Същото + intent флаг `improvement_suggestions: true` → planner добавя
агент „Одитор на сайта" (custom или analyzer) със собствен клон, чийто
изход се влива във финалния доклад като секция „Препоръки".

**Пример 3 — „Facebook пост за Game Sport Center Русе + картинка"**
Intent: social_post, needs_image, needs_hashtags, region=Русе. План:
`researcher` (бизнесът) → `trend_researcher` (тенденции по събраните
ключови думи) → `analyzer` → **fan-out към 3 генератора** (`content_bg`
текст, `hashtag_generator`, `image_prompt`→ComfyUI) → **fan-in
`caption_writer`** (сглобява пост от трите входа чрез `{{node:Име}}`) →
`bg_text_corrector` → `qa_verifier`. Точно твоите стъпки 1–7 —
`GraphFlowExecutor` поддържа този fan-out/fan-in нативно.

**Пример 4 — „Ценови мониторинг на конкуренти в Русе"**
Intent: report, competitor_focus, region=„Русе и региона". План:
`researcher` (бизнесът) → `summarizer` (ключови думи) → custom агент
„Откривател на конкуренти" (web_search, изкарва 20+ сайта) →
`competitor_profiler` / custom скрейпър по сайтовете (map-reduce, виж
бележката за токени) → `analyzer` (цени по услуга) → `report_writer` →
корекция → QA.

> **Бележка за „неограничените токени" (Пример 4, стъпка 4):** реално
> неограничен контекст няма — решението е вече наличният **map-reduce**
> режим на deep_researcher (всяка страница се резюмира поотделно,
> резюметата се събират), плюс `num_predict=-1` за research типовете.
> Planner-ът включва `map_reduce: true` за многосайтови скрейпове.

---

## 7. Имплементирани промени (Фаза 1 — тази сесия)

**Нови файлове**

| Файл | Какво прави |
|---|---|
| `app/Services/FlowPlannerService.php` | Тристепенният planner (intent → pipeline → critique) + capability registry + детерминистично довършване (модели, qa wiring, config) |
| `app/Services/OpenAiChatService.php` | Runtime chat към OpenAI (`openai/<model>`): мапва Ollama опциите, retry guards за `max_completion_tokens`/`temperature`, structured outputs |
| `app/Agents/GenericAgent.php` | Тип `custom` — config-driven tools + LLM стъпка |

**Променени файлове**

| Файл | Промяна |
|---|---|
| `app/Services/GeneratorService.php` | + `chatJson()` — Structured Outputs за openai (strict schema), prompted JSON за anthropic |
| `app/Services/AgentGeneratorService.php` | Planner-only: нормализация + твърди гаранции върху плана; `custom` тип не се ремапва; уважава `openai/` модели |
| `app/Agents/AgentFactory.php` | `custom` → GenericAgent с пълния tool belt |
| `app/Services/OllamaService.php` | `chat()`/`chatBatch()` рутират `openai/*` модели към OpenAiChatService |
| `app/Services/NodeExecutorService.php` | `ensureModelInstalled()` пропуска `openai/*` |
| `config/agent_types.php` | + тип `custom` („Универсален агент") |
| `config/services.php` | + `openai.runtime_model`, planner настройки (critique on/off, max openai agents); `GENERATOR_PROVIDER` default `openai` |
| `.env` | `GENERATOR_PROVIDER=openai`, `OPENAI_API_KEY`, `OPENAI_RUNTIME_MODEL` |

**Премахнат legacy код (втора итерация, същия ден):**

- `AgentGeneratorService`: едно-промптовата Ollama генерация, JSON
  recovery хаковете, `needsWebResearch`/`ensureResearcherFirst` и целият
  детерминистичен сайт-скелет (~600 реда) — planner-only.
- `GeneratorService`: ollama provider клонът (планирането изисква
  openai|anthropic); `OpenAiChatService` — компат-retry логиката за стари
  API параметри (само `max_completion_tokens`).
- Legacy изпълнение по `agents` таблицата: Agent CRUD (контролер методи,
  routes, `views/agents/`), `ExecuteAgentJob`, `Flow::agents()`,
  `FlowRun::agentRuns()`, legacy клонът в `runs/show` и DB lookup-ът в
  `BgTextCorrectorAgent`. `Agent`/`AgentRun` остават само като transient
  runtime DTO-та за bridge-а в `NodeExecutorService`.
- Еднократните flow-4 fix сийдъри (6 файла) — `db:seed` сега сее само
  `LlmModelSeeder` + `AgentTemplateSeeder` (системните агенти).

## 8. Фази 2–4 — ИМПЛЕМЕНТИРАНИ (2026-06-06)

**Фаза 2 — Учене от успешни планове.** Таблица `plan_library` (едно entry
на flow). Жизнен цикъл: генериране → intent-ът се записва във
`flows.plan_intent` → **записът на графа в builder-а = одобрение** →
`PlanLibraryService::captureApprovedPlan()` прави snapshot (intent +
компактен вид на графа) със status `candidate` → първият **успешен run**
(`GraphFlowExecutor::finalize`) го прави `proven` и трупа `runs_count` +
среден step-QA score. При ново планиране `fewShotBlock()` намира 1–2
най-близки proven плана (структурно сходство: deliverable, източници,
флагове; tie-break по QA/runs) и ги инжектира като примери във Фаза Б.
Редакция + повторен запис на графа връща entry-то в `candidate` — новата
топология трябва отново да се докаже.

**Фаза 2 — Cost tracking.** `App\Support\LlmUsage` акумулира usage от
всички платени заявки (OpenAI + Anthropic, ценоразпис в
`config/services.php` → `pricing`). Записва се в
`agent_generation_logs.{prompt_tokens,completion_tokens,cost_usd}` (per
planner фаза) и `node_runs.{...}` (per възел: openai/* изпълнение +
ревизии). Run viewer-ът показва обща цена на run-а (badge до прогреса);
`poll` връща `cost_usd`.

**Фаза 3 — Адаптивно препланиране + watchdog.** В `NodeExecutorService`:
при провален QA gate първият retry е обикновен (евтин), от втория нататък
`FlowPlannerService::reviseAgent()` получава спецификацията на агента +
входа + лошия изход + QA присъдата и връща поправена версия (нови
промптове, температура, опционално `escalate_to_openai` → възелът се
изпълнява на платения provider от `PLANNER_ESCALATION_PROVIDER` за този run).
Watchdog: изроден изход (празен/<20 знака/placeholder шаблон) тригерира
ревизия още преди QA.
Ревизията се прилага САМО in-memory за текущия run — записаният граф не се
пипа; одитът е в `flow_runs.context['replan']` (и в poll отговора).
Изключва се с `PLANNER_ADAPTIVE=false`.

**Фаза 4 — Anthropic structured outputs + A/B.** `GeneratorService::chatJson`
при `anthropic` ползва ПРИНУДИТЕЛЕН tool call (схемата става `input_schema`,
`tool_choice` я заковава) — без текстов парсинг. A/B сравнение:
`php artisan flows:plan-ab {flowId}` планира същия flow с двата провайдъра
и извежда таблица с топологиите + разлики по типове; пълните планове и
цената са в `agent_generation_logs`. Изисква `ANTHROPIC_API_KEY`.

## 9. Как се ползват новите функционалности (UI справочник)

Къде в сайта се намира всичко, въведено от Фази 1–4 + надстройките:

### 9.1 Генериране на план (Фаза 1)
**Къде:** Builder-ът на flow-а (`/flows/{id}/builder`) — при нов flow стартира
автоматично; иначе бутонът за генериране в builder-а.
**Какво виждаш:** прогрес попъп с фазите („Анализ на заданието" → „Генериране
на агенти" → „Проверка на плана" → „Финализиране"), после графът се изчертава.
Пълните intent / plan / critique JSON-и + цена per фаза: панел
**„Лог на генерирането"** в builder-а (редове `openai (intent_analysis)`,
`openai (pipeline_design)`, `openai (plan_critique)`).

### 9.2 Одобрение + Plan Library (Фаза 2)
**Къде:** бутонът **„Запази"** в builder-а — записът Е одобрението.
**Какво става отзад:** планът влиза в `plan_library` като *candidate*; след
първия успешен run става *proven* (вижда се в лога: `[PlanLibrary] … plan
proven`). При следващо генериране на подобно задание planner-ът автоматично
получава 1–2 proven плана като примери — без никакво действие от теб.
Редактираш ли графа и запазиш отново → отново candidate (трябва нов успешен run).

### 9.3 Цена на изпълнението (Фаза 2)
**Къде:** страницата на run-а (`/runs/{id}`) — жълт badge **`$0.0123`** горе
вдясно до бутона „Лог" (показва се само ако run-ът е ползвал платени
заявки). Per възел: `node_runs.cost_usd`; per planner фаза: панела „Лог на
генерирането". A/B страницата също показва цена per план.

### 9.4 Адаптивни ревизии (Фаза 3)
**Къде:** страницата на run-а — лилав панел **„🛠 Адаптивни ревизии на
агенти"** се появява, когато planner-ът е поправил агент по време на
изпълнение (от 2-рия QA retry или при изроден изход). Виждаш: кой възел,
защо, дали е ескалиран към OpenAI (badge „⤴ OpenAI").
**Бутон „Приложи в графа":** появява се само ако ревизираният агент после Е
МИНАЛ проверката — копира поправените промптове/температура/модел в графа на
flow-а (flow_nodes + layout), така че следващите runs тръгват с тях. До
клика ревизията важи само за конкретния run.

### 9.5 A/B сравнение на плановете (Фаза 4)
**Къде:** страницата на flow-а (`/flows/{id}`) → бутон **„⚖ A/B план"** →
страница `/flows/{id}/plan-ab`.
**Как се ползва:** три колони — 🦙 Ollama (локален, безплатен), 🤖 OpenAI,
🧠 Anthropic. „Генерирай всички" пуска наличните едновременно, а бутонът
**„▶ Генерирай"** в хедъра на всяка колона планира само с този provider
(„↻ Отново" за повторно генериране). Колоните се пълнят на живо: брой
агенти, време, цена (Ollama показва „безплатно"), списък с агентите
(уникалните за provider-а типове са в лилаво), разгъващи се промптове,
секция „Разлики". Бутон **„✓ Използвай този план"** под избраната колона →
builder-ът се отваря, изчертава плана и го запазва автоматично (= одобрение,
влиза и в plan library). Недостъпен provider (без ключ/изключен Ollama) се
показва избледнен с бележка. CLI вариантът:
`php artisan flows:plan-ab {id} [--provider=ollama|openai|anthropic]`.

### 9.6 Vector retrieval в Plan Library
**Къде:** невидимо — автоматика. При запис на одобрен план intent-ът се
embed-ва (`text-embedding-3-small`, ~$0.000002). Докато proven плановете са
под `PLANNER_VECTOR_THRESHOLD` (100), сходството е структурно; над прага —
косинусово върху embeddings (по-точно при голяма библиотека). Записи без
embedding продължават да се класират структурно.

## 9а. Идеи за следваща гъвкавост (отворени)

Изведени и от 14-те примерни flows (новини, ревюта+отговори, SEO статии,
промо кампании, Reels скриптове, бюлетини, календари, SWOT, win-back, B2B,
Google Ads, challenge, FAQ) — всички те се покриват от каталога, но биха
спечелили от:

1. **Параметризирани runs** — `{{topic}}` да се подава при стартиране
   (поле „Тема на този run" до „Стартирай"). Един flow „SEO статия за
   услуга" → 10 статии за 10 услуги без редакция (Flow 4, 10, 13).
2. **Доставка на резултата (delivery)** — настройка на flow-а „изпрати
   финалния изход към: email / Slack / webhook / файл" (Flow 7, 11 — имейл
   кампаниите сега остават само в run viewer-а).
3. **Човешко одобрение по средата (human-in-the-loop възел)** — run-ът
   спира на възел „Одобрение", известява те, продължава след клик (критично
   за Flow 5/11/12, където изходът отива към клиенти).
4. **Повтарящи се flows с дневник** — Flow 2 (новини) и Flow 8 (календар)
   са периодични; scheduler-ът съществува (`schedule_cron`), липсва
   „дайджест на промените" между две изпълнения (какво е ново спрямо
   миналия run).
5. **Условно рутиране (decision възел с реални клонове)** — тип `decision`
   съществува, но executor-ът не пропуска клонове според решението му
   (напр. Flow 3: „има негативни ревюта → клон с чернови отговори").
6. **Plan Library страница** — разглеждане на proven плановете (QA score,
   runs), ръчно изтриване/закачане като примери.
7. **Company knowledge база** — качени файлове (ценоразпис, графици,
   услуги) като източник за агентите (RAG) — Flow 7/10/15 биха ползвали
   реалните данни на центъра вместо само web.
8. **Серии/кампании като един flow** — multi-output (Flow 11: 3 имейла +
   SMS) вече работи чрез fan-out, но UI за „пакет от резултати" (zip/
   отделни карти) би направил изхода директно използваем.

## 9б. Реализирани надстройки (2026-06-06)

Затваря 9а#1, 9а#2, 9а#5 и поправя два дефекта от ревюто (мъртъв step-QA gate;
блокирана параметризация).

1. **Параметризирани runs (9а#1).** При „▶ Стартирай" в builder-а има бутон ⚙ →
   поле „Тема на този run" + произволни декларирани полета (`flow.settings.inputs`
   = `[{key,label}]`). Стойностите влизат в `flow_runs.context['inputs']` и
   `GraphFlowExecutor::buildSeed` ги мерджва над flow defaults — `{{topic}}`,
   `{{url}}` и произволни `{{ключ}}` стават достъпни в промптите. Webhook
   payload-ът се мапва по същия начин. (`Flow::$fillable` + `flows.settings`
   миграция; `FlowRunController::store`.)

2. **Активен step-QA gate + Фаза 3 (поправка).** `AgentGeneratorService::enableFinalQaGate`
   детерминистично включва QA gate на финалния `bg_text_corrector`
   (`config.qa.enabled=true` + критерии за финално качество). Верификаторът се
   **синтезира** по време на run (`NodeExecutorService::syntheticVerifier`) от
   критериите — без отделен возел в графа. Така QA-тригерираната адаптивна
   ревизия (раздел 8) най-сетне се задейства реално. В builder-а всеки не-verifier
   възел има секция „QA проверка" (вкл/изкл, праг, критерии) за ръчни gate-ове.

3. **Доставка на резултата (9а#2).** Панел „Доставка на резултата" на
   `/flows/{id}` → канал email / Slack / webhook / файл (`flows.settings.delivery`).
   След успешен run `GraphFlowExecutor::finalize` извиква `DeliveryService`
   (best-effort, SSRF guard за webhooks); резултатът се вижда в run viewer-а
   (`context['delivery']`).

4. **Условно рутиране (9а#5).** `decision` възелът има именувани изходни портове
   (клонове) с етикет + „кога". `DecisionAgent` избира ЕДИН клон; executor-ът
   (`GraphFlowExecutor::resolveActiveNodes`) прескача невзетите клонове
   (`node_runs.status='skipped'`, сиви в графа). Прекъсването се разпространява;
   при липса на решение/клонове — всички клонове се изпълняват (безопасен
   fallback). Активира се само ако графът има `decision` возел.

## 10. Конфигурация

```env
# Кой LLM проектира агентите
GENERATOR_PROVIDER=openai            # openai | anthropic (cloud) | ollama (безплатно, локално)
OLLAMA_PLANNER_MODEL=qwen2.5:14b     # локален planner модел при ollama
OPENAI_API_KEY=sk-proj-…             # НЕ комитвай! Виж раздел 11
OPENAI_GENERATOR_MODEL=gpt-4o        # planner модел (смени при нужда)
OPENAI_RUNTIME_MODEL=gpt-4o-mini     # модел за агенти с provider=openai
OPENAI_FLAGSHIP_MODEL=gpt-4o         # най-скъпият модел — ползва се само на ниво god
ANTHROPIC_API_KEY=sk-ant-…           # planner / A-B / runtime с Claude
ANTHROPIC_GENERATOR_MODEL=claude-sonnet-4-6   # planner модел за Claude
ANTHROPIC_RUNTIME_MODEL=claude-haiku-4-5      # модел за агенти с provider=anthropic
ANTHROPIC_FLAGSHIP_MODEL=claude-sonnet-4-6    # най-скъпият модел — само на ниво god
PLANNER_CRITIQUE=true                # Фаза В вкл/изкл
# Квотите на provider-ите per план се определят от нивото на моделите
# (low|medium|high|ultra|god, избира се в UI при генериране; default medium)
PLANNER_FEW_SHOTS=2                  # Фаза 2: брой примери от plan library
PLANNER_ADAPTIVE=true                # Фаза 3: ревизия при QA fail/watchdog
PLANNER_ESCALATION_PROVIDER=openai   # Фаза 3: накъде ескалира провалена стъпка (openai|anthropic)
PLANNER_VECTOR_THRESHOLD=100         # праг за vector retrieval в plan library
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
```

Ценоразписът за cost tracking е в `config/services.php` →
`services.openai.pricing` / `services.anthropic.pricing` (USD за 1M токена).

## 11. Сигурност — ВАЖНО

OpenAI ключът беше поставен в чат съобщение → третирай го като
**компрометиран**. Препоръка: завърти го от
https://platform.openai.com/api-keys и сложи новия само в `.env`
(който е в `.gitignore`). Никога в код, документи или чатове.

## 12. Източници (проучване)

- [Anthropic / LangChain — Plan-and-Execute agents](https://blog.langchain.com/planning-agents/)
- [Planner–Executor agentic framework (обзор)](https://www.emergentmind.com/topics/planner-executor-agentic-framework)
- [Survey: динамични workflow графи за LLM агенти](https://arxiv.org/html/2603.22386v1) — вкл. Aime (dynamic planner + actor factory — точно нашият случай)
- [OpenAI Structured Outputs (официална документация)](https://developers.openai.com/api/docs/guides/structured-outputs) — `response_format: json_schema, strict: true`
- [Structured Outputs vs JSON mode (2026)](https://www.respan.ai/articles/openai-structured-outputs-vs-json-mode)
- [Meta-prompting: LLM пише промптите на под-агенти](https://www.comet.com/site/blog/meta-prompting/)
- [Температури per задача (research)](https://arxiv.org/pdf/2410.09854) — различните подзадачи искат различна температура; planner-ът задава 0.1–0.3 за анализ/QA, 0.7–0.8 за креативно съдържание
