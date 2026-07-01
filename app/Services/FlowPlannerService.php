<?php

namespace App\Services;

use App\Models\AgentGenerationLog;
use App\Models\AgentTemplate;
use App\Models\Flow;
use App\Models\LlmModel;
use App\Support\LlmContext;
use App\Support\LlmUsage;
use App\Support\ModelLevel;
use App\Support\PaidModel;
use App\Support\TypographicQuotes;
use App\Support\UrlExtractor;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The "agent that creates agents" — a three-phase LLM planner that turns a
 * free-text Flow description into a fully-specified agent DAG:
 *
 *   Phase A  analyzeIntent()   — structured "thinking": deliverable, sources,
 *                                entities, region, key tasks, needed extras.
 *   Phase B  designPipeline()  — single-responsibility agents with prompts,
 *                                tools, dependencies, provider and tuning.
 *   Phase C  critiquePlan()    — evaluator pass; one repair round.
 *
 * Every phase uses GeneratorService::chatJson() (OpenAI Structured Outputs
 * when GENERATOR_PROVIDER=openai — the response physically cannot violate the
 * schema). Each phase is audited in agent_generation_logs, so the builder's
 * "Лог на генерирането" panel shows the planner's full reasoning trail.
 *
 * The planner PROPOSES; deterministic code in AgentGeneratorService GUARANTEES
 * (model selection, num_predict caps, QA wiring, cycle-free dependency graph).
 */
class FlowPlannerService
{
    private const DEFAULT_QA_THRESHOLD = 60;

    /** Tools a `custom` (GenericAgent) step may use. */
    private const AVAILABLE_TOOLS = [
        'web_search' => 'Търсене в интернет (конфигурируем провайдър — Brave/Perplexity) — връща топ резултати със заглавие, URL и описание',
        'pro_search' => 'Премиум търсене в интернет (Perplexity) — по-качествени резултати, domain/регион филтри; за конкурентен анализ и дълбоко проучване',
        'people_search' => 'Търсене на хора (Perplexity) — намира професионалисти по име, позиция, компания или локация, включително публични профили',
        'scrape_page' => 'Извлича пълното текстово съдържание на ЕДНА страница по URL',
        'crawl_site' => 'Обхожда цял сайт страница по страница и връща съдържанието им',
        'discover_urls' => 'Открива списъка от вътрешни URL адреси на даден сайт (без да ги скрейпва)',
        'extract_document' => 'Извлича текст и таблици от PDF/сканиран документ/изображение по URL (Mistral OCR) — ценоразписи, каталози, фактури',
        'google_reviews' => 'Намира Google ревюта и рейтинг за бизнес по име/локация (Google Places API)',
        'knowledge_search' => 'Търси във вътрешната база знания на фирмата (ресурси: документи, бележки, обходени страници, натрупани факти) — ДОПЪЛНИТЕЛЕН източник за наши цени/продукти/условия; НЕ замества търсенето в интернет',
    ];

    /** The intent of the most recent plan() call — persisted on the flow for the plan library. */
    private ?array $lastIntent = null;

    public function __construct(
        private GeneratorService $generator,
        private ModelSelectorService $modelSelector,
        private ModelRouterService $router,
        private PlanLibraryService $planLibrary,
    ) {}

    /**
     * Available when the configured planner provider can answer: cloud
     * (openai/anthropic, най-добро качество) или БЕЗПЛАТНО локално ollama
     * планиране (structured outputs; качеството зависи от локалния модел).
     */
    public function isAvailable(): bool
    {
        return $this->generator->isAvailable();
    }

    public function lastIntent(): ?array
    {
        return $this->lastIntent;
    }

