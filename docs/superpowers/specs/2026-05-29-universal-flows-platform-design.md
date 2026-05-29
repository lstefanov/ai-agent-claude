# FlowAI → Универсална агентна автоматизационна платформа

**Дата:** 2026-05-29
**Статус:** Draft за преглед
**Автор:** Brainstorming сесия (Claude)
**Език на изпълнение:** реални интеграции (OAuth + API), LLM авто-детекция на услуги
**Обхват на тази сесия:** само планът (без код)

> Цел: да превърнем текущия "генератор на FB постове" в **универсален двигател за ежедневни автоматизации** — flow, който може да чете/пише в Google Drive, да следи YouTube и да прави репорт, да чете пощата и да прави summary, да публикува в социални мрежи и т.н. — като автоматично разпознава кои услуги ще се ползват, кара те да се свържеш с тях преди старт, и показва **динамичен резултат** според вида flow (а не хардкоднат FB пост).

---

## 0. TL;DR — какво променяме

| Днес | Утре |
|------|------|
| Всеки агент = LLM трансформация текст→текст | Агентът може да **мисли** (LLM), да **действа** (вика реален Tool) или да **рендира** (типизиран артефакт) |
| Единственото реално действие е `ImagePromptAgent → ComfyUI` | Слой от **Connectors → Tools** (Drive, Gmail, YouTube, FB, X, TikTok, Slack, Sheets…) с реален OAuth |
| `AgentGeneratorService` е хардкоднат за маркетинг/social | Домейн-агностичен архитект, който получава каталог от конектори и **разпознава нужните услуги** |
| Няма OAuth, няма съхранение на достъп | `Connection` модел с **криптирани токени**, refresh, per-company изолация |
| Не може да се пусне flow без агенти | Не може да се **активира/пусне** flow, докато всички нужни конектори не са свързани (gate) |
| Резултатът се рендира чрез string-match за `facebook` в [runs/show.blade.php](resources/views/runs/show.blade.php) | **Регистър от render-kinds** — типизиран изход избира правилния partial (social_post / document / digest / report / media / table) |

---

## 1. Откъде тръгваме (одит на текущия код)

Прегледах целия pipeline. Текущата архитектура:

- **Стек:** Laravel 12 + MySQL (`flowai`), локални LLM-и през Ollama (`OLLAMA_URL`), изображения през ComfyUI (`COMFYUI_URL`). Queue = `sync` в dev.
- **Домейн:** `Company` → `Flow` → много подредени `Agent` → `FlowRun` → `AgentRun`.
- **Генериране на агенти:** [`AgentGeneratorService`](app/Services/AgentGeneratorService.php) праща описанието на flow-а + каталог от Ollama модели към LLM и получава JSON масив от агенти. **Системният промпт е изцяло маркетингов** ("Ти си AI архитект на маркетингови и бизнес автоматизации"), а изброените типове агенти са social-media центрирани (`researcher`, `content_bg`, `hashtag`, `image_prompt`, `caption_writer`, `qa_verifier`).
- **Изпълнение:** [`FlowExecutorService`](app/Services/FlowExecutorService.php) пуска агентите последователно, като подава изхода на всеки като `{{input}}`/`{{topic}}` в следващия + блок "Context from previous agents". Накрая QA gate.
- **Агенти:** [`AgentFactory`](app/Agents/AgentFactory.php) мапва `type` → PHP клас. **Всички класове освен `ImagePromptAgent` просто викат `OllamaService::chat()`.** Дори [`PublisherAgent`](app/Agents/PublisherAgent.php) **не публикува** — само форматира текст с LLM.
- **Единственото реално външно действие:** [`ImagePromptAgent`](app/Agents/ImagePromptAgent.php) → [`ComfyUIService`](app/Services/ComfyUIService.php) (реална генерация на изображение). Това всъщност е **прототипът на "Tool"**, който трябва да генерализираме.
- **Рендиране на резултата:** [runs/show.blade.php:38](resources/views/runs/show.blade.php:38) разпознава платформата чрез **string-match** на името/описанието на flow-а (`facebook`/`instagram`/`twitter`/`linkedin`) и рендира хардкодната картичка. Снимката, която прати, е точно `facebook` клонът. "Финалният изход" е просто текстът на последния не-QA агент.

