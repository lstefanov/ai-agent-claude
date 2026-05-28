# FlowAI — Project Plan (Skeleton / Test)

> Малък тестов проект за изграждане и тестване на динамични AI агент-базирани flows.
> Без login/register. Фокус върху AI логиката и динамичното генериране на агенти.

---

## Цел на проекта

- Регистрираме **фирми** с кратко описание
- Всяка фирма създава **flows** чрез текстово описание
- AI **автоматично генерира агентите** нужни за всеки flow
- AI **автоматично избира най-подходящия LLM модел** за всеки агент
- Всеки агент се записва в БД с **подробно описание на ролята му**
- Агентите се изпълняват последователно и резултатите се пазят в БД

---

## Технологичен стек

| Слой | Технология |
|------|-----------|
| Backend | PHP 8.2, Laravel 12 |
| База данни | MySQL 5.7 (MAMP PRO) |
| Frontend | Blade + Tailwind CSS 3 |
| Web сървър | Apache (MAMP PRO), домейн: `flowai.local` |
| AI Runtime | Ollama (localhost:11434) — инсталира се чрез `brew install ollama` |
| Image Gen | ComfyUI (localhost:8188) — реална интеграция, инсталира се чрез Python 3.12 + MPS |
| Queue | Laravel Sync Queue (dev) / Database Queue (prod) |
| Scheduler | Laravel Scheduler (cron) |

---

## Ollama модели — пълен списък

### Категория: Текст на български / генерален

| Модел | Ollama тег | RAM | Силни страни |
|-------|-----------|-----|-------------|
| BgGPT | `todorov/bggpt` | ~5GB | Единственият модел специализиран за **български език** |
| LLaMA 3.1 8B | `llama3.1:8b` | ~5GB | Бърз, добър баланс качество/скорост, отличен за English |
| LLaMA 3.1 70B | `llama3.1:70b` | ~40GB | Много висококачествен текст, нужен силен GPU |
| LLaMA 3.2 3B | `llama3.2:3b` | ~2GB | Ултра бърз, за прости задачи |
| Gemma 2 9B | `gemma2:9b` | ~6GB | Google модел, отличен за structured output и анализи |
| Gemma 2 27B | `gemma2:27b` | ~16GB | По-мощна версия на Gemma 2 |

### Категория: Структуриран JSON output / инструкции

| Модел | Ollama тег | RAM | Силни страни |
|-------|-----------|-----|-------------|
| Mistral 7B | `mistral` | ~4GB | Отличен JSON output, следва инструкции прецизно |
| Mistral NeMo 12B | `mistral-nemo` | ~7GB | По-мощен Mistral, добър за агентни задачи |
| Mixtral 8x7B | `mixtral:8x7b` | ~26GB | MoE архитектура, много висок качество |
| Qwen2.5 7B | `qwen2.5:7b` | ~5GB | Alibaba, отличен за structured tasks и многоезичност |
| Qwen2.5 14B | `qwen2.5:14b` | ~9GB | По-мощна версия |

### Категория: Reasoning / Анализи / Решения

| Модел | Ollama тег | RAM | Силни страни |
|-------|-----------|-----|-------------|
| DeepSeek-R1 8B | `deepseek-r1:8b` | ~5GB | Chain-of-thought reasoning, отличен за анализи |
| DeepSeek-R1 32B | `deepseek-r1:32b` | ~20GB | По-мощен reasoning модел |
| QwQ 32B | `qwq:32b` | ~20GB | Alibaba reasoning модел, отличен за сложни решения |
| Phi-4 14B | `phi4` | ~9GB | Microsoft, много добър reasoning при малък размер |

### Категория: QA / Верификация / Бърза проверка

| Модел | Ollama тег | RAM | Силни страни |
|-------|-----------|-----|-------------|
| Phi-3 Mini | `phi3:mini` | ~2GB | Ултра бърз, добър за QA и кратки проверки |
| Phi-3.5 Mini | `phi3.5:mini` | ~2.5GB | Подобрена версия на Phi-3 Mini |
| Gemma 2 2B | `gemma2:2b` | ~2GB | Google, много бърз за прости задачи |
| LLaMA 3.2 1B | `llama3.2:1b` | ~1GB | Най-леката опция, само за прости QA |

### Категория: Код / Технически задачи

