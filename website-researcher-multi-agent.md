# Website Researcher — Multi-Agent Scraping Архитектура

> **Контекст:** Агент "Изследовател на сайта" в FlowAI използва модела `mistral-nemo`, който има ограничен контекстен прозорец. При скрейпване на голям сайт с много страници, моделът не може да обработи цялото съдържание наведнъж. Решението е **map-reduce multi-agent pattern** — всяка страница се обработва от отделен подагент.

---

## Съдържание

1. [Проблем](#1-проблем)
2. [Решение — Обща концепция](#2-решение--обща-концепция)
3. [Архитектура](#3-архитектура)
4. [Фази на изпълнение](#4-фази-на-изпълнение)
5. [Подробно описание на агентите](#5-подробно-описание-на-агентите)
6. [JSON Schema за комуникация](#6-json-schema-за-комуникация)
7. [Логване](#7-логване)
8. [Конфигурация и лимити](#8-конфигурация-и-лимити)
9. [Edge Cases и грешки](#9-edge-cases-и-грешки)
10. [Пример за реален run](#10-пример-за-реален-run)
11. [Бъдещи подобрения](#11-бъдещи-подобрения)

---

## 1. Проблем

| Проблем | Описание |
|---|---|
| **Token limit** | `mistral-nemo` има ограничен контекстен прозорец; голям сайт надвишава лимита |
| **Единичен агент** | Един агент се опитва да обработи цялото съдържание наведнъж → fail |
| **Няма паралелизъм** | Последователното скрейпване е бавно и неефективно |
| **Няма структура** | Изходът е неструктуриран текст, труден за последващ анализ |

---

## 2. Решение — Обща концепция

Прилагаме **Map-Reduce** pattern:

- **Map фаза** — всяка страница се разпределя към отделен подагент, който я обработва независимо
- **Reduce фаза** — резултатите от всички подагенти се обединяват, дедупликират и подават на финален агент за анализ

Всеки подагент вижда само **една страница** → никога не надвишава token лимита.

---

## 3. Архитектура

```
┌─────────────────────────────────────────────────────┐
│              MAIN "Researcher" Agent                │
│         (Оркестратор — управлява целия flow)        │
└──────────────┬──────────────────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────────────────┐
│              ФАЗА 1: Discovery Agent                │
│  • Чете sitemap.xml / robots.txt                    │
│  • Обхожда вътрешни линкове                         │
│  • Класифицира и приоритизира URL-ите               │
│  • Връща структуриран списък с URL-и + приоритети   │
└──────────────┬──────────────────────────────────────┘
               │  [url_list: [{url, priority, type}]]
               ▼
┌─────────────────────────────────────────────────────┐
│           ФАЗА 2: Parallel Sub-agents               │
│                                                     │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐          │
│  │Sub-agent │  │Sub-agent │  │Sub-agent │  ...      │
│  │  Page 1  │  │  Page 2  │  │  Page 3  │          │
│  └────┬─────┘  └────┬─────┘  └────┬─────┘          │
│       │             │             │                 │
│   JSON summary  JSON summary  JSON summary          │
│       │             │             │                 │
│       └─────────────┴─────────────┘                 │
│                     │                               │
│              [ Запис в LOG ]                        │
└──────────────┬──────────────────────────────────────┘
               │  [page_summaries: [JSON, JSON, ...]]
               ▼
┌─────────────────────────────────────────────────────┐
│           ФАЗА 3: Merge & Dedup Agent               │
│  • Обединява всички page summaries                  │
│  • Премахва дублирано съдържание (header/footer)    │
│  • Групира по теми                                  │
│  • Извлича ключова информация                       │
└──────────────┬──────────────────────────────────────┘
               │  [merged_summary: JSON]
               ▼
┌─────────────────────────────────────────────────────┐
│           ФАЗА 4: Report Agent                      │
│  • Получава merged summary                          │
│  • Пише пълен структуриран доклад                   │
│  • Анализира, прави изводи                          │
│  • Записва финалния доклад                          │
└─────────────────────────────────────────────────────┘
```

---

## 4. Фази на изпълнение

### Фаза 0 — Инициализация

```
Main Agent получава входящи параметри:
  - target_url: string          // URL на сайта
  - max_pages: number           // Максимален брой страници (default: 50)
  - max_concurrency: number     // Паралелни подагенти едновременно (default: 5)
  - priority_filter: string[]   // Кои типове страници да се включат
  - report_language: string     // Език на доклада (default: "bg")
```

### Фаза 1 — Discovery (Открий структурата)

1. Зареди `sitemap.xml` → парсни URL-ите
2. Ако няма sitemap → crawl от homepage, следвай вътрешни линкове (depth ≤ 3)
3. Прочети `robots.txt` → изключи забранените пътища
4. Класифицирай всеки URL по тип:
   - `core` → /about, /pricing, /contact, /services, /products
   - `content` → /blog/\*, /news/\*, /articles/\*
   - `legal` → /privacy, /terms, /cookie-policy
   - `other` → всичко останало
5. Сортирай по приоритет: `core` > `content` > `legal` > `other`
6. Ограничи до `max_pages`

### Фаза 2 — Parallel Scraping (Скрейпни паралелно)

1. Main agent взима URL списъка
2. Spawn-ва подагенти на батчове от `max_concurrency`
3. Всеки подагент:
   a. Зарежда страницата
   b. Извлича текстово съдържание (без HTML)
   c. Ако съдържанието е > chunk threshold → разделя на chunks и summarizira поотделно
   d. Генерира JSON summary
   e. Записва в лога
   f. Връща JSON на Main agent
4. Main agent изчаква батча → spawn-ва следващия батч

### Фаза 3 — Merge & Dedup

1. Collect всички JSON summaries
2. Idентифицирай повтарящо се съдържание (header, footer, nav) → премахни
3. Групирай по теми и ключови думи
4. Създай unified knowledge base document

### Фаза 4 — Report Generation

1. Предай unified knowledge base на Report Agent
2. Report Agent анализира и пише структуриран доклад
3. Докладът се записва и показва на потребителя

---

## 5. Подробно описание на агентите

### 5.1 Main Orchestrator Agent

**Роля:** Координира всички останали агенти, управлява state-а на процеса.

**Отговорности:**
- Приема входните параметри
- Стартира Discovery агента
- Управлява опашката от URL-и
- Spawn-ва подагентите на батчове
- Collect-ва резултатите
- Предава данните на Merge агента
- Предава merged данните на Report агента
- Записва финален статус в лога

**Модел:** Може да е по-лек модел, тъй като не обработва голямо съдържание.

---

### 5.2 Discovery Agent

**Роля:** Открива всички URL-и на сайта.

**Входни данни:**
```json
{
  "target_url": "https://example.com",
  "max_pages": 50
}
```

**Изходни данни:**
```json
{
  "discovered_urls": [
    { "url": "https://example.com/about", "type": "core", "priority": 1 },
    { "url": "https://example.com/pricing", "type": "core", "priority": 1 },
    { "url": "https://example.com/blog/post-1", "type": "content", "priority": 2 }
  ],
  "total_found": 3,
  "sitemap_used": true
}
```

**Логика за приоритет:**

| Тип | Приоритет | Примерни пътища |
|---|---|---|
| `core` | 1 (висок) | /about, /pricing, /services, /products, /contact, /team |
| `content` | 2 (среден) | /blog/\*, /news/\*, /case-studies/\* |
| `legal` | 3 (нисък) | /privacy, /terms, /cookies |
| `other` | 4 (най-нисък) | всичко останало |

---

### 5.3 Page Sub-agent

**Роля:** Скрейпва и summarizira една страница.

**Входни данни:**
```json
{
  "url": "https://example.com/about",
  "page_type": "core",
  "max_summary_tokens": 500
}
```

**Логика:**
1. Fetch страницата
2. Strip HTML → чист текст
3. Ако текстът > 2000 думи → раздели на chunks от по 1500 думи с overlap от 200
4. Summarizirай всеки chunk
5. Обедини chunk summary-тата в едно финално summary
6. Върни JSON

**Изходни данни:**
```json
{
  "url": "https://example.com/about",
  "page_type": "core",
  "title": "За нас | Example Company",
  "key_topics": ["история на компанията", "мисия", "екип"],
  "summary": "Example Company е основана през 2015г. Компанията предоставя...",
  "important_data": {
    "founded": "2015",
    "employees": "50+",
    "location": "София, България"
  },
  "word_count": 1240,
  "chunks_used": 1,
  "status": "success"
}
```

---

### 5.4 Merge & Dedup Agent

**Роля:** Обединява всички page summaries в единна база от знания.

**Процес:**
1. Събира всички JSON summaries
2. Извлича всички `key_topics` → build topic graph
3. Групира summaries по теми
4. Идентифицира и премахва повтарящо се съдържание
5. Създава `unified_knowledge_base`

**Изходен формат:**
```json
{
  "site_overview": "...",
  "key_sections": {
    "about": "...",
    "products_services": "...",
    "pricing": "...",
    "contact": "..."
  },
  "important_facts": {},
  "pages_processed": 23,
  "pages_failed": 2
}
```

---

### 5.5 Report Agent

**Роля:** Анализира unified knowledge base и пише финален доклад.

**Структура на доклада:**
1. Резюме (Executive Summary)
2. Обща информация за компанията/сайта
3. Продукти / Услуги
4. Ценообразуване (ако е налично)
5. Конкурентни предимства
6. Ключови факти и данни
7. Препоръки / Заключение

---

## 6. JSON Schema за комуникация

Всички агенти комуникират чрез строго дефинирани JSON схеми. Това осигурява надеждност и лесна валидация.

### Page Summary Schema

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "type": "object",
  "required": ["url", "status", "summary"],
  "properties": {
    "url": { "type": "string", "format": "uri" },
    "page_type": { "type": "string", "enum": ["core", "content", "legal", "other"] },
    "title": { "type": "string" },
    "key_topics": { "type": "array", "items": { "type": "string" } },
    "summary": { "type": "string", "maxLength": 1000 },
    "important_data": { "type": "object" },
    "word_count": { "type": "integer" },
    "chunks_used": { "type": "integer" },
    "status": { "type": "string", "enum": ["success", "failed", "partial"] },
    "error": { "type": "string" }
  }
}
```

---

## 7. Логване

Всеки агент записва в лога при всяко важно събитие.

### Формат на лог запис

```
[TIMESTAMP] [AGENT_TYPE] [STATUS] MESSAGE
```

### Примерни лог записи

```
[2026-06-03 10:00:01] [MAIN] [START] Стартиране на Website Researcher за https://example.com
[2026-06-03 10:00:02] [DISCOVERY] [INFO] Намерени 45 URL-и от sitemap.xml
[2026-06-03 10:00:02] [DISCOVERY] [INFO] Приоритизирани: core=8, content=30, legal=3, other=4
[2026-06-03 10:00:03] [SUB-AGENT-1] [START] Скрейпване на https://example.com/about
[2026-06-03 10:00:03] [SUB-AGENT-2] [START] Скрейпване на https://example.com/pricing
[2026-06-03 10:00:05] [SUB-AGENT-1] [SUCCESS] Summary генериран (450 думи → 1 chunk)
[2026-06-03 10:00:07] [SUB-AGENT-2] [SUCCESS] Summary генериран (2300 думи → 2 chunks)
[2026-06-03 10:00:15] [SUB-AGENT-12] [FAILED] Timeout при зареждане на страница
[2026-06-03 10:01:30] [MERGE] [START] Обединяване на 43/45 summaries (2 failed)
[2026-06-03 10:01:45] [MERGE] [SUCCESS] Unified knowledge base създаден
[2026-06-03 10:02:00] [REPORT] [START] Генериране на финален доклад
[2026-06-03 10:02:30] [REPORT] [SUCCESS] Докладът е готов
[2026-06-03 10:02:30] [MAIN] [DONE] Процесът завърши. 43 успешни, 2 неуспешни страници.
```

---

## 8. Конфигурация и лимити

| Параметър | Default | Описание |
|---|---|---|
| `max_pages` | 50 | Максимален брой URL-и за обработка |
| `max_concurrency` | 5 | Максимален брой паралелни подагенти |
| `chunk_size_words` | 1500 | Думи на chunk при голяма страница |
| `chunk_overlap_words` | 200 | Overlap между chunks |
| `page_timeout_ms` | 15000 | Timeout за зареждане на страница |
| `max_summary_tokens` | 500 | Максимален размер на summary |
| `retry_attempts` | 2 | Брой опити при неуспех |
| `retry_delay_ms` | 2000 | Пауза между опитите |
| `priority_cutoff` | 3 | Включи само типове с приоритет ≤ N |

### Препоръки за `max_concurrency`

| Тип сайт | Препоръка |
|---|---|
| Малък сайт (<20 стр.) | 3-5 |
| Среден сайт (20-100 стр.) | 5-10 |
| Голям сайт (>100 стр.) | 5 (с риск от rate limiting) |

> ⚠️ **Важно:** Високата конкурентност може да предизвика блокиране от целевия сайт. Винаги спазвай `robots.txt` и добавяй малка пауза между батчовете (500-1000ms).

---

## 9. Edge Cases и грешки

| Ситуация | Поведение |
|---|---|
| Страницата не се зарежда (timeout) | Retry 2 пъти → mark as `failed` → продължи |
| Страницата е защитена (401/403) | Mark as `failed` → запиши в лога → продължи |
| Страницата е твърде голяма | Chunk-ване → summarize по chunks |
| Sitemap липсва | Crawl от homepage с depth=3 |
| Всички подагенти fail-ват | Main agent докладва грешка, не стартира Merge/Report |
| Частичен успех (>50% fail) | Предупреждение в лога, продължи с наличните данни |
| Дублирани URL-и в sitemap | Дедупликация преди spawn |
| Redirect вериги | Следвай до 5 redirects, след това mark as `failed` |

---

## 10. Пример за реален run

**Входни данни:**
```
target_url: https://somecompany.com
max_pages: 30
max_concurrency: 5
```

**Ход на изпълнение:**

```
1. Discovery Agent → sitemap.xml → 28 URL-и намерени
2. Приоритизиране:
   - core (priority 1): /about, /services, /pricing, /contact, /team [5 стр.]
   - content (priority 2): /blog/* [18 стр.]
   - legal (priority 3): /privacy, /terms [2 стр.]
   - other (priority 4): /sitemap, /404 [3 стр.]

3. Batch 1 (конкурентно):
   Sub-agent-1 → /about         ✓ summary: 320 думи
   Sub-agent-2 → /services      ✓ summary: 480 думи
   Sub-agent-3 → /pricing       ✓ summary: 210 думи
   Sub-agent-4 → /contact       ✓ summary: 150 думи
   Sub-agent-5 → /team          ✓ summary: 390 думи

4. Batch 2 (конкурентно):
   Sub-agent-6  → /blog/post-1  ✓
   Sub-agent-7  → /blog/post-2  ✓
   Sub-agent-8  → /blog/post-3  ✗ (timeout → retry → ✓)
   Sub-agent-9  → /blog/post-4  ✓
   Sub-agent-10 → /blog/post-5  ✓

   ... (продължава)

5. Merge Agent → 26/28 успешни → unified_knowledge_base.json

6. Report Agent → финален доклад (2-3 стр.)
```

---

## 11. Бъдещи подобрения

### Краткосрочни
- **Кеширане** — запазвай scraped данни, за да не се скрейпва повторно при нов run на същия сайт
- **Incremental updates** — при повторен run скрейпвай само променените страници (чрез Last-Modified header)
- **Screenshot support** — подагентът да прави screenshot за визуален контекст

### Средносрочни
- **Semantic similarity dedup** — вместо просто string matching, използвай embeddings за намиране на семантично дублирано съдържание
- **Dynamic depth** — по-важните секции да се crawl-ват по-дълбоко
- **Multi-language support** — автоматично detect на езика на страницата и summarize на същия език

### Дългосрочни
- **Competitor analysis mode** — сравнение между няколко сайта едновременно
- **Change tracking** — периодично скрейпване и tracking на промени
- **Vector store integration** — записване на summaries в vector database за семантично търсене

---

## Диаграма на данните

```
target_url
    │
    ▼
[Discovery Agent]
    │
    ▼
url_list (JSON array)
    │
    ├─────────────────────────────────┐
    ▼                                 ▼
[Sub-agent 1]              [Sub-agent N]
    │                                 │
page_summary_1 (JSON)    page_summary_N (JSON)
    │                                 │
    └──────────────┬──────────────────┘
                   ▼
           [LOG записи]
                   │
                   ▼
          [Merge & Dedup Agent]
                   │
                   ▼
        unified_knowledge_base (JSON)
                   │
                   ▼
           [Report Agent]
                   │
                   ▼
           final_report.md
```

---

*Документ създаден: 2026-06-03 | Проект: FlowAI | Агент: Website Researcher*
