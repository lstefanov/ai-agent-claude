# MCP Конектори — Ръководство за ползване

> Как да свържеш системите, как да ги ползваш в Flows и какво може да се автоматизира.

---

## Какво са MCP Конекторите?

До сега агентите само **генерираха текст** — пишеха имейли, доклади, постове. MCP конекторите правят агентите **оперативни** — изпращат имейли, пишат в Google Sheets, публикуват в Slack, четат CRM-а.

Разликата: от „Напиши имейл до клиента" → „Напиши И изпрати имейл до клиента."

---

## Стъпка 1: Свържи системата на ниво Фирма

**Company Settings → „Свързани системи"**

Тук свързваш веднъж за цялата фирма. Всички Flows после ползват тази връзка.

### Как да свържеш Gmail

1. Company Settings → Свързани системи → **„+ Свържи нова"**
2. Избираш **Gmail**
3. Системата те пренасочва към Google → логваш се → даваш разрешения
4. Виждаш: `📧 Gmail | igroup7@gmail.com | 🟢 Активен`

> **Важно:** Давай само нужните permissions. За да четеш поща: `gmail.readonly`. За да изпращаш: `gmail.send`. Не давай пълен достъп, ако не е нужен.

### Как да свържеш Slack

1. Company Settings → **„+ Свържи нова"** → **Slack**
2. Slack OAuth → избираш workspace → избираш в кои канали може да пише
3. Записваш

### Как да свържеш Google Sheets / Drive

Работи като Gmail — Google OAuth flow. При вход дай access само до нужните spreadsheets или папки.

### Как да свържеш Airtable / Notion

Те ползват **API Key** вместо OAuth:
1. Отвори Airtable → Account → API → копирай Personal Access Token
2. Company Settings → **Airtable** → поставяш токена
3. **Тествай** с бутона „Провери" — проверява дали токена е валиден

### Статуси на конекторите

- 🟢 **Активен** — работи нормално
- 🟡 **Изтекъл** — OAuth token-ът е стар → бутон „Обнови" → Google/Slack ще поиска потвърждение
- 🔴 **Грешка** — нещо не е наред → „Провери" показва подробности

---

## Стъпка 2: Flow с MCP действие

### 2.1 Генерирай Flow с MCP

Когато описваш Flow-а, планерът автоматично добавя MCP nodes ако е подходящо.

**Пример — описание, което ражда MCP nodes:**

```
Прочети последните 5 клиентски запитвания от Gmail, 
напиши персонализирани отговори за всяко и 
запази резултатите в Google Sheets колона "Отговори".
```

Планерът ще генерира:
```
[Прочети Gmail] → [Анализатор на запитвания] → [Писач на отговори] 
                                                        ↓
                                             [Потвърждение] → [Запиши в Sheets]
```

Ключово: планерът автоматично вмъква **„Потвърждение"** node преди write операции.

### 2.2 Добави MCP node ръчно в Builder-а

В Drawflow builder → вдясно → „MCP Действие" → плъзгаш в графа.

Кликаш върху node-а → конфигурационен панел:

```
Конектор:  [Фирмен Gmail ▼]
Действие:  [gmail.send_email ▼]

Параметри:
  До:      {{flow.input.client_email}}
  Тема:    {{agent.subject_writer.output}}
  Текст:   {{agent.email_writer.output}}

☑ Изисква потвърждение преди изпращане
```

### 2.3 Variable синтаксис

В параметрите на MCP node-овете ползваш:

| Синтаксис | Значение | Пример |
|-----------|----------|--------|
| `{{flow.input.X}}` | Вход на Flow-а | `{{flow.input.recipient}}` |
| `{{agent.NODE_KEY.output}}` | Изход на предишен агент | `{{agent.email_writer.output}}` |
| `{{connector.setting.X}}` | Default настройка на конектора | `{{connector.setting.default_folder}}` |
| `{{flow.setting.X}}` | Настройка специфична за Flow-а | `{{flow.setting.target_sheet}}` |
| `{{date:FORMAT}}` | Текуща дата | `{{date:Y-m-d}}` |

---

## Стъпка 3: Human Approval при write действия

Всяко **изходящо действие** (изпращане, публикуване, записване) изисква потвърждение.
Системата го налага автоматично — не можеш да го заобиколиш при email/Slack.

### Как изглежда approval в рамките на Run

Flow-ът се спира → в страницата на Run виждаш:

```
⏸ Run е на пауза — очаква потвърждение

Агентът е готов да:
  Изпрати имейл до: klient@example.com
  Тема: Оферта за летни курсове
  Текст: [превю на 3 реда...]

  [👁 Виж пълния текст]  [✅ Одобри]  [❌ Отхвърли]
```

При **Одобри** → Flow-ът продължава и изпраща.
При **Отхвърли** → Node-ът се маркира като skip, останалите агенти продължават.

### Кои действия изискват approval (задължително)

- `gmail.send_email` — изпраща имейл
- `gmail.reply` — отговаря на нишка
- `slack.post_message` — публикува в Slack
- `sheets.append_row`, `sheets.update_range` — пише в Sheets
- `drive.upload_file`, `drive.create_doc` — качва в Drive
- `notion.create_page`, `notion.update_page` — пише в Notion
- `airtable.create_record`, `airtable.update_record` — пише в Airtable

### Кои действия НЕ изискват approval (read-only)

- `gmail.list_emails`, `gmail.get_email`
- `sheets.read_range`, `sheets.get_values`
- `drive.list_files`, `drive.get_file_content`
- `notion.query_database`, `notion.get_page_content`
- `airtable.list_records`, `airtable.find_records`
- `slack.list_messages`, `slack.list_channels`

---

## Топ 11 Tools — Какво правят

| Tool | Описание | Кога да ползваш |
|------|----------|-----------------|
| `gmail.list_emails` | Чете последни N имейли (с филтри) | Класификация на входяща поща |
| `gmail.send_email` | Изпраща имейл | Автоматизирани отговори, оферти |
| `gmail.create_draft` | Създава чернова | Когато искаш ти да финализираш |
| `sheets.read_range` | Чете данни от Sheets | Входни данни за обработка |
| `sheets.append_row` | Добавя ред в Sheets | Запазване на резултати |
| `slack.post_message` | Публикува в канал | Известия, доклади за екипа |
| `drive.list_files` | Списък с файлове в папка | Намиране на последния доклад |
| `drive.get_file_content` | Чете съдържание на файл | Анализ на документи |
| `notion.query_database` | Query на Notion database | Четене на задачи/проекти |
| `notion.create_page` | Нова страница в Notion | Автоматизирано документиране |
| `http_api.post` | POST към произволен endpoint | Интеграция с всяка система |

---

## 10+ Примерни Flow Descriptions с MCP

### 1. Автоматизиран отговор на клиентски запитвания

```
Прочети последните 10 непрочетени имейла от Gmail с тема "Запитване".
За всеки имейл: анализирай запитването, намери релевантна информация от 
базата ни на знания, напиши персонализиран отговор на официален български.
Запиши всеки отговор в Google Sheets (колони: От, Тема, Отговор, Дата).
За имейли с висок приоритет — изпрати отговора директно.
За останалите — запази като чернова за преглед.
```
*MCP nodes: gmail.list_emails → sheets.append_row → gmail.create_draft / gmail.send_email*

---

### 2. Седмичен маркетинг доклад

```
Всяка петък в 17:00: вземи данните за продажби от Google Sheets (лист "Продажби 2026"),
изчисли топ 5 продукта за седмицата, напиши executive summary от 200 думи,
добави графика с тренда. Публикувай в Slack канал #management.
Запази доклада като нов Google Doc в папка "Доклади/Седмични/".
```
*MCP nodes: sheets.read_range → drive.create_doc → slack.post_message*

---

### 3. Обработка на нови leads от CRM

```
Когато се задейства webhook от HubSpot за нов contact:
Вземи пълните данни за контакта.
Провери в базата ни на знания дали имаме оферта за неговия сектор.
Напиши персонализиран welcome имейл с конкретни предложения.
Добави бележка в HubSpot контакта с резюме на изпратеното.
Увведоми отговорния продажбар в Slack.
```
*MCP nodes: hubspot read → gmail.send_email → hubspot.create_note → slack.post_message*

---

### 4. Конкурентен анализ → Notion

```
Всеки понеделник: направи дълбоко уеб проучване на топ 5 конкуренти
(провери сайтовете им, Google новини, LinkedIn). Напиши структуриран доклад
с промени: нови продукти, ценови промени, маркетинг активности.
Запази в Notion базата ни "Конкуренти" — нова страница за текущата седмица.
Изпрати summary в Slack #strategy.
```
*MCP nodes: notion.create_page → slack.post_message*