| Модел | Ollama тег | RAM | Силни страни |
|-------|-----------|-----|-------------|
| DeepSeek Coder V2 | `deepseek-coder-v2` | ~9GB | Топ модел за код генериране |
| Qwen2.5 Coder 7B | `qwen2.5-coder:7b` | ~5GB | Специализиран за код, бърз |
| CodeLlama 13B | `codellama:13b` | ~8GB | Meta, добър за code completion |
| StarCoder2 7B | `starcoder2:7b` | ~4GB | Hugging Face, добър за code tasks |

### Категория: Многоезичност / Превод

| Модел | Ollama тег | RAM | Силни страни |
|-------|-----------|-----|-------------|
| Qwen2 7B | `qwen2:7b` | ~5GB | 29 езика, отличен multilingual |
| Aya 8B | `aya:8b` | ~5GB | Cohere, специализиран за 23 езика |
| Aya Expanse 8B | `aya-expanse:8b` | ~5GB | По-нова версия на Aya |

### Категория: Vision / Анализ на изображения

| Модел | Ollama тег | RAM | Силни страни |
|-------|-----------|-----|-------------|
| LLaVA 7B | `llava:7b` | ~5GB | Анализ на изображения + текст |
| LLaVA 13B | `llava:13b` | ~8GB | По-добър vision модел |
| Moondream 2 | `moondream` | ~2GB | Ултра лек vision модел |
| LLaMA 3.2 Vision 11B | `llama3.2-vision:11b` | ~8GB | Meta vision, много добър |

---

## Автоматично съответствие агент → модел

AI архитектът (AgentGeneratorService) избира модела по следната логика, вградена в системния prompt:

| Тип агент | Препоръчан модел | Резервен модел | Причина |
|-----------|----------------|---------------|---------|
| `content_bg` | `todorov/bggpt` | `qwen2:7b` | Текст на **български** |
| `content_en` | `llama3.1:8b` | `mistral` | Висококачествен English текст |
| `image_prompt` | `mistral` | `qwen2.5:7b` | Структуриран prompt за ComfyUI |
| `qa_verifier` | `phi3.5:mini` | `gemma2:2b` | Бърза QA проверка |
| `analyzer` | `deepseek-r1:8b` | `phi4` | Reasoning и анализи |
| `researcher` | `qwen2.5:7b` | `mistral-nemo` | Структурирано извличане на инфо |
| `summarizer` | `llama3.1:8b` | `gemma2:9b` | Резюмета на текст |
| `decision` | `deepseek-r1:8b` | `qwq:32b` | Вземане на решения |
| `publisher` | `phi3:mini` | `llama3.2:1b` | Прост агент, не е нужна мощ |
| `translator` | `qwen2:7b` | `aya:8b` | Многоезичен превод |
| `code` | `deepseek-coder-v2` | `qwen2.5-coder:7b` | Генериране на код |
| `vision` | `llama3.2-vision:11b` | `llava:7b` | Анализ на изображения |
| `orchestrator` | `mistral` | `qwen2.5:7b` | JSON planning и координация |

---

## База данни — схема

### `companies`
```
id
name               — Название на фирмата
description        — Кратко описание (използва се като контекст от агентите)
industry           — Сектор (технологии, търговия, медии и т.н.)
language           — Основен език (bg / en / ...) — влияе на избора на модел
created_at, updated_at
```

### `flows`
```
id
company_id         — FK към companies
name               — Название на flow-а
description        — Оригиналното текстово описание от потребителя
status             — draft / active / paused
schedule_cron      — напр. "0 10 * * *" (nullable — ръчно изпълнение)
last_run_at        — кога е изпълнен последно
created_at, updated_at
```

### `agents` — централна таблица с пълно описание

```
id
flow_id            — FK към flows
name               — Кратко име (напр. "Агент за съдържание")
type               — content_bg / content_en / image_prompt / qa_verifier /
                     analyzer / researcher / summarizer / decision /
                     publisher / translator / code / vision / orchestrator
role               — Подробно описание на ролята (TEXT):
                     "Този агент е отговорен за генерирането на текстово
                     съдържание за Facebook пост. Взима контекста на фирмата
                     и темата на деня, и създава ангажиращ пост на български."
capabilities       — JSON масив с умения:
                     ["text_generation", "bulgarian_language", "social_media"]
strengths          — TEXT: "Специализиран за български текст, познава
                     конвенциите на социалните мрежи"
limitations        — TEXT: "Не генерира изображения, не публикува директно"
input_description  — TEXT: описание какво получава като вход
output_description — TEXT: описание какво трябва да върне като изход
prompt_template    — LONGTEXT: шаблонът на prompt-а с {placeholders}
model              — Ollama модел (напр. "todorov/bggpt")
model_reason       — TEXT: защо е избран точно този модел
                     "BgGPT е избран защото фирмата работи на български
                     и съдържанието трябва да е естествено звучащо"
order              — Поредност на изпълнение в flow-а
is_verifier        — bool — дали е QA агент (спира flow при неуспех)
qa_threshold       — int (0-100, default 70) — минимален score за QA агенти
depends_on         — JSON: [agent_id, ...] — от кои агенти зависи
config             — JSON: допълнителни настройки (temperature, max_tokens и т.н.)
is_active          — bool
created_at, updated_at
```