### Трите дупки, които трябва да запълним

1. **Няма "действие".** Системата може само да съчинява текст. Не може да прочете файл, да изпрати мейл, да публикува пост.
2. **Няма връзка с услуги.** Няма OAuth, няма съхранени достъпи, няма разпознаване кои услуги трябват.
3. **Изходът е хардкоднат.** Резултатът се познава по име на flow и се рендира като FB картичка.

Целият този план е за запълването на тези три дупки **по универсален начин**.

---

## 2. Голямата идея — три вида агенти

Ключовата концептуална промяна: агентът вече не е само "LLM трансформация". Въвеждаме поле `kind`:

```
kind = think | act | render
```

- **think** — мисли/съчинява чрез LLM. Това е цялото текущо поведение (researcher, analyzer, content, summarizer, qa_verifier…). Нищо не се чупи.
- **act** — изпълнява реален **Tool** срещу външна услуга през **Connector**. Връща **типизиран структуриран резултат**, не просто markdown. (напр. `gmail.list_unread`, `gdrive.write_file`, `facebook.create_post`, `youtube.list_channel_videos`).
- **render** — взима данните от предишните стъпки и произвежда **типизиран артефакт за показване/изход** (social_post, document, digest, report…). Често последната стъпка.

Така един flow е просто **последователност от think/act/render стъпки**. Примери:

```
Gmail дайджест:   gmail.list_unread (act) → summarizer (think) → render:digest
YouTube репорт:   youtube.list_channel_videos (act) → youtube.get_video_stats (act)
                  → analyzer (think) → report_builder (think)
                  → gdrive.write_file (act) → render:report
Drive резюме:     gdrive.list_files (act) → gdrive.read_file (act)
                  → summarizer (think) → gdrive.write_file (act) → render:document
FB пост (реален): researcher (think) → content (think) → comfyui.generate_image (act)
                  → caption (think) → qa_verifier (think) → facebook.create_post (act)
                  → render:social_post
```

> Забележи: старият FB flow остава валиден — просто `PublisherAgent` се заменя с реален `act` агент `facebook.create_post`, а ComfyUI генерацията става нормален Tool.

---

## 3. Нови домейн концепции

### 3.1 Connector (дефиниция на интеграция)
Статична дефиниция на услуга. Живее в код (клас) + ред в таблица за метаданни/UI.

| Поле | Описание |
|------|----------|
| `key` | напр. `google_drive`, `gmail`, `youtube`, `facebook`, `twitter`, `tiktok`, `slack`, `google_sheets`, `http` |
| `name`, `icon`, `category` | за UI каталога |
| `auth_type` | `oauth2` \| `api_key` \| `none` |
| `oauth` | client_id/secret (от config/env), authorize_url, token_url, **scopes** |
| `tools[]` | списък от Tool-ове, които конекторът предоставя |

### 3.2 Tool (едно конкретно действие)
Една извикваема операция с **JSON schema за вход** и **типизиран изход**. Примери:

| Tool key | Вход | Изход (kind) |
|----------|------|--------------|
| `gdrive.list_files` | folder_id, query | `dataset` (списък файлове) |
| `gdrive.read_file` | file_id | `document` (текст/съдържание) |
| `gdrive.write_file` | name, content, folder_id | `document` (+ link) |
| `gmail.list_unread` | label, max | `dataset` (мейли) |
| `gmail.get_message` | message_id | `document` |
| `gmail.send` | to, subject, body | `status` |
| `youtube.list_channel_videos` | channel_id, since | `dataset` |
| `youtube.get_video_stats` | video_id | `table` |
| `youtube.get_comments` | video_id | `dataset` |
| `facebook.create_post` | page_id, message, image | `social_post` |
| `twitter.post_tweet` | text, media | `social_post` |
| `tiktok.upload_video` | video, caption | `social_post` |
| `slack.post_message` | channel, text | `status` |
| `sheets.read_range` / `sheets.append_row` | … | `table` / `status` |
| `comfyui.generate_image` | prompt | `media` (съществуващото, обвито като Tool) |
| `web.search` | query, max, lang | `dataset` (резултати от търсене) |
| `web.fetch` | url | `document` (изчистен текст на страница/статия) |
| `http.request` | method, url, headers, body | `raw`/`dataset` (generic за дългата опашка) |

