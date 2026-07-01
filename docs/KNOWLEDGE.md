# Knowledge Base v2

Референтен документ за фирмената RAG система (company knowledge).
Придружава секцията "Knowledge" в `CLAUDE.md` и описва моделите, услугите, потока на обработка и точките за употреба.

## Обзор

Knowledge base v2 е фирмена RAG система.
Всяка `Company` има собствена база знания, изолирана по `company_id`.
Системата приема съдържание от четири вида ресурси: качени файлове, URL адреси, изображения и ръчни бележки.
Съдържанието се нарязва на парчета (chunks), ембедва се и се прави достъпно за агентите по време на изпълнение на flow.
Планерът третира фирменото знание като допълнителен контекст, а не като заместител на уеб инструментите.
Изследователските агенти запазват уеб инструментите си, когато знанието е непълно.

Главната входна точка е `KnowledgeService` (`app/Services/KnowledgeService.php`).
Специализираните услуги живеят в `app/Services/Knowledge/`.

## Модели и таблици

Основната схема се създава в миграцията `database/migrations/2026_06_12_200000_knowledge_v2.php`.
Папките и конфликтите имат отделни миграции.

| Модел | Таблица | Роля |
|---|---|---|
| `KnowledgeResource` | `knowledge_resources` | Един източник: `type` е `url`, `upload`, `image` или `note`; `status` минава през `pending`, `processing`, `ready`, `failed`. |
| `KnowledgePage` | `knowledge_pages` | Една обходена страница на URL ресурс; пази `url`, `title`, `meta_description` и статус. |
| `KnowledgeChunk` | `knowledge_chunks` | Нарязано парче текст с `embedding` (json) и `embedding_provider`; носи `meta` (heading, sheet, section, url, title). |
| `KnowledgeFact` | `knowledge_facts` | Извлечен структуриран факт с `category`, `name`, `value`, `source_type` (`resource`, `page`, `run`, `chat`) и `status` (`active`, `superseded`). |
| `KnowledgeGap` | `knowledge_gaps` | Отворен въпрос, за който няма покритие; вързан към `flow_run_id` и `node_key`; статус `open` или `resolved`. |
| `KnowledgeConflict` | `knowledge_conflicts` | Открито противоречие между факти или източници за ръчно разрешаване. |
| `KnowledgeFolder` | `knowledge_folders` | Организация на ресурсите в дървовидни папки. |
| `KnowledgeEvent` | `knowledge_events` | Одит на промените: `action` е `added`, `updated` или `deleted`, върху `subject_type` `resource`, `page` или `fact`. |
| `KnowledgeChatMessage` | (chat таблица) | Съобщения в чата за знание, включително web полета и обратна връзка. |
| `WebPageCache` | `web_page_cache` | Кеш на суровото съдържание при обхождане на URL. |
| `WebPageDigest` | `web_page_digests` | Дайджест на страница, който може да бъде преизползван между ресурси. |
| `AssistantTaskKnowledgeRequirement` | (requirements таблица) | Свързва задача на асистент с изискванията ѝ към знанието. |

Ембедингите се пазят като json колона директно върху `knowledge_chunks`, `knowledge_facts` и `knowledge_gaps`, заедно с `embedding_provider`.

## Услуги

### Ядро (`app/Services/`)

`KnowledgeService` е фасадата за търсене и рендиране на контекст.
Ключови методи: `search`, `searchChecklist`, `knowledgeBlock`, `ownProfileBlock`, `summary`, `isEmpty`, `providerTag`, `deleteResource`, `deletePage`, `foreignProviderChunks`.
`EmbeddingService` произвежда ембединги и се споделя с flow паметта (един и същ провайдер за двете системи).
`CrawlService` обхожда URL адреси и произвежда страници.
`WebPageCacheService` управлява кеша и дайджестите на страниците, за да не се сваля едно и също съдържание повторно.

### Специализирани (`app/Services/Knowledge/`)

| Услуга | Роля |
|---|---|
| `KnowledgeIngestor` | Оркестрира приемането на ресурс до готови chunks и facts. |
| `DocumentTextExtractor` | Извлича текст от качени документи. |
| `TextChunker` | Нарязва текста на chunks с метаданни. |
| `KnowledgeSynthesizer` | Синтезира съдържание в по-висше ниво знание. |
| `KnowledgeFactService` | Извлича и поддържа структурираните факти (включително `superseded`). |
| `KnowledgeGapService` | Записва и разрешава пропуските в знанието. |
| `KnowledgeConflictService` | Открива и следи противоречията. |
| `KnowledgeRequirementService` | Съпоставя изискванията на задачите към наличното знание. |
| `KnowledgeChatService` | Захранва чата за знание над базата на фирмата. |

## Поток на обработка

1. Потребител добавя ресурс: качва файл, подава URL, качва изображение или пише бележка.
2. `KnowledgeResource` се създава със `status = pending` и приемането се пуска във фонов job.
3. За URL ресурси `CrawlService` обхожда страниците, а `WebPageCacheService` кешира и преизползва дайджести.
4. `DocumentTextExtractor` вади текста; `TextChunker` го нарязва на chunks.
5. `EmbeddingService` ембедва chunks; `KnowledgeFactService` извлича структурирани факти.
6. Ресурсът минава в `status = ready`, а `KnowledgeEvent` записва промяната.
7. Неуспехите записват `error` и `status = failed`; ресурсът може да се преиндексира (reingest).

## Търсене и употреба

По време на flow планерът и агентите извикват `KnowledgeService::search` и `searchChecklist` за релевантни chunks и facts.
`knowledgeBlock` рендира готов контекстен блок, който се вгражда във входа на node.
`ownProfileBlock` рендира профила на самата фирма.
`summary` дава обобщение за UI и статистики.
Знанието е допълнително: когато покритието е слабо, `KnowledgeGapService` записва gap, а изследователските агенти падат обратно към уеб инструменти.

## Контролери и маршрути

Админ приложението монтира знанието под `companies/{company}/knowledge` в `routes/web.php`.

| Контролер | Отговорност |
|---|---|
| `CompanyKnowledgeController` | Ресурси, папки, факти, събития, пропуски, конфликти, качвания, URL адреси, бележки, преиндексиране, изтегляне и дайджести. |
| `KnowledgeChatController` | Изпращане на съобщения, история, сесии, обратна връзка, детайл и статус по токен. |
| `FlowKnowledgeController` | Превключване на достъпа на конкретен flow до знанието (`flows/{flow}/knowledge/toggle`). |

Конфликтите се сканират през `conflicts/scan` и се разрешават или игнорират по отделни маршрути.

## Команди

`php artisan knowledge:detect-conflicts` сканира базата знания за противоречия.

## Кръстосани препратки

- `CLAUDE.md`, секция "Knowledge" за архитектурния обзор.
- `DYNAMIC-AGENT-PLANNER.md` за това как планерът консумира фирменото знание.
- `MCP-CONNECTORS.md` за уеб и конекторните инструменти, които изследователските агенти ползват при пропуски.