---

### 5. Автоматизирано следене на Gmail и тикет в Airtable

```
Всеки час: провери Gmail за нови имейли с тема "СПЕШНО" или "URGENT".
За всеки такъв имейл: извлечи ключовата информация (контакт, проблем, дедлайн),
създай тикет в Airtable таблица "Support Tickets" с полета:
Приоритет=HIGH, Статус=New, Описание, Контакт.
Изпрати потвърдителен имейл до подателя.
Уведоми #support в Slack.
```
*MCP nodes: gmail.list_emails → airtable.create_record → gmail.send_email → slack.post_message*

---

### 6. Генерирай и изпрати оферта

```
При вход: [Клиент], [Услуга], [Бюджет], [Дедлайн].
Намери всички релевантни услуги от базата ни на знания с цени.
Напиши персонализирана оферта в официален стил, съобразена с бюджета.
Провери Google Sheets "Изпратени оферти" — дали сме контактували с клиента.
Запиши офертата в Drive папка "Оферти/" като PDF-ready документ.
Изпрати офертата на [Клиент имейл] с CC до [Мениджър].
Добави ред в "Изпратени оферти" Sheet с датата и статус "Изпратена".
```
*MCP nodes: sheets.read_range → drive.create_doc → gmail.send_email → sheets.append_row*

---

### 7. Публикуване в социалните мрежи (с approval)

```
При вход: [Тема на поста], [Тон], [Целева аудитория].
Напиши 3 варианта на Facebook пост — официален, неформален, промоционален.
Провери нашите brand guidelines от базата на знания.
Прегледай последните 5 публикации от Drive папка "Публикувани постове/" за стил.
Избери най-добрия вариант и го представи за одобрение.
При одобрение: запази финалния текст в Drive и публикувай в Slack #social-media
  като предстояща публикация за финален преглед от екипа.
```
*MCP nodes: drive.list_files → drive.get_file_content → slack.post_message*

---

### 8. Анализ на клиентски feedback → Sheets dashboard

```
Вземи всички имейли от последния месец с тема "Feedback" или "Отзив".
Анализирай всеки: извлечи тема, настроение (позитивно/неутрално/негативно),
конкретни споменати проблеми или похвали, препоръки.
Структурирай резултатите и ги запиши в Google Sheets "Feedback Dashboard":
  - Лист "Raw": по един ред за всеки имейл
  - Лист "Summary": агрегирани метрики (% позитивни, топ 5 проблема)
Изпрати summary доклад до #management в Slack.
```
*MCP nodes: gmail.list_emails → sheets.append_row (multiple) → slack.post_message*

---

### 9. Onboarding нов служител

```
При вход: [Ime на служителя], [Позиция], [Начална дата], [Email].
Напиши персонализиран welcome имейл с: програма за първата седмица,
  линкове към важни ресурси, контакти на ключови хора.
Създай Notion страница в "Onboarding/" с пълното onboarding checklist за позицията.
Изпрати welcome имейл до служителя.
Публикувай в Slack #general: "Поздравете новия ни колега [Ime]! 🎉"
Добави служителя в Airtable таблица "Екип" с начална дата и позиция.
```
*MCP nodes: notion.create_page → gmail.send_email → slack.post_message → airtable.create_record*

---

### 10. Мониторинг на конкурентни цени → Alert

```
Всеки вторник и петък: обходи ценовите страници на [Конкурент 1], [Конкурент 2], [Конкурент 3].
Сравни с нашите цени от Google Sheets "Ценоразпис".
Ако намериш разлика >10%: генерирай alert доклад с детайли.
Запиши всички цени в Sheets "Конкурентен мониторинг" (ред за всяка проверка).
При значима разлика: изпрати имейл до [мениджър@фирма.bg] и публикувай в Slack #pricing.
При стабилни цени: само запиши в Sheets без известия.
```
*MCP nodes: sheets.read_range → sheets.append_row → gmail.send_email (conditional) → slack.post_message (conditional)*

---

### 11. Daily standup summary за екипа

```
Всеки работен ден в 09:00:
Провери Notion "Sprint Tasks" database — задачи по статус (Done/In Progress/Blocked).
Провери Airtable "Issues" — критични проблеми от вчера.
Провери Gmail за имейли от клиенти от последните 24 часа.
Напиши кратко standup резюме: вчера / днес / блокери.
Публикувай в Slack #standup.
```
*MCP nodes: notion.query_database → airtable.list_records → gmail.list_emails → slack.post_message*