Tool интерфейс (скица):

```php
interface Tool
{
    public function key(): string;                 // "gmail.list_unread"
    public function connector(): string;           // "gmail"
    public function inputSchema(): array;          // JSON schema за args
    public function requiredScopes(): array;       // ["gmail.readonly"]
    public function outputKind(): string;          // "dataset"
    public function run(array $args, Connection $conn): ToolResult;
}

final class ToolResult
{
    public string $kind;        // dataset | document | social_post | media | status | table | raw
    public array  $data;        // структурираните данни
    public array  $links = [];  // напр. линк към публикувания пост / Drive файл
    public string $summaryText; // кратко текстово резюме за подаване към следващ think агент
}
```

### 3.3 Connection (свързан акаунт / credential)
Авторизираният инстанс на конектор за дадена компания. **Това е, което "Свържи акаунт" създава.**

| Поле | Описание |
|------|----------|
| `company_id` | per-company изолация |
| `connector_key` | `google_drive` |
| `account_label` | "igroup7@gmail.com" |
| `access_token` / `refresh_token` | **криптирани** (`encrypted` cast / `Crypt`) |
| `scopes` | реално дадените scopes |
| `expires_at` | за refresh |
| `status` | `active` \| `expired` \| `revoked` |

### 3.4 Capability requirement (разпознатата нужда)
Когато flow се генерира/редактира, LLM + детерминистичен post-pass извеждат **списък от нужни конектори със scopes**. Flow-ът получава **readiness gate**: не може да се активира/пусне, докато всеки нужен конектор няма валиден `Connection`.

---

## 4. Промени по модела `Agent`

Добавяме (миграция):

```php
$table->string('kind')->default('think');     // think | act | render
$table->string('tool_key')->nullable();        // за act: "gmail.list_unread"
$table->json('input_mapping')->nullable();      // как се градят args от контекста
$table->string('output_kind')->nullable();      // подсказка за рендиране
$table->string('connector_key')->nullable();    // за бърз gate/изчисления
```

Нов **`ToolAgent`** в `AgentFactory` (за `kind=act`):

1. Резолва `Connection` за `tool->connector()` и `flow->company`.
2. Гради args от контекста чрез `input_mapping` (template) **или** чрез LLM "function-calling" стъпка (LLM попълва args по `inputSchema`).
3. Вика `tool->run($args, $connection)`.
4. Връща `ToolResult` → сериализира се в `AgentRun.output` като типизиран envelope `{ kind, data, links, summaryText }`.
5. `FlowExecutorService` подава `summaryText` нататък (за think агенти), а пълните `data`/`links` се пазят за рендиране.

> `BaseAgent`/думащите агенти остават както са. Само добавяме нов клон в фабриката + envelope формат на изхода.

### 4.1 Параметри на flow-а (потребителски вход)
Някои flows имат стойности, които потребителят задава при създаване — напр. **email получател**, channel в Slack, Drive папка, YouTube канал. Добавяме `Flow.params` (JSON) + UI форма при създаване:

```jsonc
// Flow.params (дефиниция) — генерира се от описанието / задава се ръчно
[
  { "key": "recipient_email", "label": "Email за получаване", "type": "email", "required": true }
]
// Flow.param_values (стойности) — попълнени от потребителя
{ "recipient_email": "igroup7@gmail.com" }
```

Достъпни са в `input_mapping` като `{{params.recipient_email}}` — точно както `{{company_description}}` днес. Така `gmail.send` знае на кого да прати, без да е хардкоднато.

---

## 5. Авто-детекция на услуги + gate за свързване

### 5.1 Генерализиране на `AgentGeneratorService`
- Замяна на маркетинговия системен промпт с **домейн-агностичен "AI архитект на автоматизации"**.
- Подаваме му **каталог от конектори + tools** (точно както сега подаваме каталога с модели в `buildModelsContext()`).
- Изходът вече включва **две неща**:
  1. pipeline-а от агенти (микс think/act/render),
  2. масив `required_connectors` със `scopes` + човешка причина ("този flow чете пощата → нужен е Gmail с gmail.readonly").

