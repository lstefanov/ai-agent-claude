<?php

namespace App\Services;

use App\Models\AgentGenerationLog;
use App\Models\AgentTemplate;
use App\Models\Flow;
use App\Models\LlmModel;
use App\Support\LlmContext;
use App\Support\LlmUsage;
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
        'web_search' => 'Търсене в интернет (Brave) — връща топ резултати със заглавие, URL и описание',
        'scrape_page' => 'Извлича пълното текстово съдържание на ЕДНА страница по URL',
        'crawl_site' => 'Обхожда цял сайт страница по страница и връща съдържанието им',
        'discover_urls' => 'Открива списъка от вътрешни URL адреси на даден сайт (без да ги скрейпва)',
        'google_reviews' => 'Намира Google ревюта и рейтинг за бизнес по име/локация (Google Places API)',
    ];

    /** The intent of the most recent plan() call — persisted on the flow for the plan library. */
    private ?array $lastIntent = null;

    public function __construct(
        private GeneratorService $generator,
        private ModelSelectorService $modelSelector,
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
    public function plan(Flow $flow, ?callable $onProgress = null, ?string $logToken = null): array
    {
        $intent = $this->analyzeIntent($flow, $onProgress, $logToken);
        $this->lastIntent = $intent;

        $plan = $this->designPipeline($flow, $intent, $onProgress, $logToken);
        $agents = is_array($plan['agents'] ?? null) ? $plan['agents'] : [];

        // A strong planner rarely under-produces; a single transient short plan
        // should not abort the whole run — retry the design phase once. The
        // retry must not repeat the request verbatim (a low-temperature model
        // fails identically), so it carries explicit corrective feedback.
        if (count($agents) < 3) {
            Log::warning('[FlowPlanner] Pipeline design returned '.count($agents).' agents — retrying once.');
            $plan = $this->designPipeline($flow, $intent, $onProgress, $logToken,
                'ВНИМАНИЕ: предишният опит върна само '.count($agents).' агента вместо пълен pipeline. '
                .'Върни ПЪЛНИЯ DAG с ВСИЧКИ агенти (обикновено 7+), без съкращаване и без да '
                .'прекъсваш текстовите полета по средата.');
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
            $this->materialize($agents, $intent),
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
Отговаряй на български в стойностите.
PROMPT;

        $user = "Описание на flow (това е ЕДИНСТВЕНИЯТ източник на задачата):\n\"{$flow->description}\"";

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
    private function designPipeline(Flow $flow, array $intent, ?callable $onProgress, ?string $logToken, ?string $retryFeedback = null): array
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
9. provider: "ollama" (локален, безплатен — за масовата работа) или платен "openai" /
   "anthropic" — САМО за най-критичните стъпки: сложен fan-in доклад/синтез или
   стриктен JSON. Максимум 2 платени агента общо в плана.
10. temperature: 0.1-0.3 за research/анализ/QA/корекция; 0.6-0.8 за креативно съдържание
    и image промптове.
11. output_size: short (списъци, хаштагове, QA) | medium (постове, имейли) |
    long (доклади, анализи) | unlimited (research дъмпове, пълни корекции на дълъг текст).
12. За research върху цял сайт ползвай deep_researcher (има crawl + map-reduce).
    За Google ревюта — review_analyzer. За тенденции/вирусни теми — trend_researcher.
    trend_researcher НИКОГА не води сайт-анализ pipeline.
13. qa_custom_prompt: конкретна проверка за изхода НА ТОЗИ агент (2-3 изречения, български).
14. rationale: 1 изречение ЗАЩО този агент съществува (връзка с key_tasks).

ПРИМЕРНИ ТОПОЛОГИИ (само структура; имената са ориентировъчни):
- Доклад за сайт: site_context → [deep_researcher, review_analyzer] (паралелно) →
  [analyzer, sentiment_analyzer] → report_writer (fan-in от двата клона + site_context)
  → bg_text_corrector → qa_verifier.
- Social post с картинка: researcher → trend_researcher → analyzer →
  [content_bg, hashtag_generator, image_prompt] (fan-out) → caption_writer (fan-in от трите,
  с {{node:...}} референции) → bg_text_corrector → qa_verifier.
PROMPT;

        $intentJson = json_encode($intent, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $user = "INTENT (структурираното разбиране на заданието):\n{$intentJson}\n\n"
            ."ОРИГИНАЛНО ОПИСАНИЕ НА FLOW:\n\"{$flow->description}\"\n\n"
            .$this->capabilityCatalog()
            // Фаза 2: most similar PROVEN plans from the library as worked examples.
            .$this->planLibrary->fewShotBlock($intent, (int) config('services.planner.few_shots', 2))
            ."\n\nПроектирай pipeline-а. Върни agents + plan_rationale (кратко обяснение на топологията).";

        if ($retryFeedback !== null) {
            $user .= "\n\n".$retryFeedback;
        }

        return $this->runPhase('pipeline_design', $system, $user, $this->planSchema(), [
            'temperature' => 0.3,
            'num_predict' => $this->numPredictFor($intent, simple: 8000, medium: 10000, complex: 12000),
        ], $flow, $logToken);
    }

    /** @return array<string, mixed> */
    private function planSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['agents', 'plan_rationale'],
            'properties' => [
                'plan_rationale' => ['type' => 'string'],
                'agents' => ['type' => 'array', 'items' => $this->agentSpecSchema()],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function agentSpecSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'uid', 'name', 'type', 'custom_tools', 'role', 'system_prompt',
                'prompt_template', 'depends_on', 'provider', 'temperature',
                'output_size', 'qa_custom_prompt', 'is_verifier', 'rationale',
            ],
            'properties' => [
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
                'provider' => ['type' => 'string', 'enum' => ['ollama', 'openai', 'anthropic', 'deepseek', 'xai', 'qwen']],
                'temperature' => ['type' => 'number'],
                'output_size' => ['type' => 'string', 'enum' => ['short', 'medium', 'long', 'unlimited']],
                'qa_custom_prompt' => ['type' => ['string', 'null']],
                'is_verifier' => ['type' => 'boolean'],
                'rationale' => ['type' => 'string'],
            ],
        ];
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
2. Зависимостите логични ли са? Има ли агент, който ползва данни, които никой preди него не събира?
3. Има ли излишни/дублиращи се агенти или агенти извън заданието?
4. Fan-in агентите реферират ли правилните входове ({{node:Име}} съвпада с реално name)?
5. Промптите конкретни ли са (формат, тон, какво се запазва дословно)?
6. Има ли точно един bg_text_corrector (предпоследен) и един qa_verifier (uid qa_main, последен)?
Ако планът е добър → approved=true, issues=[], revised_agents=[].
Ако има дефекти → approved=false, опиши ги в issues и върни ЦЕЛИЯ поправен план в revised_agents
(всички агенти, не само променените).
ЕЗИК: всички текстови полета в revised_agents (name, role, system_prompt,
prompt_template, qa_custom_prompt, rationale) са на БЪЛГАРСКИ. Ако заварен агент е с
английско name или role — поправи го на кратко българско (name 2–5 думи).
КАВИЧКИ: в текстовите стойности не пиши права двойна кавичка (") и не ползвай „…“ —
ако ти трябват кавички, пиши «…».
PROMPT;

        $user = "INTENT:\n".json_encode($intent, JSON_UNESCAPED_UNICODE)
            ."\n\nПЛАН:\n".json_encode($plan, JSON_UNESCAPED_UNICODE)
            ."\n\nКаталогът от типове и tools е същият като при проектирането:\n"
            .$this->capabilityCatalog();

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['approved', 'issues', 'revised_agents'],
            'properties' => [
                'approved' => ['type' => 'boolean'],
                'issues' => ['type' => 'array', 'items' => ['type' => 'string']],
                'revised_agents' => ['type' => 'array', 'items' => $this->agentSpecSchema()],
            ],
        ];

        try {
            $result = $this->runPhase('plan_critique', $system, $user, $schema, [
                'temperature' => 0.1,
                'num_predict' => $this->numPredictFor($intent, simple: 4000, medium: 6000, complex: 8000),
            ], $flow, $logToken);
        } catch (Throwable $e) {
            // Critique is an enhancement — never let it kill a valid plan.
            Log::warning('[FlowPlanner] Critique phase failed, keeping original plan: '.$e->getMessage());

            return $plan['agents'];
        }

        $revised = is_array($result['revised_agents'] ?? null) ? $result['revised_agents'] : [];

        if (! ($result['approved'] ?? true) && count($revised) >= 3) {
            Log::info('[FlowPlanner] Critique revised the plan: '.implode(' | ', $result['issues'] ?? []));

            return $revised;
        }

        return $plan['agents'];
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
    // Materialization — planner spec → legacy agent dicts (deterministic)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array<string, mixed>>  $agents
     * @return array<int, array<string, mixed>>
     */
    private function materialize(array $agents, array $intent): array
    {
        $maxPaid = max(0, (int) config('services.planner.max_paid_agents', 2));
        $paidBudget = $this->pickPaidAgents($agents, $maxPaid);

        $out = [];
        $verifierSeen = false;

        foreach ($agents as $i => $spec) {
            if (! is_array($spec) || empty($spec['name'])) {
                continue;
            }

            $type = trim((string) ($spec['type'] ?? 'content_bg'));
            $isVerifier = ($type === 'qa_verifier') || (bool) ($spec['is_verifier'] ?? false);
            $uid = trim((string) ($spec['uid'] ?? ''));

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
                $config['tools'] = array_values(array_intersect(
                    array_map('strval', (array) ($spec['custom_tools'] ?? [])),
                    array_keys(self::AVAILABLE_TOOLS),
                ));
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
                // Paid prefixes (openai/…, anthropic/…) are preserved by
                // normalizeAgent; '' lets the ModelSelector pick the best
                // installed Ollama model.
                'model' => $paidBudget[$uid !== '' ? $uid : $i]
                    ?? '',
                'model_reason' => trim((string) ($spec['rationale'] ?? '')),
                'order' => $i + 1,
                'is_verifier' => $isVerifier,
                'qa_threshold' => $isVerifier ? self::DEFAULT_QA_THRESHOLD : null,
                'config' => $config,
                'uid' => $uid !== '' ? $uid : null,
                'depends_on' => array_values(array_filter(array_map('strval', (array) ($spec['depends_on'] ?? [])))),
                'output_language' => $this->normalizeLanguage((string) ($intent['language'] ?? 'bg')),
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
     * Enforce the paid-agent budget: keep the N agents that asked for a paid
     * provider (openai/anthropic) with the most inbound dependencies (fan-in
     * synthesis steps benefit most), demote the rest to Ollama. Providers
     * without an API key are demoted regardless.
     *
     * @param  array<int, array<string, mixed>>  $agents
     * @return array<int|string, string> uid (or index fallback) → pinned "provider/model"
     */
    private function pickPaidAgents(array $agents, int $max): array
    {
        if ($max === 0) {
            return [];
        }

        $candidates = [];
        foreach ($agents as $i => $spec) {
            $provider = (string) ($spec['provider'] ?? 'ollama');

            if (array_key_exists($provider, PaidModel::PREFIXES)
                && PaidModel::available($provider)
                && ! ($spec['is_verifier'] ?? false)) {
                $key = trim((string) ($spec['uid'] ?? '')) ?: $i;
                $candidates[] = [
                    'key' => $key,
                    'provider' => $provider,
                    'fan_in' => count((array) ($spec['depends_on'] ?? [])),
                ];
            }
        }

        usort($candidates, fn ($a, $b) => $b['fan_in'] <=> $a['fan_in']);

        $budget = [];
        foreach (array_slice($candidates, 0, $max) as $c) {
            $budget[$c['key']] = PaidModel::pin($c['provider']);
        }

        return $budget;
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

    private function capabilityCatalog(): string
    {
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
            $lines[] = "- {$name} → {$desc}";
        }

        $models = LlmModel::where('is_available', true)
            ->where('is_enabled', true)
            ->orderBy('category')
            ->limit(20)
            ->pluck('ollama_tag')
            ->all();

        $paidOptions = ['openai агентите ползват '.config('services.openai.runtime_model', 'gpt-4o-mini')];
        if (PaidModel::available('anthropic')) {
            $paidOptions[] = 'anthropic агентите ползват '.config('services.anthropic.runtime_model', 'claude-haiku-4-5');
        }
        if (PaidModel::available('deepseek')) {
            $paidOptions[] = 'deepseek агентите ползват '.config('services.deepseek.runtime_model', 'deepseek-v4-flash');
        }
        if (PaidModel::available('xai')) {
            $paidOptions[] = 'xai агентите ползват '.config('services.xai.runtime_model', 'grok-4.1-fast');
        }
        if (PaidModel::available('qwen')) {
            $paidOptions[] = 'qwen агентите ползват '.config('services.qwen.runtime_model', 'qwen3.5-flash');
        }

        $lines[] = '';
        $lines[] = 'ИЗПЪЛНЕНИЕ: ollama агентите получават модел автоматично от кода (инсталирани: '
            .(empty($models) ? 'стандартен набор' : implode(', ', $models)).'). '
            .implode('; ', $paidOptions).'.';

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

        LlmContext::set([
            'purpose' => 'planner:'.$phase,
            'session_id' => $logToken,
            'company_id' => $flow->company_id ?? $flow->company?->id,
            'flow_id' => $flow->id,
        ]);

        try {
            $result = $this->generator->chatJson($system, $user, $phase, $schema, $options);
        } catch (Throwable $e) {
            LlmContext::clear();
            $log->update(array_merge(LlmUsage::take(), [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'duration_ms' => (int) (microtime(true) * 1000) - $startMs,
            ]));

            throw $e;
        }

        LlmContext::clear();

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