---

## Flow-specific настройки за MCP

При конфигуриране на MCP node в Builder-а можеш да зададеш настройки, специфични за този Flow:

### Пример: Google Drive — конкретна папка

```
Конектор: Google Drive
Действие: drive.upload_file

Flow настройки:
  Целева папка: [Доклади/Маркетинг/]  (избираш от папките в Drive)
  Prefix на файла: [Маркетинг_доклад_]
  Формат: [PDF]
```

### Пример: Slack — конкретен канал

```
Конектор: Slack
Действие: slack.post_message

Flow настройки:
  Канал: [#marketing]  (избираш от наличните канали)
  Mention: [@channel за спешни известия]
```

### Пример: Google Sheets — конкретен spreadsheet

```
Конектор: Google Sheets
Действие: sheets.append_row

Flow настройки:
  Spreadsheet: [Продажби 2026]  (избираш от файловете)
  Sheet: [Юни 2026]
  Начален ред: [2]  (1 е header)
```

---

## Следене на MCP действията

### Лог на конектора

Company Settings → Свързани системи → кликни на конектора → **„История"**:

```
23.06 14:32  gmail.send_email   ✅  Имейл до klient@ex.com  (Flow: Оферти)    1.2s
23.06 14:30  sheets.append_row  ✅  Ред добавен в "Оферти"                    0.3s
23.06 13:15  gmail.list_emails  ✅  15 имейла прочетени                       0.8s
22.06 16:44  slack.post_message ❌  Channel not found: #marketting             0.1s
```

При грешка: кликваш на реда → виждаш пълната грешка → коригираш Flow настройките.

### В страницата на Run

В `runs/{id}` → за всеки MCP node виждаш:

```
🔌 Изпрати оферта (gmail.send_email)
   Статус: ✅ Успешно
   До: klient@example.com
   Тема: Оферта за обучение по плуване
   Трае: 1.4s
   Cost: $0 (MCP)
```

---

## Съвети за надеждни MCP Flows

**1. Започни с read-only nodes.** Провери дали четеш правилните данни преди да пишеш. Грешен `sheets.read_range` → грешен изход → грешен `gmail.send_email`.

**2. Approval nodes са твои приятели.** При нов Flow: постави approval на ВСИЧКИ write actions в началото. Когато вземаш, че изходите са правилни — може да намалиш.

**3. Ползвай `gmail.create_draft` вместо `gmail.send_email` докато тестваш.** Чернова не изпраща — можеш да прегледаш в Gmail и да изтриеш без последствия.

**4. Провери variable interpolation преди Run.** В Builder-а → кликни „Провери variables" → системата показва дали всички `{{...}}` са резолвируеми.

**5. Следи лога на конектора.** Ако имаш scheduled Flow и нещо е счупено — ще го видиш там, не в Run страницата.

**6. OAuth tokens изтичат.** Google tokens изтичат на всеки 60 дни при неактивност. Настрой нотификация при `status=expired` → Company Settings → Notifications.

---

## Честа грешка: loop при read + write в един Flow

```
❌ ГРЕШНО:
gmail.list_emails → [обработка] → gmail.send_email
                                        ↓
                           (следващия run чете и собствените изпратени имейли)
```

```
✅ ПРАВИЛНО:
gmail.list_emails (filter: unread, label: INBOX) → [обработка] → gmail.send_email
                                                                       ↓
                                                         gmail.label_email (label: PROCESSED)
```

Винаги маркирай обработените имейли/записи или използвай филтър по дата.

---

## Речник

| Термин | Значение |
|--------|----------|
| **Конектор** | Свързана система на ниво Фирма (Gmail, Sheets...) |
| **Tool** | Конкретно действие на конектора (gmail.send_email) |
| **MCP node** | Node в Flow-а от тип `mcp_action` |
| **Variable** | Placeholder `{{...}}` в tool params |
| **Read-only tool** | list_, get_, read_ — не изисква approval |
| **Write tool** | send_, create_, update_, append_, post_ — изисква approval |
| **Approval gate** | Human-in-the-loop node преди write действие |
| **Scope** | OAuth разрешение (gmail.readonly, gmail.send) |
| **SSRF guard** | Защита срещу вътрешни мрежови заявки от HTTP API connector |