```jsonc
{
  "agents": [ /* както сега, + kind, tool_key, input_mapping, output_kind */ ],
  "required_connectors": [
    { "connector": "gmail",  "scopes": ["gmail.readonly"], "reason": "Чете непрочетени мейли" },
    { "connector": "slack",  "scopes": ["chat:write"],     "reason": "Праща дайджеста в канал" }
  ]
}
```

- **Детерминистичен post-pass:** не вярваме сляпо на LLM. За всеки агент с `tool_key` мапваме `tool → connector → requiredScopes()` и обединяваме с `required_connectors`. Така истинският списък нужни конектори е изчислен от реалните tools, не само от LLM преценка. (Същата защитна философия като текущия `recoverTruncatedJson`/`normalizeAgent`.)

### 5.2 Connection gate (UX)
На `flows/show` и `flows/edit` — панел **"Интеграции"**:

```
🔌 Интеграции на този flow
  ✅ Google Drive        igroup7@gmail.com           [Преконфигурирай]
  ⚠️ Gmail               Не е свързан                [ Свържи ]
  🔄 Facebook Page       Токенът е изтекъл           [ Поднови ]
```

- **"Свържи"** → OAuth redirect (Laravel Socialite за Google/Facebook/Twitter; custom OAuth драйвер за TikTok/останалите) → callback → криптиран `Connection`.
- Бутонът **"Стартирай"** е disabled (с tooltip "Свържи Gmail, за да продължиш"), докато не са свързани всички нужни конектори. Същият gate важи и преди **планиране** (cron).
- При генериране на flow завършваме с екран **"Тези услуги ще се ползват — свържи ги, за да активираш flow-а"**.

---

## 6. Динамичен резултат (край на хардкоднатата FB картичка)

### 6.1 Типизиран изход вместо string-match
Махаме платформа-детекцията по име от [runs/show.blade.php:34-46](resources/views/runs/show.blade.php:34). Вместо това **изходният артефакт носи своя `kind`**:

```jsonc
{ "kind": "social_post", "data": { "platform": "facebook", "text": "...", "image": "...", "hashtags": [...] }, "links": ["https://facebook.com/.../posts/123"] }
```

`kind` се определя по приоритет от:
1. изричния `output_kind` на render/act агента;
2. иначе — от Tool-а, който е произвел изхода (`facebook.create_post → social_post:facebook`, `gdrive.write_file → document`);
3. иначе — `raw` (markdown fallback, текущото поведение).

### 6.2 Регистър от render partials
Един Blade partial на kind, избиран динамично:

```
resources/views/partials/result/
  social_post.blade.php   (FB / IG / X / LinkedIn / TikTok варианти — генерализира текущата картичка)
  document.blade.php       (заглавие + preview + download/Drive линк)
  digest.blade.php         (списък: подател / тема / резюме — за Gmail дайджест)
  report.blade.php         (секции + метрики + таблици/графики)
  media.blade.php          (изображение/видео preview)
  table.blade.php          (data таблица / dataset)
  status.blade.php         ("✅ Изпратено до 3 получателя", линкове)
  raw.blade.php            (markdown fallback — днешното поведение)
```

`runs/show` зарежда: `@include("partials.result.$kind", ['result' => $finalResult])`. Така добавяне на нов тип резултат = нов partial, без да пипаме логиката за изпълнение. **Текущата FB картичка просто става `social_post.blade.php` с `platform=facebook`.**

---

## 7. Стратегия за конекторите — нативно vs MCP (важно решение)

Три подхода, с trade-offs:

### Подход A — Нативно (Laravel Socialite + тънки Tool класове) — *за ядрото*
- ✅ Пълен контрол, прозрачност, лесен дебъг, най-добра сигурност.
- ✅ Socialite вече има драйвери за Google/Facebook/Twitter (+ community драйвери за останалите).
- ❌ Всеки нов конектор е ръчна работа.
- **Подходящ за:** Google (Drive/Gmail/Sheets/Calendar/YouTube — всичко през един Google OAuth), Facebook/IG, Twitter/X. Това покрива 90% от твоите примери.