### `llm_models` — регистър на наличните модели

```
id
ollama_tag         — "todorov/bggpt"
display_name       — "BgGPT"
category           — bulgarian / general / json / reasoning / qa / code /
                     vision / multilingual / image_prompt
description        — Подробно описание за какво е подходящ
strengths          — JSON масив
ram_required_gb    — Приблизителна RAM нужда
is_available       — bool (проверява се от Ollama /api/tags)
is_default_for     — JSON: ["content_bg", "translator"] — типове агенти за default
created_at, updated_at
```

### `flow_runs`
```
id
flow_id
status             — pending / running / completed / failed
triggered_by       — manual / scheduler
context            — JSON: споделен контекст между агентите в run-а
started_at, completed_at, created_at
```

### `agent_runs`
```
id
flow_run_id
agent_id
status             — pending / running / completed / failed / skipped
input              — TEXT: точният вход подаден на агента
output             — TEXT: точният изход от агента
model_used         — Ollama модел ползван при изпълнението
tokens_used        — int (ако Ollama го връща)
duration_ms        — int: времетраене в ms
error              — TEXT nullable
started_at, completed_at, created_at
```

---

## Структура на проекта

```
app/
├── Models/
│   ├── Company.php
│   ├── Flow.php
│   ├── Agent.php
│   ├── LlmModel.php
│   ├── FlowRun.php
│   └── AgentRun.php
│
├── Services/
│   ├── OllamaService.php          # Комуникация с Ollama (chat, list models)
│   ├── ComfyUIService.php         # Image generation — реална интеграция с localhost:8188
│   ├── AgentGeneratorService.php  # AI генерира + описва агентите от flow описание
│   ├── ModelSelectorService.php   # Логика за избор на модел по тип агент
│   └── FlowExecutorService.php    # Orchestrator — изпълнява агентите
│
├── Agents/
│   ├── BaseAgent.php              # Абстрактен клас
│   ├── ContentAgent.php           # Текст (BG и EN)
│   ├── ImagePromptAgent.php       # Генерира prompt за ComfyUI
│   ├── QaVerifierAgent.php        # QA проверка + score
│   ├── AnalyzerAgent.php          # Анализи и reasoning
│   ├── ResearcherAgent.php        # Mock research
│   ├── SummarizerAgent.php        # Резюмета
│   ├── DecisionAgent.php          # Вземане на решения
│   ├── PublisherAgent.php         # Mock publisher
│   ├── TranslatorAgent.php        # Превод
│   └── OrchestratorAgent.php     # Мета-агент за координация
│
├── Jobs/
│   ├── ExecuteFlowJob.php
│   ├── ExecuteAgentJob.php
│   └── SyncOllamaModelsJob.php    # Синхронизира наличните модели от Ollama
│
└── Http/Controllers/
    ├── CompanyController.php
    ├── FlowController.php
    ├── AgentController.php        # Редактиране на агент + смяна на модел
    ├── FlowRunController.php
    └── LlmModelController.php     # Управление на моделите

resources/views/
├── layouts/app.blade.php
├── companies/
│   ├── index.blade.php
│   ├── show.blade.php
│   └── create.blade.php
├── flows/
│   ├── index.blade.php
│   ├── create.blade.php           # Описание → "Генерирай агенти с AI"
│   ├── show.blade.php             # Агенти + история + "Стартирай"
│   └── edit.blade.php
├── agents/
│   └── edit.blade.php             # Пълен edit: роля, модел, prompt
├── runs/
│   └── show.blade.php             # Детайли: Input / Output / Модел / ms
└── models/
    └── index.blade.php            # Списък модели + sync от Ollama
```

---

## AgentGeneratorService — как работи

### Стъпка 1: Генериране на агенти (Mistral)

