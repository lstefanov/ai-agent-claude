# docs

Карта на документацията на FlowAI.
Тук е активната документация; заменените и завършени документи са в `archive/`.
За операционните правила при работа по кода виж `../CLAUDE.md` в корена на репото.

## Референтни докове (ядро)

Каноничните описания на основните системи.

| Документ | За какво е |
|---|---|
| [DYNAMIC-AGENT-PLANNER.md](DYNAMIC-AGENT-PLANNER.md) | Дизайн на динамичния планер: intent анализ, дизайн на pipeline, критика, фази 2-4. |
| [HORIZON-REDIS.md](HORIZON-REDIS.md) | Работа с Redis и Horizon опашки в локална dev среда. |
| [MCP-CONNECTORS.md](MCP-CONNECTORS.md) | Регистър на MCP конекторите и поведение на действията. |
| [KNOWLEDGE.md](KNOWLEDGE.md) | Фирмена RAG система (knowledge base v2): модели, услуги, ingest, търсене. |
| [BILLING.md](BILLING.md) | Кредити и билинг: резервации, леджър, планове, gate policy, Stripe. |
| [EVAL-SUITE.md](EVAL-SUITE.md) | Eval suite за flow регресии плюс авто-оптимизатор цена/качество. |

## AI Организация

Слоят на работната сила над flow енджина.

| Документ | За какво е |
|---|---|
| [AI-ORGANIZATION-VISION.md](AI-ORGANIZATION-VISION.md) | Визия: Управител, който създава фирма от агенти-персонажи. |
| [AI-ORGANIZATION-IMPLEMENTATION-PLAN.md](AI-ORGANIZATION-IMPLEMENTATION-PLAN.md) | Пълен имплементационен план за AI организацията. |
| [AI-ORG-PROGRESS.md](AI-ORG-PROGRESS.md) | Актуален прогрес по имплементацията. |
| [CLAUDE-CODE-KICKOFF.md](CLAUDE-CODE-KICKOFF.md) | Старт за автономно изпълнение по организацията. |
| [AI-ORGANIZATION-MOCKUP.html](AI-ORGANIZATION-MOCKUP.html) | HTML мокъп на организацията (светла тема). |

## Клиентски портал

| Документ | За какво е |
|---|---|
| [CLIENT-PORTAL-REVAMP-PLAN.md](CLIENT-PORTAL-REVAMP-PLAN.md) | Каноничният план за портала и организацията (V3). |
| [CLIENT-PORTAL-SETUP.md](CLIENT-PORTAL-SETUP.md) | Локална настройка на клиентския портал. |

## Планове и процеси

| Документ | За какво е |
|---|---|
| [UI-UX-REDESIGN-PLAN.md](UI-UX-REDESIGN-PLAN.md) | План за UI/UX редизайна. |
| [MULTI-AGENT-DEV-WORKFLOW.md](MULTI-AGENT-DEV-WORKFLOW.md) | Работен процес за многоагентна разработка по FlowAI. |

## Ръководства за потребителя

| Документ | За какво е |
|---|---|
| [EVAL-SUITE-USER-GUIDE.md](EVAL-SUITE-USER-GUIDE.md) | Ръководство за ползване на eval suite. |
| [MCP-CONNECTORS-USER-GUIDE.md](MCP-CONNECTORS-USER-GUIDE.md) | Ръководство за ползване на конекторите. |

## Туториали и настройка

| Документ | За какво е |
|---|---|
| [tutorial-fb-posts-sports-center.md](tutorial-fb-posts-sports-center.md) | Туториал: FB постове за спортен център от нулата до резултат. |
| [TEST-FLOWS.md](TEST-FLOWS.md) | Примерни тестови flow-ове (PrimeLaser). |
| [windows-ollama-server.md](windows-ollama-server.md) | Свързване с Ollama на Windows сървър. |

## Архив

Заменените, завършени или остарели документи са в [archive/](archive/).
Виж [archive/README.md](archive/README.md) за причината зад всеки преместен файл.
Каноничният план за клиентския портал е `CLIENT-PORTAL-REVAMP-PLAN.md` (V3), не варианта с наставка `-V2`.