### Подход B — MCP / Composio мост — *за дългата опашка*
- ✅ Десетки интеграции наведнъж през един "bridge" Tool.
- ✅ MCP е стандартът, който този проект (и Claude) вече ползва.
- ❌ Външна зависимост, по-малко контрол над auth UX, потенциални разходи (Composio).
- **Подходящ за:** нишови услуги (Notion, Linear, ClickUp, Asana…), където не искаш ръчен драйвер.

### Подход C — Само generic `http.request` Tool
- ✅ Нула зависимости, върши работа за прости REST API-та с API key.
- ❌ Целият auth/pagination товар пада върху промпта/потребителя.
- **Подходящ за:** webhook-и, прости REST endpoint-и, RSS.

### Специален случай — Web Search конектор (за "преглеждане на интернет")
Flows, които **четат интернет пространството** (новини, статии, блогове), се нуждаят от истинско търсене/сваляне, защото `researcher` агентът сам по себе си е LLM и би халюцинирал актуални данни. Това е отделен конектор с **auth = API key (не OAuth)** → лесен за добавяне:

- `web.search` — заявка към search provider (**Brave Search API**, SerpAPI или Google Custom Search) → списък резултати (заглавие, URL, snippet) като `dataset`.
- `web.fetch` — сваля URL и извлича чист четим текст (Readability-style) като `document`.
- **Без търсачка / нула зависимости:** `http.request` срещу конкретни **RSS** емисии, които потребителят посочи (примерно избрани спортни сайтове). По-ограничено, но безплатно и предвидимо.

> Препоръка: добави **Brave Search API** (евтин, прост key) за `web.search` + `web.fetch`. Това отключва цял клас "research → summary → deliver" flows.

### Препоръка: **Хибрид** — A за ядрото, C веднага като escape hatch, B по-късно за широчина.
Започни с **един Google OAuth** (покрива Drive + Gmail + Sheets + YouTube + Calendar само с различни scopes!) + Facebook. Това отключва огромна част от ежедневните flows с минимум работа.

---

## 8. Сигурност

- **Криптиране на токени at rest** — `encrypted` cast / `Crypt::encryptString`. Никога plaintext в БД/логове.
- **Token refresh** — cron job, който подновява изтичащи OAuth токени; `Connection.status = expired` при провал → gate-ът ги хваща.
- **Least-privilege scopes** — всеки Tool декларира минималните нужни scopes; искаме само обединението за реално ползваните tools.
- **Per-company изолация** — `Connection` е винаги вързан за `company_id`; ToolAgent никога не ползва чужд `Connection`.
- **Redaction в логовете** — текущият детайлен лог в `FlowExecutorService` не трябва да печата токени/headers; добавяме маскиране за `act` стъпки.
- **Потвърждение преди публикуване** — за `act` tools с външен ефект (публикуване, изпращане) — опционален "dry-run / искай потвърждение" флаг на агента, особено при ръчно тестване.

---

## 9. Креативни идеи за ежедневни flows (recipes)

Готови шаблони ("рецепти"), които потребителят пуска с един клик и само свързва акаунтите:

**Комуникация / поща**
1. **Inbox triage & дневен дайджест** — Gmail/Outlook непрочетени → категоризиране → резюме → чернови на отговори.
2. **Извличане на фактури/разписки** — мейл прикачени файлове → extract → ред в Google Sheet.
3. **Customer support FAQ** — тикети → клъстериране → чернови на KB статии.

**Документи / данни**
4. **Drive folder summarizer** — следи папка → резюмира нови документи → записва резюме обратно в Drive.
5. **Sheets KPI репорт** — чете Google Sheet → анализ → графика → праща в Slack.
6. **Седмичен analytics roll-up** — GA / Search Console → репорт в Drive.

**Видео / съдържание**
7. **YouTube channel monitor** — нови видеа на конкуренти → статистики + sentiment на коментари → репорт в Drive/Slack.
8. **Podcast/transcript → блог** — транскрипт → статия → публикуване (WordPress/Drive).
9. **SEO content brief** — keyword research → outline → чернова.

**Социални мрежи (реално публикуване)**
10. **Daily cross-poster** — една идея → адаптирани варианти за FB / IG / X / LinkedIn / TikTok → публикува във всяка.
11. **Review responder** — Google Business / app store ревюта → чернови на отговори.
12. **Competitor price/news watch** — `http.request` scrape → diff → alert в Slack/мейл.