```
SYSTEM: Ти си AI архитект на агентни системи. Анализираш описания на бизнес процеси
        и проектираш минималния необходим набор от специализирани агенти.
        Всеки агент има ЕДИНСТВЕНА отговорност. Включваш ЗАДЪЛЖИТЕЛНО QA агент.
        Избираш модела спрямо задачата и езика на фирмата.

USER:   Фирма: {company.name}
        Сектор: {company.industry}
        Език: {company.language}
        Описание на фирмата: {company.description}

        Flow описание: "{flow.description}"

        Наличните модели и техните специалности:
        - todorov/bggpt: текст на български
        - llama3.1:8b: генерален английски текст
        - mistral: JSON output, инструкции
        - deepseek-r1:8b: reasoning, анализи
        - phi3.5:mini: бърза QA проверка
        - qwen2.5:7b: structured tasks, многоезичност
        - llama3.2-vision:11b: анализ на изображения
        - deepseek-coder-v2: генериране на код
        [... пълен списък ...]

        Върни САМО валиден JSON масив с агентите.
```

### Стъпка 2: Очакван JSON отговор

```json
[
  {
    "name": "Агент за съдържание",
    "type": "content_bg",
    "role": "Генерира текстово съдържание за Facebook пост на български. Взима контекста на фирмата, темата на деня и тона на бранда, след което създава ангажиращ пост от 80-120 думи с призив за действие.",
    "capabilities": ["text_generation", "bulgarian_language", "social_media_copywriting"],
    "strengths": "Специализиран за естествено звучащ български текст, познава конвенциите на Facebook",
    "limitations": "Не генерира изображения, не публикува директно в социалните мрежи",
    "input_description": "Описание на фирмата, тема на поста, тон (formal/casual)",
    "output_description": "Готов текст за Facebook пост + предложение за заглавие",
    "prompt_template": "Ти си copywriter за {company_name}, фирма в сектор {company_industry}...",
    "model": "todorov/bggpt",
    "model_reason": "Фирмата е българска и съдържанието трябва да звучи естествено на български",
    "order": 1,
    "is_verifier": false,
    "qa_threshold": null,
    "config": { "temperature": 0.8, "max_tokens": 500 }
  },
  {
    "name": "Агент за изображение",
    "type": "image_prompt",
    "role": "Преобразува текстовото описание на поста в детайлен prompt за ComfyUI. Генерира визуални инструкции за изображение, подходящо за Facebook (1200x630px), съобразено с тона и сектора на фирмата.",
    "capabilities": ["prompt_engineering", "visual_description", "image_composition"],
    "strengths": "Структурирани, детайлни ComfyUI prompts, познава визуалните стандарти на социалните мрежи",
    "limitations": "Не генерира изображението директно — само prompt-а за ComfyUI",
    "input_description": "Текстът на поста от Content агента",
    "output_description": "ComfyUI prompt на английски за генериране на изображение",
    "prompt_template": "Create a detailed ComfyUI image generation prompt for a Facebook post...",
    "model": "mistral",
    "model_reason": "Mistral е отличен за структуриран output и следване на точен формат",
    "order": 2,
    "is_verifier": false,
    "qa_threshold": null,
    "config": { "temperature": 0.5, "max_tokens": 300 }
  },
  {
    "name": "QA Верификатор",
    "type": "qa_verifier",
    "role": "Проверява качеството на генерирания Facebook пост по 4 критерия: граматика и правопис, ангажиращост, подходящ тон за платформата, и липса на спам думи. Дава score от 0 до 100 и детайлен feedback. При score под 70 спира flow-а.",
    "capabilities": ["grammar_check", "tone_analysis", "spam_detection", "quality_scoring"],
    "strengths": "Бърз и прецизен, ефективен за структурирани проверки",
    "limitations": "Не редактира съдържанието — само оценява",
    "input_description": "Текстът на поста + метаданни (фирма, платформа)",
    "output_description": "JSON с score (0-100), passed (bool) и feedback",
    "prompt_template": "Провери следния Facebook пост и върни JSON оценка...",
    "model": "phi3.5:mini",
    "model_reason": "Phi-3.5 Mini е бърз и ефективен за QA задачи без нужда от голям модел",
    "order": 3,
    "is_verifier": true,
    "qa_threshold": 70,
    "config": { "temperature": 0.1, "max_tokens": 200 }
  },
  {
    "name": "Агент публикуване",
    "type": "publisher",
    "role": "Финализира поста и го предава към публикуване. Форматира текста, добавя хаштагове, и записва в системата за публикуване по зададен график. В тестовия режим симулира публикуването и записва резултата в БД.",
    "capabilities": ["content_formatting", "hashtag_generation", "scheduling"],
    "strengths": "Надеждно финализиране и форматиране на съдържанието",
    "limitations": "В скелета работи в mock режим — не публикува реално",
    "input_description": "Финалният текст и path към изображението",
    "output_description": "Потвърждение за публикуване с timestamp и mock post ID",
    "prompt_template": "Форматирай следния пост за публикуване и добави 3-5 релевантни хаштага...",
    "model": "phi3:mini",
    "model_reason": "Простата задача не изисква мощен модел — Phi-3 Mini е достатъчен",
    "order": 4,
    "is_verifier": false,
    "qa_threshold": null,
    "config": { "temperature": 0.3, "max_tokens": 150 }
  }
]
```