    /**
     * Full planning run. Returns agent dicts in the exact legacy shape that
     * AgentGeneratorService::finalizePlannedAgents() consumes (same shape the
     * builder JS already understands), or [] when planning failed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function plan(Flow $flow, ?callable $onProgress = null, ?string $logToken = null, ModelLevel $level = ModelLevel::Medium, ?string $personaBlock = null, ?array $personaPolicy = null): array
    {
        $intent = $this->analyzeIntent($flow, $onProgress, $logToken);
        $this->lastIntent = $intent;

        $plan = $this->designPipeline($flow, $intent, $level, $onProgress, $logToken, personaBlock: $personaBlock, personaPolicy: $personaPolicy);
        $agents = is_array($plan['agents'] ?? null) ? $plan['agents'] : [];

        // A strong planner rarely under-produces; a single transient short plan
        // should not abort the whole run — retry the design phase once. The retry
        // must not repeat the request verbatim (a low-temperature model fails
        // identically), so it carries explicit corrective feedback.
        if (count($agents) < 3) {
            Log::warning('[FlowPlanner] Pipeline design returned '.count($agents).' agents — retrying once.');
            $plan = $this->designPipeline($flow, $intent, $level, $onProgress, $logToken,
                'ВНИМАНИЕ: предишният опит върна само '.count($agents).' агента вместо пълен pipeline. '
                .'Върни ПЪЛНИЯ DAG с ВСИЧКИ агенти (обикновено 7+), без съкращаване и без да '
                .'прекъсваш текстовите полета по средата.', personaBlock: $personaBlock, personaPolicy: $personaPolicy);
            $agents = is_array($plan['agents'] ?? null) ? $plan['agents'] : [];
        }

        if (count($agents) < 3) {
            Log::warning('[FlowPlanner] Pipeline design still < 3 agents after retry — aborting plan.');

            return [];
        }

        if (config('services.planner.critique', true)) {
            $agents = $this->critiquePlan($flow, $intent, $plan, $onProgress, $logToken);
        }

        return $this->sanitizePlanUrls(
            $this->materialize($agents, $intent, $level, $flow, $logToken),
            UrlExtractor::first($flow->description ?? ''),
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Phase A — intent analysis ("мисленето")
    // ──────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function analyzeIntent(Flow $flow, ?callable $onProgress, ?string $logToken): array
    {
        if ($onProgress) {
            $onProgress('Анализ на заданието');
        }

        $system = <<<'PROMPT'
Ти си анализатор на задания за multi-agent AI workflow система. Получаваш свободен текст
— описание на flow — и извличаш СТРУКТУРИРАНО разбиране на задачата: какво трябва да се
произведе, от какви източници идва информацията, кои са обектите (бизнеси/сайтове/теми),
кой регион/език, и списък от конкретни подзадачи (key_tasks) — "ключовите думи" на заданието,
всяка от които по-късно ще стане отговорност на отделен агент.
Бъди конкретен и изчерпателен в key_tasks: включи и имплицитните изисквания
(напр. "цените се запазват дословно в таблица", "контактите не се резюмират").
СУБЕКТЪТ на заданието е САМО това, което пише в ОПИСАНИЕТО (включително URL-а в него).
НЕ въвеждай външни компании, марки или теми, които ги няма в описанието. Ако има URL —
той е целевият сайт и обектът на анализа.
Ако описанието реферира СОБСТВЕНАТА фирма (нашата фирма, нашите цени, нашите
продукти/услуги, нашите условия), включи 'internal_knowledge' в information_sources.
Отговаряй на български в стойностите.
PROMPT;

        $user = "Описание на flow (това е ЕДИНСТВЕНИЯТ източник на задачата):\n\"{$flow->description}\"";

        $kb = $flow->company ? app(KnowledgeService::class)->summary($flow->company) : null;
        if (! empty($kb['documents']) || ! empty($kb['facts'])) {
            $user .= "\nЗабележка: фирмата има вътрешна база знания ({$kb['documents']} ресурса, {$kb['facts']} факта) — "
                .'тя е ДОПЪЛНИТЕЛЕН източник и не променя нуждата от външно проучване (web/crawl).';
        }

        $result = $this->runPhase('intent_analysis', $system, $user, $this->intentSchema(), [
            'temperature' => 0.1,
            'num_predict' => 2000,
        ], $flow, $logToken);

        // The deterministic URL extractor is more reliable than the LLM for URLs.
        if (($url = UrlExtractor::first($flow->description ?? '')) && empty(array_filter(
            $result['entities'] ?? [],
            fn ($e) => ! empty($e['url']),
        ))) {
            $result['entities'][] = ['name' => parse_url($url, PHP_URL_HOST) ?: $url, 'kind' => 'website', 'url' => $url];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function intentSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'deliverable', 'deliverable_description', 'language', 'entities',
                'information_sources', 'region', 'key_tasks', 'needs_image',
                'needs_hashtags', 'competitor_focus', 'improvement_suggestions', 'complexity',
            ],
            'properties' => [
                'deliverable' => ['type' => 'string', 'enum' => ['report', 'social_post', 'blog_article', 'email', 'newsletter', 'analysis', 'seo_content', 'other']],
                'deliverable_description' => ['type' => 'string'],
                'language' => ['type' => 'string'],
                'entities' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['name', 'kind', 'url'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'kind' => ['type' => 'string', 'enum' => ['business', 'website', 'product', 'person', 'topic']],
                            'url' => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
                'information_sources' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => ['target_website', 'web_search', 'google_reviews', 'internal_knowledge']],
                ],
                'region' => ['type' => ['string', 'null']],
                'key_tasks' => ['type' => 'array', 'items' => ['type' => 'string']],
                'needs_image' => ['type' => 'boolean'],
                'needs_hashtags' => ['type' => 'boolean'],
                'competitor_focus' => ['type' => 'boolean'],
                'improvement_suggestions' => ['type' => 'boolean'],
                'complexity' => ['type' => 'string', 'enum' => ['simple', 'medium', 'complex']],
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Phase B — pipeline design
    // ──────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function designPipeline(Flow $flow, array $intent, ModelLevel $level, ?callable $onProgress, ?string $logToken, ?string $retryFeedback = null, ?string $personaBlock = null, ?array $personaPolicy = null): array
    {
        if ($onProgress) {
            $onProgress('Генериране на агенти');
        }

        $system = <<<'PROMPT'
Ти си архитект на multi-agent AI pipelines. По даден структуриран intent проектираш
ПЪЛЕН production pipeline от агенти като насочен ацикличен граф (DAG).

ЗЛАТНИ ПРАВИЛА:
⚠️ ЕЗИК (ЗАДЪЛЖИТЕЛНО, НАЙ-ВАЖНО): name, role, system_prompt, prompt_template,
qa_custom_prompt, rationale и plan_rationale пиши на БЪЛГАРСКИ. НИКОГА на английски.
Само type е технически идентификатор и остава както е в каталога.
- name: КРАТКО българско име от 2–5 думи (напр. «Извличане на контекст»), а НЕ описание
  и НЕ английско изречение.
- role: кратка българска роля — едно изречение.
- КАВИЧКИ: вътре в текстовите стойности НИКОГА не пиши права двойна кавичка (") и не
  ползвай „…“ — ако ти трябват кавички, пиши «…».
1. ВСЕКИ агент има ТОЧНО ЕДНА конкретна отговорност. По-добре повече малки агенти, отколкото един универсален.
2. Покрий ВСЯКА задача от key_tasks с поне един агент и ДЕКОМПОЗИРАЙ дребно: отделни агенти
   за РАЗЛИЧНИ типове данни (напр. описание на бизнеса; услуги и ЦЕНИ; контакти), отделно
   събиране/crawl, ревюта + sentiment, анализ, препоръки, доклад. НЕ сливай различни задачи
   за извличане в един агент. Целѝ широчина и гранулярност като примерите от библиотеката
   (обикновено 10–14 агента за анализ на сайт). Не добавяй агенти за неискани неща.
3. ГРАФ: всеки агент има уникален uid и depends_on (uid-и, чиито ИЗХОДИ са му вход).
   Използвай РАЗКЛОНЕНИЯ: независими събиращи агенти работят паралелно (fan-out),
   а обединяващ агент зависи от всичките (fan-in). Без цикли.
4. Когато краен агент сглобява от няколко входа, в prompt_template реферирай конкретните
   входове с {{node:Име на агента}} (точното name). За единичен вход ползвай {{input}}.
   Целевият сайт е {{url}} (от описанието) — агентите анализират ИМЕННО него; НИКОГА не
   измисляй или замествай друг бизнес/URL. За целеви сайт ползвай {{url}}.
5. type е от каталога ИЛИ "custom". За custom попълни custom_tools от списъка с tools.
   НЕ измисляй несъществуващи типове или tools.
6. ЗАДЪЛЖИТЕЛНО: точно един bg_text_corrector (предпоследен, коригира финалния текст)
   и точно един qa_verifier с uid "qa_main" (последен, is_verifier=true).
7. system_prompt: 3-5 ПОДРОБНИ изречения на български — точна роля, стил, език, ограничения,
   какво НЕ трябва да прави (без измислици, фактите дословно). Колкото по-детайлен, по-добре.
8. prompt_template: ПОДРОБЕН, минимум 5 изречения (целѝ 400+ символа) на български — точен
   формат на изхода, конкретните входове ({{url}}, {{node:Име}}, {{input}}), КАКВО точно да
   извлече/произведе и какво да изключи. Кратки/общи промпти са НЕприемливи — изравни
   детайла с примерите от библиотеката.
9. {{provider_rule}}
10. temperature: 0.1-0.3 за research/анализ/QA/корекция; 0.6-0.8 за креативно съдържание
    и image промптове.
11. output_size: short (списъци, хаштагове, QA) | medium (постове, имейли) |
    long (доклади, анализи) | unlimited (research дъмпове, пълни корекции на дълъг текст).
12. За research върху цял сайт ползвай deep_researcher (има crawl + map-reduce).
    За Google ревюта — review_analyzer. За тенденции/вирусни теми — trend_researcher.
    trend_researcher НИКОГА не води сайт-анализ pipeline.
13. qa_custom_prompt: конкретна проверка за изхода НА ТОЗИ агент (2-3 изречения, български).
14. rationale: 1 изречение ЗАЩО този агент съществува (връзка с key_tasks).
15. БАЗА ЗНАНИЯ: knowledge_search (ако е наличен) е САМО ДОПЪЛНЕНИЕ към интернет
    инструментите — research/extraction агентите ВИНАГИ пазят web_search/scrape_page/
    crawl_site, независимо какво има в базата. НИКОГА не пиши prompt_template, който
    ограничава агента да търси САМО в базата знания — релевантното фирмено знание се
    инжектира автоматично в промпта на всеки агент.

ПРИМЕРНИ ТОПОЛОГИИ (само структура; имената са ориентировъчни):
- Доклад за сайт: site_context → [deep_researcher, review_analyzer] (паралелно) →
  [analyzer, sentiment_analyzer] → report_writer (fan-in от двата клона + site_context)
  → bg_text_corrector → qa_verifier.
- Social post с картинка: researcher → trend_researcher → analyzer →
  [content_bg, hashtag_generator, image_prompt] (fan-out) → caption_writer (fan-in от трите,
  с {{node:...}} референции) → bg_text_corrector → qa_verifier.
PROMPT;

        $system = strtr($system, ['{{provider_rule}}' => $level->promptRule()]);

        $intentJson = json_encode($intent, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $user = "INTENT (структурираното разбиране на заданието):\n{$intentJson}\n\n"
            ."ОРИГИНАЛНО ОПИСАНИЕ НА FLOW:\n\"{$flow->description}\"\n\n"
            .$this->capabilityCatalog($flow)
            // Фаза 2: most similar PROVEN plans from the library as worked examples.
            .$this->planLibrary->fewShotBlock($intent, (int) config('services.planner.few_shots', 2))
            ."\n\nПроектирай pipeline-а. Върни agents + plan_rationale (кратко обяснение на топологията).";

        if ($retryFeedback !== null) {
            $user .= "\n\n".$retryFeedback;
        }

        // Org: персоната на асистента-автор оформя СТИЛА/ПОДХОДА на проектираните
        // агенти (тон, акценти, предпазливост/смелост) — без да жертва покритие,
        // структура или вярност. Празен блок (админ/не-org) → промптът е непроменен.
        if ($personaBlock !== null && $personaBlock !== '') {
            $user .= "\n\n[ПЕРСОНА НА АВТОРА — асистентът, който проектира и ще движи този flow]\n"
                .$personaBlock
                ."\n\nОтрази този характер и подход в стила и формулировките на агентите (system_prompt/"
                .'prompt_template/role/name). Персоната оформя ПОДХОДА, не коректността — покритието на '
                .'key_tasks, топологията на DAG-а и верността на фактите остават водещи.';
        }

        if (is_array($personaPolicy) && $personaPolicy !== []) {
            $user .= "\n\n[РЕАЛНИ НАСТРОЙКИ ОТ ЧЕРТИТЕ]\n"
                .'Политика за одобрение: '.($personaPolicy['approval_policy'] ?? 'approve_each').".\n"
                .'Ниво за модел при наследяване: '.($personaPolicy['star_tier'] ?? 'medium').".\n"
                .'Подход към инструменти: '.($personaPolicy['tool_bias'] ?? 'analytical').".\n"
                .'Едновременни задачи в директорски цикъл: '.(int) ($personaPolicy['parallelism'] ?? 1).".\n"
                .'Праг за проверка на качество: '.(int) ($personaPolicy['qa_threshold'] ?? self::DEFAULT_QA_THRESHOLD).".\n"
                .'Използвай тези настройки при избора на стъпки: по-креативен служител може да има повече варианти и формулировки; '
                .'по-прецизен служител трябва да има повече фактологична проверка и по-строги инструкции.';
        }

        // 11 агента ≈ 8.5K изходни токена (кирилицата токенизира скъпо) —
        // medium трябва да носи 13-14 агента с margin. Провайдерският
        // max_output_cap клампва, ако моделът поддържа по-малко.
        return $this->runPhase('pipeline_design', $system, $user, $this->planSchema($flow), [
            'temperature' => is_numeric($personaPolicy['planner_temperature'] ?? null) ? (float) $personaPolicy['planner_temperature'] : 0.3,
            'num_predict' => $this->numPredictFor($intent, simple: 8000, medium: 12000, complex: 16000),
        ], $flow, $logToken);
    }

    /** @return array<string, mixed> */
    private function planSchema(?Flow $flow = null): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['agents', 'plan_rationale'],
            'properties' => [
                'plan_rationale' => ['type' => 'string'],
                'agents' => ['type' => 'array', 'items' => $this->agentSpecSchema($flow)],
            ],
        ];
    }

    /**
     * Схемата е условна: полетата mcp_tool + tool_params се добавят САМО когато
     * фирмата има активни MCP конектори. Без конектори схемата е непроменена —
     * нормалната генерация остава байт-идентична (нулев риск).
     *
     * @return array<string, mixed>
     */
    private function agentSpecSchema(?Flow $flow = null): array
    {
        $required = [
            'uid', 'name', 'type', 'custom_tools', 'role', 'system_prompt',
            'prompt_template', 'depends_on', 'provider', 'temperature',
            'output_size', 'qa_custom_prompt', 'is_verifier', 'rationale',
        ];

        $properties = [
            'uid' => ['type' => 'string'],
            'name' => ['type' => 'string'],
            'type' => ['type' => 'string'],
            'custom_tools' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => array_keys(self::AVAILABLE_TOOLS)],
            ],
            'role' => ['type' => 'string'],
            'system_prompt' => ['type' => 'string'],
            'prompt_template' => ['type' => 'string'],
            'depends_on' => ['type' => 'array', 'items' => ['type' => 'string']],
            'provider' => ['type' => 'string', 'enum' => ['ollama', 'openai', 'anthropic', 'deepseek', 'gemini', 'xai', 'qwen']],
            'temperature' => ['type' => 'number'],
            'output_size' => ['type' => 'string', 'enum' => ['short', 'medium', 'long', 'unlimited']],
            'qa_custom_prompt' => ['type' => ['string', 'null']],
            'is_verifier' => ['type' => 'boolean'],
            'rationale' => ['type' => 'string'],
        ];

        if ($this->mcpActionsFor($flow) !== []) {
            $properties['mcp_tool'] = ['type' => ['string', 'null']];
            $properties['tool_params'] = [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['key', 'value'],
                    'properties' => ['key' => ['type' => 'string'], 'value' => ['type' => 'string']],
                ],
            ];
            $required[] = 'mcp_tool';
            $required[] = 'tool_params';
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => $required,
            'properties' => $properties,
        ];
    }

    /**
     * Активните MCP действия за фирмата (connector + tool). [] ако няма
     * конектори → планерът изобщо не вижда MCP (схема + каталог непроменени).
     *
     * @return array<int, array{connector_id:int, connector:string, type:string, tool:string, desc:string, writes:bool}>
     */
    private function mcpActionsFor(?Flow $flow): array
    {
        $company = $flow?->company;
        if (! $company) {
            return [];
        }

        $mcp = app(McpClientService::class);
        $out = [];
        foreach ($company->connectors()->active()->get() as $connector) {
            foreach ($mcp->listTools($connector) as $tool) {
                $out[] = [
                    'connector_id' => $connector->id,
                    'connector' => $connector->display_name ?: $connector->connector_type,
                    'type' => $connector->connector_type,
                    'tool' => $tool['name'],
                    'desc' => $tool['description'] ?? '',
                    'writes' => (bool) ($tool['writes'] ?? false),
                ];
            }
        }

        return $out;
    }

    /**
     * Планерните tool_params идват като списък {key,value} (schema-friendly) →
     * обект key=>value за FlowNode.config.
     *
     * @return array<string, string>
     */
    private function mcpPairsToObject(mixed $pairs): array
    {
        $out = [];
        foreach ((array) $pairs as $pair) {
            if (is_array($pair) && isset($pair['key'])) {
                $value = trim((string) ($pair['value'] ?? ''));
                // Планерска халюцинация-плейсхолдър (<UNKNOWN>, <recipient>…) → празно,
                // за да го попълни потребителят, вместо да изпрати буквално „<UNKNOWN>".
                if (preg_match('/^<[^>]*>$/', $value)) {
                    $value = '';
                }
                $out[(string) $pair['key']] = $value;
            }
        }

        return $out;
    }

    /** Дали описанието на flow-а явно иска директно изпращане без одобрение. */
    private function describesDirectSend(Flow $flow): bool
    {
        return (bool) preg_match(
            '/без\s+одобрение|без\s+потвържден|директно\s+(изпрат|публикув|запиш)|автоматично\s+(изпрат|публикув)|no\s+approval|send\s+directly/iu',
            (string) ($flow->description ?? ''),
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Phase C — plan critique (evaluator-optimizer, one repair round)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array<string, mixed>>  $plan
     * @return array<int, array<string, mixed>>
     */
    private function critiquePlan(Flow $flow, array $intent, array $plan, ?callable $onProgress, ?string $logToken): array
    {
        if ($onProgress) {
            $onProgress('Проверка на плана');
        }

        $system = <<<'PROMPT'
Ти си QA на multi-agent pipeline планове. Получаваш intent и план (agents DAG).
Провери безмилостно:
1. Всяка key_task от intent-а покрита ли е от агент?
2. Зависимостите логични ли са? Има ли агент, който ползва данни, които никой преди него не събира?
3. Има ли излишни/дублиращи се агенти или агенти извън заданието?
4. Fan-in агентите реферират ли правилните входове ({{node:Име}} съвпада с реално name)?
5. Промптите конкретни ли са (формат, тон, какво се запазва дословно)?
6. Има ли точно един bg_text_corrector (предпоследен) и един qa_verifier (uid qa_main, последен)?
НЕ проверявай и НЕ сменяй provider/model на агентите — моделите се назначават
детерминистично СЛЕД критиката и всяка твоя промяна там се отхвърля.
Ако планът е добър → approved=true, issues=[], revised_agents=[], remove_uids=[].
Ако има дефекти → approved=false, опиши ги в issues и върни САМО промените (diff):
- revised_agents: ПЪЛНАТА поправена спецификация САМО на агентите, които променяш или
  добавяш (съществуващ uid = замяна, нов uid = добавяне). НЕ преписвай непроменените
  агенти — планът е голям и преписването му се отрязва.
- remove_uids: uid-овете на агентите за премахване (излишни/дублиращи се).
- Ако премахваш/преименуваш агент, върни в revised_agents и агентите, чиито depends_on
  или {{node:Име}} реферират към него, с поправени референции.
ЕЗИК: всички текстови полета в revised_agents (name, role, system_prompt,
prompt_template, qa_custom_prompt, rationale) са на БЪЛГАРСКИ. Ако заварен агент е с
английско name или role — поправи го на кратко българско (name 2–5 думи).
КАВИЧКИ: в текстовите стойности не пиши права двойна кавичка (") и не ползвай „…“ —
ако ти трябват кавички, пиши «…».
PROMPT;

        $user = "INTENT:\n".json_encode($intent, JSON_UNESCAPED_UNICODE)
            ."\n\nПЛАН:\n".json_encode($plan, JSON_UNESCAPED_UNICODE)
            ."\n\nКаталогът от типове и tools е същият като при проектирането:\n"
            .$this->capabilityCatalog($flow);

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['approved', 'issues', 'revised_agents', 'remove_uids'],
            'properties' => [
                'approved' => ['type' => 'boolean'],
                'issues' => ['type' => 'array', 'items' => ['type' => 'string']],
                'revised_agents' => ['type' => 'array', 'items' => $this->agentSpecSchema()],
                'remove_uids' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];

        // Diff изход: issues + само променените агенти (на 11-агентен план
        // пълното преписване е ~8.5K токена и се срязваше на капа). Подът по
        // agent count покрива план, в който много агенти са дефектни.
        $agentCount = count($plan['agents'] ?? []);
        $critiqueBudget = max(
            $this->numPredictFor($intent, simple: 4000, medium: 6000, complex: 8000),
            $agentCount * 300,
        );

        try {
            $result = $this->runPhase('plan_critique', $system, $user, $schema, [
                'temperature' => 0.1,
                'num_predict' => $critiqueBudget,
            ], $flow, $logToken);
        } catch (Throwable $e) {
            // Critique is an enhancement — never let it kill a valid plan.
            Log::warning('[FlowPlanner] Critique phase failed, keeping original plan: '.$e->getMessage());

            return $plan['agents'];
        }

        $revised = is_array($result['revised_agents'] ?? null) ? $result['revised_agents'] : [];
        $removeUids = array_map('strval', is_array($result['remove_uids'] ?? null) ? $result['remove_uids'] : []);

        // Blast-radius гард: легитимното премахване е 1-2 дублиращи се агента.
        // Критика, която иска да реже над 1/4 от плана, е объркана (виждано на
        // живо: mini модел изтри 4/11 агента заради измислено правило) —
        // целият diff се отхвърля, планът остава.
        if (count($removeUids) > max(1, intdiv($agentCount, 4))) {
            Log::warning(sprintf(
                '[FlowPlanner] Critique wanted to remove %d/%d agents — diff rejected, keeping original plan. Issues: %s',
                count($removeUids), $agentCount, implode(' | ', $result['issues'] ?? []),
            ));

            return $plan['agents'];
        }

        if (! ($result['approved'] ?? true) && ($revised !== [] || $removeUids !== [])) {
            $merged = $this->mergeRevisedAgents($plan['agents'], $revised, $removeUids);

            // Sanity floor: критиката не може да остави план без съдържание.
            if (count($merged) >= 3) {
                Log::info('[FlowPlanner] Critique revised the plan: '.implode(' | ', $result['issues'] ?? []));

                return $merged;
            }
        }

        return $plan['agents'];
    }

    /**
     * Apply the critique diff deterministically: existing uid → replace in
     * place, new uid → append, remove_uids → drop. Dangling depends_on left
     * by a removal are pruned later by AgentGeneratorService (references are
     * validated against existing uids).
     *
     * @param  array<int, array<string, mixed>>  $agents
     * @param  array<int, array<string, mixed>>  $revised
     * @param  array<int, string>  $removeUids
     * @return array<int, array<string, mixed>>
     */
    private function mergeRevisedAgents(array $agents, array $revised, array $removeUids): array
    {
        $byUid = [];
        foreach ($agents as $i => $agent) {
            $byUid[(string) ($agent['uid'] ?? 'idx_'.$i)] = $agent;
        }

        foreach ($revised as $agent) {
            if (! is_array($agent)) {
                continue;
            }
            $uid = (string) ($agent['uid'] ?? '');
            if ($uid === '') {
                continue;
            }
            $byUid[$uid] = $agent;
        }

        foreach ($removeUids as $uid) {
            unset($byUid[$uid]);
        }

        return array_values($byUid);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Фаза 3 — adaptive replanning: revise one failing agent mid-run
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Given a failing node (QA gate fail or degenerate output), ask the planner
     * for a corrected spec: better prompts, adjusted temperature, and optionally
     * an escalation to the configured paid provider for this run.
     *
     * @param  array{name: string, type: string, system_prompt: string, prompt_template: string, model: string, temperature: float|null}  $agentSpec
     * @return array{system_prompt: string, prompt_template: string, temperature: float, escalate: bool, reason: string}|null
     */
    public function reviseAgent(array $agentSpec, string $inputExcerpt, string $badOutput, string $failureReason): ?array
    {
        $system = <<<'PROMPT'
Ти си експерт по поправка на AI агенти в multi-agent pipeline. Един агент произведе
лош изход. Анализирай провала и върни ПОПРАВЕНА спецификация:
- Пренапиши system_prompt и prompt_template така, че конкретният дефект да не може
  да се повтори (добави изрични забрани/изисквания срещу точно тази грешка).
- Запази placeholder-ите ({{input}}, {{url}}, {{node:...}}) непокътнати.
- Коригирай temperature, ако е неподходяща (фактология → ниска).
- escalate=true САМО ако дефектът изглежда причинен от слаб локален модел
  (халюцинации, игнорирани инструкции, изроден текст), не от лош промпт —
  тогава стъпката ще се изпълни на по-силен платен модел.
Отговаряй на български в текстовите полета.
PROMPT;

        $user = "АГЕНТ:\n".json_encode($agentSpec, JSON_UNESCAPED_UNICODE)
            ."\n\nВХОД (откъс):\n".mb_substr($inputExcerpt, 0, 2000)
            ."\n\nЛОШ ИЗХОД (откъс):\n".mb_substr($badOutput, 0, 2000)
            ."\n\nПРИЧИНА ЗА ПРОВАЛА:\n{$failureReason}";

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['system_prompt', 'prompt_template', 'temperature', 'escalate', 'reason'],
            'properties' => [
                'system_prompt' => ['type' => 'string'],
                'prompt_template' => ['type' => 'string'],
                'temperature' => ['type' => 'number'],
                'escalate' => ['type' => 'boolean'],
                'reason' => ['type' => 'string'],
            ],
        ];

        try {
            $revised = $this->generator->chatJson($system, $user, 'agent_revision', $schema, [
                'temperature' => 0.2,
                'num_predict' => 4000,
            ]);
        } catch (Throwable $e) {
            Log::warning('[FlowPlanner] reviseAgent failed: '.$e->getMessage());

            return null;
        }

        if (trim((string) ($revised['prompt_template'] ?? '')) === '') {
            return null;
        }

        return [
            'system_prompt' => trim((string) $revised['system_prompt']),
            'prompt_template' => trim((string) $revised['prompt_template']),
            'temperature' => max(0.0, min(1.2, (float) ($revised['temperature'] ?? 0.3))),
            'escalate' => (bool) ($revised['escalate'] ?? false),
            'reason' => trim((string) ($revised['reason'] ?? '')),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Builder Copilot entry points
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The capability catalog as the design phase sees it — agent types, tools
     * and models. Exposed for the Builder Copilot's get_capabilities tool.
     */
    public function capabilityCatalogText(?Flow $flow = null): string
    {
        return $this->capabilityCatalog($flow);
    }

    /**
     * Evaluate an EXISTING graph (the builder's working copy) against the
     * flow's intent — critique without revision, for the copilot's
     * evaluate_flow tool.
     *
     * @param  array<int, array<string, mixed>>  $agents  compact agent dicts (name, type, role, prompts, model, depends_on)
     * @return array{approved: bool, issues: list<string>, suggestions: list<string>}
     */
    public function critiqueExistingPlan(Flow $flow, array $agents): array
    {
        $system = <<<'PROMPT'
Ти си QA на multi-agent pipeline планове. Получаваш intent/описание на flow и ТЕКУЩИЯ му
граф от агенти (както е в builder-а). Оцени го безмилостно, но конструктивно:
1. Покрива ли графът всички задачи от заданието?
2. Зависимостите логични ли са? Ползва ли агент данни, които никой преди него не събира?
3. Има ли излишни/дублиращи се агенти или агенти извън заданието?
4. Промптите конкретни ли са (формат, тон, какво се пази дословно)?
5. Подходящи ли са моделите и температурите за задачите?
6. Има ли bg_text_corrector предпоследен и qa_verifier последен?
Върни approved (true САМО ако няма съществени дефекти), issues (конкретните дефекти) и
suggestions (конкретни подобрения, назоваващи засегнатите агенти по име).
Отговаряй на български. КАВИЧКИ: в текстовите стойности не пиши права двойна кавичка (")
— ако ти трябват кавички, пиши «…».
PROMPT;

        $intent = $flow->activeVersion?->plan_intent ?: ['description' => (string) $flow->description];

        $user = "INTENT/ОПИСАНИЕ:\n".json_encode($intent, JSON_UNESCAPED_UNICODE)
            ."\n\nТЕКУЩ ГРАФ (агенти):\n".json_encode($agents, JSON_UNESCAPED_UNICODE)
            ."\n\n".$this->capabilityCatalog($flow);

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['approved', 'issues', 'suggestions'],
            'properties' => [
                'approved' => ['type' => 'boolean'],
                'issues' => ['type' => 'array', 'items' => ['type' => 'string']],
                'suggestions' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];

        $result = $this->generator->chatJson($system, TypographicQuotes::normalize($user), 'plan_critique', $schema, [
            'temperature' => 0.1,
            'num_predict' => 4000,
        ]);

        return [
            'approved' => (bool) ($result['approved'] ?? false),
            'issues' => array_values(array_map('strval', (array) ($result['issues'] ?? []))),
            'suggestions' => array_values(array_map('strval', (array) ($result['suggestions'] ?? []))),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Materialization — planner spec → legacy agent dicts (deterministic)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array<string, mixed>>  $agents
     * @return array<int, array<string, mixed>>
     */
    private function materialize(array $agents, array $intent, ModelLevel $level, Flow $flow, ?string $logToken): array
    {
        $language = $this->normalizeLanguage((string) ($intent['language'] ?? 'bg'));
        $providerPins = $this->resolveProviderPins($agents, $level, $language, $flow, $logToken);

        $out = [];
        $verifierSeen = false;

        foreach ($agents as $i => $spec) {
            if (! is_array($spec) || empty($spec['name'])) {
                continue;
            }

            $type = trim((string) ($spec['type'] ?? 'content_bg'));
            $isVerifier = ($type === 'qa_verifier') || (bool) ($spec['is_verifier'] ?? false);
            $uid = trim((string) ($spec['uid'] ?? ''));
            // The pins are keyed by the spec's original uid — capture it before
            // the first verifier's uid is rewritten to qa_main.
            $pinKey = $uid !== '' ? $uid : $i;

            // The first verifier always becomes qa_main — the uid every step-QA config references.
            if ($isVerifier && ! $verifierSeen) {
                $uid = 'qa_main';
                $verifierSeen = true;
            }

            $config = [
                'temperature' => $this->clampTemperature($spec['temperature'] ?? null, $type),
                // Honored by AgentGeneratorService::normalizeAgentConfig — the planner's
                // size intent wins over the per-type default (important for `custom`).
                'planner_num_predict' => $this->numPredictForSize((string) ($spec['output_size'] ?? 'medium')),
            ];

            if ($type === 'custom') {
                $allowed = array_keys(self::AVAILABLE_TOOLS);
                // Планерът ПРЕДЛАГА, кодът ГАРАНТИРА: без база знания
                // knowledge_search се маха детерминистично от плана.
                if ($flow->company === null || app(KnowledgeService::class)->isEmpty($flow->company)) {
                    $allowed = array_values(array_diff($allowed, ['knowledge_search']));
                }
                $config['tools'] = array_values(array_intersect(
                    array_map('strval', (array) ($spec['custom_tools'] ?? [])),
                    $allowed,
                ));
            }

            // mcp_action: резолвираме конектора по namespace-а на tool-а
            // (gmail.* → gmail, sheets.* → google_sheets) към активен конектор на
            // фирмата. Параметрите идват като {key,value} двойки → обект. Без
            // конектор → config.connector_id=null (генераторът ще го отсее).
            if ($type === 'mcp_action') {
                $mcpTool = (string) ($spec['mcp_tool'] ?? '');
                $ns = explode('.', $mcpTool)[0];
                $connectorType = config("mcp.tool_namespaces.$ns", $ns);
                $connector = $flow->company?->connectors()->active()
                    ->where('connector_type', $connectorType)->first();
                $config['connector_id'] = $connector?->id;
                $config['tool'] = $mcpTool;
                $config['tool_params'] = $this->mcpPairsToObject($spec['tool_params'] ?? []);
                // Write tool → одобрение, ОСВЕН ако описанието иска директно изпращане
                // (потребителски опт-аут; генераторът уважава false).
                $isWrite = in_array($mcpTool, (array) config('mcp.write_tools', []), true);
                $config['requires_approval'] = $isWrite && ! $this->describesDirectSend($flow);
            }

            // Site-wide research gets the proven map-reduce treatment by default.
            if ($type === 'deep_researcher') {
                $config += [
                    'map_reduce' => true,
                    'max_pages_to_scrape' => 200,
                    'map_concurrency' => 4,
                    'max_page_chars' => 30000,
                    'page_summary_tokens' => 1500,
                ];
            }

            if (! $isVerifier) {
                // Per-agent QA defaults. The final gate (bg_text_corrector) is
                // enabled deterministically by AgentGeneratorService::enableFinalQaGate;
                // any other agent's gate is opt-in from the builder's QA panel.
                // The verifier is synthesized at run time from these criteria —
                // no separate verifier node is needed.
                $config['qa'] = [
                    'enabled' => false,
                    'threshold' => self::DEFAULT_QA_THRESHOLD,
                    'max_retries' => 3,
                    'custom_prompt' => trim((string) ($spec['qa_custom_prompt'] ?? ''))
                        ?: 'Провери дали изходът изпълнява описаната роля на агента, базиран е на реалните входни данни и е на правилния език.',
                ];
            }

            $out[] = [
                'name' => (string) $spec['name'],
                'type' => $type,
                'role' => trim((string) ($spec['role'] ?? '')),
                'capabilities' => $type === 'custom' ? ($config['tools'] ?? []) : [],
                'strengths' => null,
                'limitations' => null,
                'input_description' => empty($spec['depends_on'])
                    ? 'Описание на flow-а / seed контекст.'
                    : 'Изходите на: '.implode(', ', (array) $spec['depends_on']),
                'output_description' => trim((string) ($spec['rationale'] ?? '')),
                'system_prompt' => trim((string) ($spec['system_prompt'] ?? '')),
                'prompt_template' => trim((string) ($spec['prompt_template'] ?? '')),
                // Paid prefixes (gemini/…, openai/…) are preserved by
                // normalizeAgent; '' lets the ModelSelector pick the best
                // installed Ollama model.
                'model' => $providerPins[$pinKey]['model'] ?? '',
                'model_reason' => trim((string) ($providerPins[$pinKey]['reason'] ?? ''))
                    ?: trim((string) ($spec['rationale'] ?? '')),
                'order' => $i + 1,
                'is_verifier' => $isVerifier,
                'qa_threshold' => $isVerifier ? self::DEFAULT_QA_THRESHOLD : null,
                'config' => $config,
                'uid' => $uid !== '' ? $uid : null,
                'depends_on' => array_values(array_filter(array_map('strval', (array) ($spec['depends_on'] ?? [])))),
                'output_language' => $language,
            ];
        }

        return $out;
    }

    /**
     * Replace every absolute URL in agent text with the {{url}} placeholder
     * (resolved to the flow's real site — or a per-run override — at run time).
     * Covers both hallucinated/foreign domains (models that fabricate a domain
     * from the company name) and the real site itself, so a proven plan stays
     * portable: the same flow can be re-run against any target site.
     *
     * @param  array<int, array<string, mixed>>  $agents
     * @return array<int, array<string, mixed>>
     */
    private function sanitizePlanUrls(array $agents, ?string $realUrl): array
    {
        $realHost = $realUrl ? parse_url($realUrl, PHP_URL_HOST) : null;
        if (! $realHost) {
            return $agents;
        }

        $fields = ['name', 'role', 'system_prompt', 'prompt_template', 'output_description', 'input_description'];

        foreach ($agents as &$agent) {
            foreach ($fields as $f) {
                if (! is_string($agent[$f] ?? null) || $agent[$f] === '') {
                    continue;
                }
                $agent[$f] = preg_replace('#https?://[^\s"\'<>)\]}]+#i', '{{url}}', $agent[$f]);
            }
        }
        unset($agent);

        return $agents;
    }

    /**
     * Map the planner's specs to model pins via the task-aware router. The
     * planner's per-agent provider е само ПРЕПОРЪКА (vote бонус в скоринга) —
     * квотите на нивото и конкретният избор са в ModelRouterService.
     *
     * @param  array<int, array<string, mixed>>  $agents
     * @return array<int|string, array{model: string, reason: string}> uid (или индекс) → pin
     */
    private function resolveProviderPins(array $agents, ModelLevel $level, string $language, Flow $flow, ?string $logToken): array
    {
        $items = [];
        foreach ($agents as $i => $spec) {
            if (! is_array($spec) || empty($spec['name'])) {
                continue;
            }

            $type = trim((string) ($spec['type'] ?? 'content_bg'));
            $provider = (string) ($spec['provider'] ?? 'ollama');

            $items[] = [
                'key' => (string) (trim((string) ($spec['uid'] ?? '')) ?: $i),
                'type' => $type,
                'name' => (string) ($spec['name'] ?? ''),
                'role' => (string) ($spec['role'] ?? ''),
                'prompt' => trim((string) ($spec['system_prompt'] ?? '').' '.(string) ($spec['prompt_template'] ?? '')),
                'tools' => array_values(array_map('strval', (array) ($spec['custom_tools'] ?? []))),
                'fan_in' => count((array) ($spec['depends_on'] ?? [])),
                'output_language' => $language,
                'num_predict' => $this->numPredictForSize((string) ($spec['output_size'] ?? 'medium')),
                'temperature' => is_numeric($spec['temperature'] ?? null) ? (float) $spec['temperature'] : 0.3,
                'map_reduce' => $type === 'deep_researcher',
                'is_verifier' => ($type === 'qa_verifier') || (bool) ($spec['is_verifier'] ?? false),
                'planner_provider' => array_key_exists($provider, PaidModel::PREFIXES) ? $provider : null,
            ];
        }

        return $this->router->assign($items, $level, $flow, $logToken);
    }

    private function clampTemperature(mixed $value, string $type): float
    {
        $default = match ($type) {
            'qa_verifier' => 0.1,
            'bg_text_corrector' => 0.2,
            'image_prompt' => 0.8,
            default => 0.3,
        };

        return is_numeric($value) ? max(0.0, min(1.2, (float) $value)) : $default;
    }

    private function numPredictForSize(string $size): int
    {
        return match ($size) {
            'short' => 1000,
            'long' => 6000,
            'unlimited' => -1,
            default => 3000, // medium
        };
    }

    /** The intent may say "български"/"Bulgarian" — BaseAgent expects a 2-letter code. */
    private function normalizeLanguage(string $language): string
    {
        $language = mb_strtolower(trim($language));

        return match (true) {
            $language === '' || str_contains($language, 'бълг') || str_starts_with($language, 'bg') || str_contains($language, 'bulgar') => 'bg',
            str_starts_with($language, 'en') || str_contains($language, 'англ') || str_contains($language, 'english') => 'en',
            str_starts_with($language, 'de') || str_contains($language, 'немски') || str_contains($language, 'german') => 'de',
            default => mb_strlen($language) === 2 ? $language : 'bg',
        };
    }

    // ──────────────────────────────────────────────────────────────────────
    // Capability registry — what the system can actually do
    // ──────────────────────────────────────────────────────────────────────

    private function capabilityCatalog(?Flow $flow = null): string
    {
        // Базата знания на фирмата: tool-ът се ПОКАЗВА само когато има какво
        // да се търси; materialize() е детерминистичната гаранция.
        $kb = $flow?->company ? app(KnowledgeService::class)->summary($flow->company) : null;
        $kbReady = ! empty($kb['documents']);

        $lines = ['НАЛИЧНИ ТИПОВЕ АГЕНТИ (type → описание):'];

        $templates = AgentTemplate::whereNull('company_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['type', 'description'])
            ->unique('type');

        if ($templates->isEmpty()) {
            foreach (config('agent_types', []) as $slug => $info) {
                $lines[] = "- {$slug} → ".($info['description'] ?? '');
            }
        } else {
            foreach ($templates as $t) {
                $lines[] = "- {$t->type} → {$t->description}";
            }
        }

        $lines[] = '- custom → Универсален агент "on the fly": изпълнява избраните custom_tools и подава материала към LLM промпта. Използвай го, когато никой каталожен тип не пасва точно.';

        $lines[] = '';
        $lines[] = 'НАЛИЧНИ TOOLS (за type=custom):';
        foreach (self::AVAILABLE_TOOLS as $name => $desc) {
            if ($name === 'knowledge_search' && ! $kbReady) {
                continue; // празна база знания → планерът изобщо не вижда тула
            }
            $lines[] = "- {$name} → {$desc}";
        }

        // MCP ДЕЙСТВИЯ — реални операции в свързаните системи на фирмата. Показват
        // се само когато има активни конектори (иначе планерът не вижда mcp_action).
        $mcpActions = $this->mcpActionsFor($flow);
        if ($mcpActions !== []) {
            $lines[] = '';
            $lines[] = 'НАЛИЧНИ MCP ДЕЙСТВИЯ (реални системи — type="mcp_action"):';
            foreach ($mcpActions as $a) {
                $mark = $a['writes'] ? ' — WRITE: ИЗИСКВА human_approval ПРЕДИ него' : ' (read-only)';
                $lines[] = "- {$a['tool']} («{$a['connector']}») → {$a['desc']}{$mark}";
            }
            $lines[] = 'Добави mcp_action агент САМО когато заданието явно иска действие в такава система '
                .'(изпрати/запиши/публикувай/прочети). Полета: type="mcp_action", mcp_tool=точното име на '
                .'действието по-горе, tool_params=списък {key,value}. За да вмъкнеш изхода на ПРЕДХОДЕН агент '
                .'ползвай ТОЧНОТО МУ ИМЕ (name): {{agent.<точното name на агента>.output}} — НЕ uid. '
                .'За вход от потребителя: {{flow.input.X}}. НИКОГА не измисляй стойности (получател/имейл/линк): '
                .'ако не е подаден, остави полето ПРАЗНО (потребителят ще го попълни). За WRITE действие '
                .'ЗАДЪЛЖИТЕЛНО добави отделен агент type="human_approval" и сложи неговия uid в depends_on на '
                .'mcp_action. mcp_action не пише текст — само изпълнява действието.';
        }

        if ($kbReady) {
            $folders = empty($kb['folders']) ? '' : ' (папки: '.implode(', ', $kb['folders']).')';
            $titles = empty($kb['titles']) ? '' : ' Примерни ресурси: «'.implode('», «', array_slice($kb['titles'], 0, 5)).'».';
            $lines[] = '';
            $lines[] = "БАЗА ЗНАНИЯ НА ФИРМАТА: {$kb['documents']} ресурса, {$kb['facts']} факта, {$kb['chunks']} откъса{$folders}.{$titles}";
            $lines[] = 'Знанието е ДОПЪЛНИТЕЛЕН източник, НЕ замяна на интернет: релевантните факти се '
                .'инжектират АВТОМАТИЧНО в промпта на всеки съдържателен агент, а custom агент може да '
                .'добави knowledge_search КЪМ другите си tools за наши цени/продукти/условия.';
            $lines[] = 'ЗАБРАНЕНО: агент, чийто промпт казва да търси САМО в базата знания, или '
                .'research/extraction агент само с knowledge_search без интернет инструменти '
                .'(web_search/scrape_page/crawl_site) — независимо какво вече има в базата.';
        }

        $models = LlmModel::where('is_available', true)
            ->where('is_enabled', true)
            ->orderBy('category')
            ->limit(20)
            ->pluck('ollama_tag')
            ->all();

        $cheap = [];
        $premium = [];
        foreach (array_keys(PaidModel::PREFIXES) as $provider) {
            if (! PaidModel::available($provider)) {
                continue;
            }
            $option = $provider.' → '.PaidModel::strip(PaidModel::pin($provider));
            PaidModel::isPremium($provider) ? $premium[] = $option : $cheap[] = $option;
        }

        $lines[] = '';
        $lines[] = 'ИЗПЪЛНЕНИЕ (provider на всеки агент):';
        $lines[] = '- ollama (локално, безплатно): моделът се избира автоматично от кода (инсталирани: '
            .(empty($models) ? 'стандартен набор' : implode(', ', $models)).'). '
            .'Задължително за български текст към краен потребител.';
        if ($cheap !== []) {
            $lines[] = '- Евтини cloud — предпочитани за research/анализ/екстракция/стриктен JSON, работят паралелно: '
                .implode('; ', $cheap).'.';
        }
        if ($premium !== []) {
            $lines[] = '- Premium — само за най-критичния fan-in синтез (бюджетирани): '
                .implode('; ', $premium).'.';
        }

        return implode("\n", $lines);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Phase runner + audit logging
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Output-token budget scaled by the intent complexity — a simple flow's
     * plan/critique never needs the full budget, and on slow providers the
     * cap is also the worst-case latency.
     *
     * @param  array<string, mixed>  $intent
     */
    private function numPredictFor(array $intent, int $simple, int $medium, int $complex): int
    {
        return match ($intent['complexity'] ?? 'medium') {
            'simple' => $simple,
            'complex' => $complex,
            default => $medium,
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function runPhase(
        string $phase,
        string $system,
        string $user,
        array $schema,
        array $options,
        Flow $flow,
        ?string $logToken
    ): array {
        // „…“-quoted input primes the model to close quotes with a bare ASCII "
        // inside JSON string values, truncating them — guillemets are safe.
        $user = TypographicQuotes::normalize($user);

        $providerModel = $this->generator->providerModel($phase);

        $log = AgentGenerationLog::create([
            'flow_id' => $flow->id,
            'company_id' => $flow->company_id ?? $flow->company?->id,
            'token' => $logToken,
            'provider' => $providerModel['provider'].' ('.$phase.')',
            'model' => $providerModel['model'],
            'system_prompt' => $system,
            'user_message' => $user,
            'options' => $options,
            'status' => 'running',
        ]);
        $startMs = (int) (microtime(true) * 1000);

        // Planning normally owns the top-level frame, but an adaptive REVISION runs
        // nested inside a node's execution — restore the enclosing node frame rather
        // than clearing it, so the rest of that node's calls stay attributed.
        $prevCtx = LlmContext::get();
        $restoreCtx = static function () use ($prevCtx): void {
            $prevCtx === [] ? LlmContext::clear() : LlmContext::set($prevCtx);
        };

        LlmContext::set([
            'purpose' => 'planner:'.$phase,
            'session_id' => $logToken,
            'company_id' => $flow->company_id ?? $flow->company?->id,
            'flow_id' => $flow->id,
            // Билинг-атрибуция: наследяваме резервацията от обгръщащата рамка (org
            // генерация), за да влязат planner редовете под нея (§0.5.6).
            'context_type' => $prevCtx['context_type'] ?? null,
            'subject_type' => $prevCtx['subject_type'] ?? null,
            'subject_id' => $prevCtx['subject_id'] ?? null,
            'reservation_id' => $prevCtx['reservation_id'] ?? null,
            'operation_id' => $prevCtx['operation_id'] ?? null,
        ]);

        try {
            $result = $this->generator->chatJson($system, $user, $phase, $schema, $options);
        } catch (Throwable $e) {
            $restoreCtx();
            $log->update(array_merge(LlmUsage::take(), [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'duration_ms' => (int) (microtime(true) * 1000) - $startMs,
            ]));

            throw $e;
        }

        $restoreCtx();

        $parsed = $result['agents'] ?? $result['revised_agents'] ?? $result;

        $log->update(array_merge(LlmUsage::take(), [
            'raw_response' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'parsed_count' => is_array($parsed) ? count($parsed) : null,
            'duration_ms' => (int) (microtime(true) * 1000) - $startMs,
            'status' => 'completed',
        ]));

        return $result;
    }
}