**Research → summary → deliver** (изисква Web Search конектора)
13. **Спортни новини дайджест (примерът на Спортния център)** — всяка сутрин в 10:00 `web.search` за спортни мероприятия/новини/статии/блогове → `web.fetch` на топ резултатите → `analyzer` (филтрира релевантното) → `summarizer` → `render:document` → `gmail.send` до посочен от потребителя email (`{{params.recipient_email}}`). Cron: `0 10 * * *`.
14. **Тематичен news brief (всяка ниша)** — същият шаблон, параметризиран по ключови думи и получател; работи за всякакъв бизнес/тема.

**Организация**
15. **Calendar prep** — днешен Google Calendar → research на участниците/фирмите → брифинг.
16. **Slack standup bot** — събира съобщения → резюме → публикува.
17. **News/RSS бриф (BG)** — feeds → резюме на български → дневен дайджест по мейл/Slack.

> Всяка рецепта = предефиниран pipeline + списък нужни конектори. Това е и страхотен onboarding: "Избери рецепта → свържи 1-2 акаунта → готово."

---

## 10. Фазиран roadmap

| Фаза | Цел | Резултат |
|------|-----|----------|
| **0. Фундамент** | Connector/Tool/Connection абстракция, миграции (Agent.kind/tool_key/…, Flow.params), криптирани токени, generic `ToolAgent`, типизиран изход envelope | Архитектурата стои, нищо старо не се чупи |
| **1. Първи реални конектори** | Един Google OAuth (Drive read/write + Gmail read/send) + Facebook publish + **Web Search (`web.search`/`web.fetch`, API key)** + **flow параметри (recipient email)**. Socialite. Connection gate UX. | "Спортни новини → summary → email в 10:00" и "прочети Drive → резюме → запиши" работят end-to-end |
| **2. Универсален генератор** | Домейн-агностичен `AgentGeneratorService` + каталог от конектори + детекция на `required_connectors` + post-pass | Описваш произволен flow → системата сама избира tools и иска връзки |
| **3. Динамичен резултат** | Render-kind регистър + partials; миграция на FB картичката към `social_post` | Резултатът се рендира според вида flow, не по име |
| **4. Широчина + надеждност** | YouTube, Twitter/X, TikTok, Slack, Sheets; token refresh cron; per-tool QA; redaction в логове | Покрива всички примери от заданието |
| **5. Полиране** | Каталог/marketplace на конектори, рецепти (раздел 9), dry-run за публикуване | Onboarding с един клик, продуктов вид |

---

## 11. Рискове и съвети

- **Google verification** — чувствителни scopes (Gmail, Drive full) изискват Google OAuth verification за продукция. За прототип/тест: остани в "Testing" режим с test users (до 100) — без verification.
- **Facebook/TikTok app review** — публикуването изисква одобрено приложение и Page/Business акаунти. За демо: ползвай test app + собствена страница.
- **Дължина на LLM изхода** — генераторът вече има `recoverTruncatedJson`; при добавяне на `required_connectors` дръж изхода компактен (раздели pipeline и connectors в два промпта, ако трябва).
- **`act` стъпки и retry** — текущият 3x retry в `FlowExecutorService` е писан за LLM. За реални API действия (особено публикуване/изпращане) retry трябва да е **идемпотентен** или изключен — иначе риск от дублирани постове/мейли.
- **Локални модели и tool-use** — Ollama моделите варират в "function calling". За попълване на tool args дръж резервен вариант с детерминистичен `input_mapping` template, не разчитай само на LLM.
- **YAGNI** — не строй всичките 15 рецепти наведнъж. Фаза 1 с Google + FB доказва цялата архитектура; останалото е повторение на шаблона.

---

## 12. Следваща стъпка

Това е дизайн документът (план). Когато си готов да преминем към реализация:
- Започваме с **Фаза 0 + 1** (фундамент + Google/Facebook конектори) като отделен implementation план.
- Препоръчвам да валидираме архитектурата с **един** реален flow ("Drive → резюме → запиши обратно") преди да мащабираме.

> Прегледай документа и ми кажи какво да коригирам/добавя, преди да напиша детайлния implementation план.