---

## Изпълнение на flow (Orchestrator)

```
FlowExecutorService::run($flow)
  ↓
FlowRun::create(status=running, context={company, flow_meta})
  ↓
foreach агент (сортирани по order, само is_active=true):
  ↓
  AgentRun::create(status=running, input=context)
  ↓
  ако type == image_prompt → ComfyUIService::generate(output на предишен агент)
  иначе → OllamaService::chat(model, prompt_template + context)
  ↓
  AgentRun::update(output, model_used, duration_ms, status=completed)
  ↓
  context = merge(context, {agent_name: output})   ← резултатът е достъпен за следващия
  ↓
  ако is_verifier == true:
    парсирай score от output
    ако score < qa_threshold:
      FlowRun::failed("QA failed with score {score}")
      СТОП
  ↓
FlowRun::update(status=completed)
```

---

## UI — Страници

### `/` — Начална (Компании)
Grid от карти с фирмите. Бутон "Добави фирма".

### `/companies/{id}` — Фирма
Описание + индустрия + списък с flows (статус, последно изпълнение). Бутон "Нов flow".

### `/companies/{id}/flows/create` — Нов flow
1. Поле: Namn на flow-а
2. Голямо textarea: Описание на flow-а
3. Бутон: **"Генерирай агенти с AI"** (AJAX → AgentGeneratorService)
4. Preview таблица на агентите (name, type, model, role preview)
   - Dropdown за смяна на модела на всеки агент
   - Expandable секция с пълното описание на ролята
5. Бутон: "Запази flow"

### `/flows/{id}` — Flow детайли
- Информация + schedule
- Таблица: агенти с роля, тип, модел, order, QA статус
- Бутон "Стартирай сега"
- Таблица: история на изпълненията (статус, дата, времетраене)

### `/flows/{id}/agents/{agentId}/edit` — Редактиране на агент
- Всички полета редактируеми: name, role, prompt_template
- Dropdown: избор на модел (зарежда от Ollama /api/tags)
- Показва model_reason генериран от AI
- Поле за config (JSON editor)

### `/runs/{id}` — Резултат от изпълнение
Стъпка по стъпка: агент → модел → input → output → duration → статус.
Expandable raw JSON за всеки agent run.

### `/models` — LLM модели
Таблица с всички модели, категория, RAM, наличност.
Бутон "Синхронизирай от Ollama" (вика /api/tags и обновява is_available).

---

## Фази на разработка

### Фаза 1 — Инфраструктура (ден 1-2)
- [ ] Laravel 12 инсталация + Tailwind
- [ ] Всички migrations (6 таблици)
- [ ] Seed: `llm_models` таблица с всички модели
- [ ] OllamaService (chat + stream + list models)
- [ ] ComfyUIService (generate + mock fallback)
- [ ] Базов layout + навигация

### Фаза 2 — Companies & Flows CRUD (ден 2-3)
- [ ] Companies CRUD
- [ ] Flows CRUD
- [ ] AgentGeneratorService (пълен prompt с модели)
- [ ] ModelSelectorService (fallback логика)
- [ ] AJAX endpoint за генериране на агенти
- [ ] Preview UI с dropdown за модел

### Фаза 3 — Agent Execution (ден 3-4)
- [ ] BaseAgent + всички конкретни агент класове
- [ ] FlowExecutorService (orchestrator + context passing)
- [ ] FlowRun + AgentRun с пълно логване
- [ ] ExecuteFlowJob (Queue)

### Фаза 4 — UI за резултати + Scheduler + Модели (ден 4-5)
- [ ] Страница резултати от run
- [ ] Scheduling (cron + Laravel Scheduler)
- [ ] Страница за LLM модели + Ollama sync
- [ ] Статус badges (pending/running/completed/failed)
- [ ] Полиране на Tailwind дизайна

---

## Примерни flows за тестване

| Flow описание | Генерирани агенти | Модели |
|---------------|------------------|--------|
| "FB постове на български с изображение" | Content → ImagePrompt → QA → Publisher | bggpt → mistral → phi3.5 → phi3 |
| "Анализ на новини в сектора" | Researcher → Analyzer → Summarizer → QA | qwen2.5 → deepseek-r1 → llama3.1 → phi3.5 |
| "Ценови анализ на конкуренти" | Researcher → Analyzer → Decision → Report → QA | qwen2.5 → deepseek-r1 → deepseek-r1 → llama3.1 → phi3.5 |
| "Instagram пост с хаштагове" | Content → Hashtag → ImagePrompt → QA → Publisher | bggpt → mistral → mistral → phi3.5 → phi3 |
| "Седмичен бизнес отчет" | Researcher → Analyzer → Summarizer → Translator → QA | qwen2.5 → deepseek-r1 → llama3.1 → qwen2:7b → phi4 |

---

## Инсталация на услугите

### Ollama (Apple Silicon / M3 Max)

```bash
brew install ollama
brew services start ollama     # стартира автоматично при login
# или ръчно: ollama serve
```

### ComfyUI (реална интеграция, MPS backend)

```bash
brew install python@3.12
git clone https://github.com/comfyanonymous/ComfyUI ~/ComfyUI
cd ~/ComfyUI
/opt/homebrew/bin/python3.12 -m venv venv
source venv/bin/activate
pip install torch torchvision torchaudio    # Apple Metal (MPS) backend
pip install -r requirements.txt
# Стартиране: python main.py --listen 127.0.0.1 --port 8188
```

### Startup скрипт (в проекта)

```bash
./scripts/start-services.sh   # стартира Ollama + ComfyUI с едно команда
```

### MAMP PRO Virtual Host

1. Добави в MAMP PRO → Hosts: `flowai.local` → `[project]/public/`, PHP 8.2
2. Добави в `/etc/hosts`: `127.0.0.1 flowai.local`

---

## Ollama модели — инсталация

```bash
# Задължителни (ниски изисквания)
ollama pull todorov/bggpt      # BG текст     ~5GB
ollama pull mistral             # JSON/структура ~4GB
ollama pull phi3.5:mini         # QA бърз       ~2.5GB
ollama pull phi3:mini           # Ultra бърз    ~2GB

# Препоръчани
ollama pull llama3.1:8b         # Генерален     ~5GB
ollama pull deepseek-r1:8b      # Reasoning     ~5GB
ollama pull qwen2.5:7b          # Structured    ~5GB
ollama pull gemma2:9b           # Анализи       ~6GB
ollama pull qwen2:7b            # Multilingual  ~5GB

# По избор (нуждаят се от повече RAM)
ollama pull mistral-nemo        # По-добър Mistral ~7GB
ollama pull phi4                # Reasoning      ~9GB
ollama pull llava:7b            # Vision         ~5GB
ollama pull deepseek-coder-v2   # Код            ~9GB
ollama pull qwen2.5:14b         # По-мощен       ~9GB
```

---

## .env настройки

```env
APP_NAME=FlowAI
APP_URL=http://flowai.local

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=flowai
DB_USERNAME=root
DB_PASSWORD=root

QUEUE_CONNECTION=sync

# Ollama — инсталация: brew install ollama && brew services start ollama
OLLAMA_URL=http://localhost:11434
OLLAMA_GENERATOR_MODEL=mistral
OLLAMA_DEFAULT_FALLBACK=llama3.1:8b

# ComfyUI — реална интеграция (не mock)
# Инсталация: brew install python@3.12 && git clone ComfyUI && pip install torch (MPS)
COMFYUI_URL=http://localhost:8188
COMFYUI_ENABLED=true

# Mock режим за FB, Email и т.н.
INTEGRATIONS_MOCK=true
```

---

## Бъдещи разширения (извън скелета)

- Реален Facebook Graph API publisher
- Email интеграция (IMAP четене + SMTP изпращане)
- Web scraping за новини и цени на конкуренцията
- Login/Register + multi-tenancy
- Webhook notifications при завършен flow
- Визуален drag-and-drop builder за агентите
- Статистики: брой runs, success rate, средно времетраене по модел
- A/B тестване на модели (run един flow с 2 различни модела и сравни)
- RAG (Retrieval-Augmented Generation) — агенти с достъп до документи
