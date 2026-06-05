<?php

namespace App\Services;

use App\Models\AgentGenerationLog;
use App\Models\AgentTemplate;
use App\Models\Flow;
use App\Models\LlmModel;
use App\Support\UrlExtractor;
use Illuminate\Support\Facades\Log;
use Throwable;

class AgentGeneratorService
{
    private const BG_TEXT_CORRECTOR_TYPE = 'bg_text_corrector';
    private const QA_VERIFIER_TYPE = 'qa_verifier';
    private const DEFAULT_QA_THRESHOLD = 60;

    public function __construct(
        private GeneratorService $generator,
        private ModelSelectorService $modelSelector,
    ) {}

    public function generate(Flow $flow, ?callable $onProgress = null, ?string $logToken = null): array
    {
        $company       = $flow->company;
        $modelsContext = $this->buildModelsContext();
        $targetUrl     = UrlExtractor::first($flow->description ?? '');

        // ── Website-analysis flows: deterministic branched skeleton ──────────
        // Small local models are unreliable at producing a correct branched DAG
        // (wrong types, duplicates, missing the real crawler). When the flow
        // targets a concrete URL we build the pipeline structurally in code and
        // only fill in hand-written Bulgarian prompts — no LLM guesswork about
        // types or dependencies. This is the root-cause fix for flow 18.
        if (! empty($targetUrl)) {
            return $this->generateSiteAnalysisPipeline($flow, $targetUrl, $logToken, $onProgress);
        }

        // For a website-analysis flow the subject is the scraped site — NOT the
        // company record, which is unrelated and would pollute the agents. So we
        // present the URL as the only subject and forbid company placeholders.
        if ($targetUrl) {
            $subjectBlock = <<<SUBJECT
ОБЕКТ НА АНАЛИЗ: уебсайтът {$targetUrl}
ВАЖНО: Цялата информация за бизнеса (име, услуги, цени, контакти) идва от scrape-ване на ТОЗИ сайт, НЕ от данни за компания в системата. ИГНОРИРАЙ всякакви данни за компанията.
SUBJECT;

            $urlDirective = <<<DIRECTIVE
ИНСТРУКЦИИ ЗА АНАЛИЗ НА САЙТ ({$targetUrl}):
- ПЪРВИЯТ агент е deep_researcher и обхожда сайта чрез placeholder {{url}} (НЕ trend_researcher, НЕ scraper).
- ВСЕКИ СЛЕДВАЩ агент работи с изхода на предходния агент чрез placeholder {{input}} — това са реалните данни, събрани от сайта.
- ЗАБРАНЕНО: НЕ използвай {{company_description}}, НЕ изписвай името на компанията и НЕ реферирай статични данни за фирмата в нито един system_prompt или prompt_template. Идентичността на бизнеса (име, услуги, цени) идва САМО от scrape-натите данни.


DIRECTIVE;
        } else {
            $subjectBlock = <<<SUBJECT
Компания: {$company->name}
Индустрия: {$company->industry}
Описание на компанията: {$company->description}
SUBJECT;
            $urlDirective = '';
        }

        $systemPrompt = <<<PROMPT
Ти си AI архитект на маркетингови и бизнес автоматизации.
Твоята задача: проектирай ПЪЛЕН, готов за продукция multi-agent pipeline.

СТРОГИ ПРАВИЛА:
1. Върни САМО валиден JSON масив — без markdown, без обяснения, без допълнителен текст
2. МИНИМУМ 5 агента, идеално 6-8 в зависимост от сложността
3. Всеки агент има ТОЧНО ЕДНА отговорност
4. system_prompt трябва да е детайлен (минимум 3 изречения) — на български
5. prompt_template трябва да е детайлен (минимум 5 изречения) — на български, с конкретни placeholder-и: {{input}} (изход на предходния агент), {{topic}}; {{url}} и {{input}} когато задачата е за конкретен уебсайт; {{company_description}} САМО когато flow-ът НЕ анализира конкретен сайт
6. ВИНАГИ включвай: поне един researcher/analyzer, поне един content агент, точно един bg_text_corrector предпоследен и точно един qa_verifier агент (може на всяка позиция)
7. Избирай модели според задачата — виж списъка с модели
8. Агентите ТРЯБВА да отговарят на описанието на flow-а — не измисляй задачи, които не са поискани. НЕ включвай competitor_profiler освен ако описанието директно споменава конкуренти.
9. ГРАФ НА ЗАВИСИМОСТИТЕ: всеки агент има уникален "uid" и "depends_on" (масив от uid на агентите, чийто изход му е вход). Първите събиращи агенти имат depends_on=[]. Където е логично, използвай РАЗКЛОНЕНИЯ: няколко независими агента могат да събират различни данни паралелно (всеки с depends_on=[]), а следващ анализатор зависи от ВСИЧКИТЕ тях (depends_on с няколко uid → fan-in). НЕ прави цикли. depends_on реферира САМО съществуващи uid от този масив.

Генерирането на по-малко от 5 агента е ЗАБРАНЕНО.
PROMPT;

        $userMessage = <<<MSG
{$subjectBlock}

Flow за изграждане: "{$flow->description}"

{$urlDirective}НАЛИЧНИ МОДЕЛИ (избери внимателно за всеки агент):
{$modelsContext}

{$this->buildTypesContext()}

ПРАВИЛА ЗА ПРОЕКТИРАНЕ НА PIPELINE:
⚠️ КРИТИЧНО: Избери шаблона САМО ако описанието на flow-а директно съвпада. НЕ добавяй агенти за конкуренти, освен ако думата "конкурент/и" или "competitor" изрично се появява в описанието на flow-а.

- За анализ на единична фирма/бизнес БЕЗ конкретен сайт (одит, профил, доклад по име на компания):
  researcher → review_analyzer → analyzer → report_writer → bg_text_corrector → qa_verifier
- За social media flows: trend_researcher → hook_writer → content_bg → hashtag_generator → caption_writer → bg_text_corrector → qa_verifier
- За competitive intelligence (САМО когато описанието споменава "конкуренти" или "competitor"): competitor_profiler → swot_builder → report_writer → bg_text_corrector → qa_verifier
- За SEO flows: keyword_extractor → seo_writer → meta_generator → bg_text_corrector → qa_verifier
- За review monitoring: review_analyzer → sentiment_analyzer → report_writer → bg_text_corrector → qa_verifier
- За outreach/email flows: analyzer → email_composer → bg_text_corrector → qa_verifier
- АКО flow-ът изисква актуални новини/web данни: изследовател ЗАДЪЛЖИТЕЛНО е на позиция 1 (order: 1)
- За обхождане/анализ на КОНКРЕТЕН посочен URL използвай deep_researcher на позиция 1 (той реално scrape-ва страниците). trend_researcher търси вирусни теми за идеи за контент — НЕ го използвай за анализ на конкретен сайт или бизнес доклад.
- За български текст: винаги използвай todorov/bggpt за генериране и bg_text_corrector за финална езикова корекция
- За QA/верификация: използвай phi3.5 или phi3:mini (бързи, ефективни)
- За JSON/структуриран изход, image промпти, анализ: използвай mistral-nemo
- За webhook_sender и slack_notifier: ЗАДЪЛЖИТЕЛНО включи config.webhook_url в agent config

Върни JSON масив, където всеки обект има ТОЧНО тези полета:
{
  "name": "Описателно българско име, СЪОТВЕТСТВАЩО на задачата (напр. 'Изследовател на сайта', 'Автор на доклад')",
  "type": "един от типовете изброени по-горе",
  "role": "2-3 изречения на БЪЛГАРСКИ описващи: какво прави агентът, какъв вход получава и какъв изход произвежда",
  "capabilities": ["масив", "от", "способности"],
  "strengths": "в какво е силен агентът — на български",
  "limitations": "какво не може да прави — на български",
  "input_description": "описание на входа — на български",
  "output_description": "описание на изхода — на български",
  "system_prompt": "System prompt на БЪЛГАРСКИ. Минимум 3 изречения. Описва ролята, стила, езика и ограниченията на агента.",
  "prompt_template": "Промпт шаблон на БЪЛГАРСКИ. Минимум 5 изречения. Включи конкретни инструкции за формат, тон, дължина, какво да се включи/изключи. Използвай placeholder-и {{company_description}}, {{input}}, {{topic}} и {{url}} (за конкретен сайт) където е подходящо.",
  "model": "точен ollama tag от списъка по-горе",
  "model_reason": "защо е избран този модел — на български",
  "order": 1,
  "is_verifier": false,
  "qa_threshold": null,
  "uid": "kratak_stabilen_id (напр. 'researcher_site', 'analyzer_main') — уникален за всеки агент",
  "depends_on": ["масив от uid на агентите, чийто ИЗХОД този агент ползва като вход — празен масив за първите агенти"],
  "config": {
    "temperature": 0.7,
    "num_predict": null,
    "qa": {
      "enabled": false,
      "verifier_agent_uid": "qa_main",
      "threshold": 60,
      "max_retries": 3,
      "custom_prompt": "Конкретна проверка на БЪЛГАРСКИ специфична за задачата на агента — 2-3 изречения"
    }
  }
}

За bg_text_corrector: поправя САМО правописа. Агентът автоматично намира правилното съдържание — prompt_template е само кратка инструкция без placeholder. output_role=body, temperature=0.2, не добавя нови факти, връща само коригирания текст без обяснения.
За qa_verifier: is_verifier=true, qa_threshold=60, temperature=0.1, uid="qa_main", config НЕ включва qa поле
За всеки НЕ-verifier агент: config ТРЯБВА да включва qa обект с enabled=false, verifier_agent_uid="qa_main", threshold=60, max_retries=3
За custom_prompt в config.qa — пиши конкретна проверка подходяща за изхода на агента:
  - researcher/deep_researcher: "Провери дали са събрани конкретни данни за исканата тема с поне 3 отделни факта/резултата. Данните трябва да са релевантни на flow описанието и добре структурирани."
  - competitor_profiler (САМО ако е включен): "Провери дали са намерени поне 3 конкурента с имена и уебсайтове. Ако липсват цени — допустимо е, но трябва да е отбелязано. Структурата трябва да е ясна."
  - analyzer: "Провери дали изходът съдържа структурирани данни, цитирани източници и ключови открития. Данните трябва да са организирани и лесно четими."
  - content_bg/hook_writer/caption_writer/bg_text_corrector: "Провери дали съдържанието е на БЪЛГАРСКИ, има ясен призив за действие (CTA), подходящ тон и структура за платформата. Проверка за правопис и стил."
  - report_composer/report_writer: "Провери дали докладът съдържа всички необходими секции, изводи и препоръки. Езикът трябва да е БЪЛГАРСКИ, professional тон."
  - За всички останали: напиши проверка базирана на output_description на агента — какво конкретно трябва да присъства в изхода
За image_prompt агенти: temperature=0.8, num_predict се задава автоматично от системата
За researcher/analyzer: temperature=0.3
MSG;

        $options = ['temperature' => 0.2, 'num_predict' => 4000];
        $providerModel = $this->generator->providerModel();

        Log::info('[AgentGenerator] Using provider: ' . $providerModel['provider'] . ' (' . $providerModel['model'] . ')');
        Log::info('[AgentGenerator] Flow: ' . $flow->description);

        // Persist a full, untruncated record of the generation request so it can be
        // reviewed later in the builder's "Лог на генерирането" panel.
        $genLog = AgentGenerationLog::create([
            'flow_id'       => $flow->id,
            'company_id'    => $flow->company_id ?? $flow->company?->id,
            'token'         => $logToken,
            'provider'      => $providerModel['provider'],
            'model'         => $providerModel['model'],
            'system_prompt' => $systemPrompt,
            'user_message'  => $userMessage,
            'options'       => $options,
            'status'        => 'running',
        ]);
        $startMs = (int) (microtime(true) * 1000);

        if ($onProgress) {
            $onProgress('Генериране на агенти');
        }

        try {
            $raw = $this->generator->chat(
                systemPrompt: $systemPrompt,
                userMessage: $userMessage,
                options: $options,
                onProgress: $onProgress
            );
        } catch (Throwable $e) {
            $genLog->update([
                'status'      => 'failed',
                'error'       => $e->getMessage(),
                'duration_ms' => (int) (microtime(true) * 1000) - $startMs,
            ]);
            throw $e;
        }

        Log::info('[AgentGenerator] Raw response length: ' . strlen($raw));

        if ($onProgress) {
            $onProgress('Обработка на резултата');
        }

        $agents = $this->parseAgentJson($raw);

        Log::info('[AgentGenerator] Parsed ' . count($agents) . ' agents');

        $genLog->update([
            'raw_response' => $raw,
            'parsed_count' => count($agents),
            'duration_ms'  => (int) (microtime(true) * 1000) - $startMs,
            'status'       => count($agents) < 3 ? 'failed' : 'completed',
            'error'        => count($agents) < 3 ? 'AI върна по-малко от 3 агента.' : null,
        ]);

        // Safety net: if AI returned fewer than 3, something went wrong
        if (count($agents) < 3) {
            Log::warning('[AgentGenerator] Too few agents (' . count($agents) . '), returning empty to trigger retry');
            return [];
        }

        if ($onProgress) {
            $onProgress('Проверка за web research');
        }

        if ($this->needsWebResearch($flow->description ?? '')) {
            $agents = $this->ensureResearcherFirst($agents);
        }

        if ($onProgress) {
            $onProgress('Финализиране на pipeline-а');
        }

        $agents = $this->ensureQaVerifierLast($agents);
        $agents = $this->ensureBgTextCorrectorBeforeQa($agents);
        $agents = $this->dedupeAgents($agents);
        $agents = $this->finalizeDependencyGraph($agents);

        return $agents;
    }

    /**
     * Build a website-analysis pipeline deterministically (no LLM structure
     * guesswork) and run it through the same normalize + graph-finalize path the
     * LLM output uses. The result is the user's approved branched architecture:
     *
     *   Базов контекст ─┬─► Изследовател ─► Анализатор ─┐
     *                   └─► Ревюта      ─► Анализатор ─┴─► Доклад ─► Коректор ─► QA
     */
    private function generateSiteAnalysisPipeline(Flow $flow, string $targetUrl, ?string $logToken, ?callable $onProgress): array
    {
        if ($onProgress) {
            $onProgress('Изграждане на pipeline за анализ на сайт');
        }

        $skeleton = $this->buildSiteAnalysisSkeleton($targetUrl);

        $agents = array_values(array_filter(array_map(
            fn ($a, $i) => $this->normalizeAgent($a, $i + 1),
            $skeleton,
            array_keys($skeleton),
        )));
        $agents = $this->finalizeDependencyGraph($agents);

        // Audit trail in the builder's "Лог на генерирането" panel — deterministic
        // builds have no raw LLM response, so we record the resolved skeleton.
        AgentGenerationLog::create([
            'flow_id'       => $flow->id,
            'company_id'    => $flow->company_id ?? $flow->company?->id,
            'token'         => $logToken,
            'provider'      => 'deterministic',
            'model'         => 'site-analysis-skeleton',
            'system_prompt' => 'Детерминистичен разклонен скелет за анализ на сайт (без LLM за структурата).',
            'user_message'  => "Целеви сайт: {$targetUrl}\nFlow: ".($flow->description ?? ''),
            'options'       => [],
            'raw_response'  => json_encode($agents, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'parsed_count'  => count($agents),
            'duration_ms'   => 0,
            'status'        => 'completed',
        ]);

        Log::info('[AgentGenerator] Built deterministic site-analysis pipeline ('.count($agents).' agents) for '.$targetUrl);

        return $agents;
    }

    /**
     * Raw agent dicts for the branched site-analysis pipeline. Types, uids and
     * depends_on are fixed in code; normalizeAgent() fills model + config defaults.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildSiteAnalysisSkeleton(string $targetUrl): array
    {
        $qa = fn (string $check): array => [
            'temperature' => 0.3,
            'qa' => [
                'enabled' => false,
                'verifier_agent_uid' => 'qa_main',
                'threshold' => self::DEFAULT_QA_THRESHOLD,
                'max_retries' => 3,
                'custom_prompt' => $check,
            ],
        ];

        return [
            [
                'name' => 'Базов контекст за бизнеса',
                'type' => 'site_context',
                'uid' => 'site_context',
                'depends_on' => [],
                'order' => 1,
                'role' => 'Скрейпва началната страница на сайта и изгражда компактна идентичност на бизнеса (име, дейност, основни услуги, контакти). Това е споделеният базов контекст за двата следващи клона.',
                'system_prompt' => 'Ти изграждаш базов профил на бизнес от началната му страница. Извличаш САМО реално присъстващи факти: име на бизнеса, с какво се занимава, основни направления услуги/продукти, контакти и локация, език. Не измисляш нищо. Пишеш кратко и структурирано на български.',
                'prompt_template' => "Изгради базов профил на бизнеса от сайта {{url}}. Използвай съдържанието на началната страница, предоставено по-долу. Върни кратък структуриран профил на български: име, дейност, основни услуги, контакти, локация и език. Без измислици — пропусни каквото липсва.",
                'config' => $qa('Провери дали профилът съдържа реално име на бизнеса и поне 2-3 направления услуги/дейност, извлечени от началната страница.'),
            ],
            [
                'name' => 'Изследовател на сайта',
                'type' => 'deep_researcher',
                'uid' => 'site_explorer',
                'depends_on' => ['site_context'],
                'order' => 2,
                'role' => 'Обхожда ВСИЧКИ страници на сайта, разбира съдържанието на всяка и извлича структурирано услуги с цени, контакти и факти за бизнеса. Прогресът се показва страница по страница.',
                'system_prompt' => 'Ти си изследовател, който обхожда цял уебсайт. За всяка страница разбираш какво представлява съдържанието и извличаш САМО реалните данни — услуги и техните цени, описания, контакти, локации. Не търсиш фиксирани думи и не измисляш цени. Обединяваш всичко в изчерпателна, структурирана база знания на български, със консолидирана таблица на услугите и цените.',
                'prompt_template' => "Обходи целия сайт {{url}} и извлечи изчерпателна информация за бизнеса: всички услуги/продукти с техните цени (както от ценова страница, така и от отделните страници на услуги), контакти, локации и ключови твърдения. Базовият контекст за бизнеса е: {{input}}. Върни структурирана база знания на български с консолидирана таблица услуга → цена и всички намерени контакти.",
                'config' => array_merge($qa('Провери дали са обходени множество страници и са извлечени конкретни услуги с цени и контакти. Трябва да има реални числови цени, ако сайтът ги публикува.'), [
                    'temperature' => 0.3,
                    'map_reduce' => true,
                    'max_pages_to_scrape' => 200,
                    'map_concurrency' => 4,
                    // Генерозни caps, за да се хванат ВСИЧКИ услуги/цени от плътни
                    // ценоразписи (напр. 39 услуги на една WooCommerce страница).
                    'max_page_chars' => 30000,
                    'page_summary_tokens' => 1500,
                    'num_ctx' => 16384,
                ]),
            ],
            [
                'name' => 'Анализатор на ревюта',
                'type' => 'review_analyzer',
                'uid' => 'review_finder',
                'depends_on' => ['site_context'],
                'order' => 3,
                'role' => 'Намира реални ревюта за бизнеса: вградените на самия сайт (Google/Facebook widget-и, testimonials), плюс външни източници (Google Maps, Facebook). Анализира тон и повтарящи се теми.',
                'system_prompt' => 'Ти откриваш и анализираш реални клиентски ревюта за конкретен бизнес. Разпознаваш ревюта вградени в съдържанието на сайта, както и от външни източници. Никога не измисляш ревюта, оценки или цитати. Ако не са намерени реални ревюта, го казваш ясно. Пишеш на български.',
                'prompt_template' => "Намери и анализирай реалните ревюта за бизнеса от сайта {{url}}. Базов контекст за бизнеса (име, дейност): {{input}}. Потърси вградени ревюта/testimonials на самия сайт и външни ревюта (Google Maps, Facebook). Обобщи: общ тон, повтарящи се похвали и оплаквания, средна оценка и конкретни цитати с източник. Ако няма реални ревюта — кажи го ясно.",
                'config' => $qa('Провери дали изходът се базира на реални намерени ревюта (с източник) или ясно заявява, че такива не са намерени. Без измислени оценки.'),
            ],
            [
                'name' => 'Анализатор на съдържанието',
                'type' => 'analyzer',
                'uid' => 'content_analyzer',
                // Also depends on site_context so it keeps the business identity, not
                // just the raw crawl — nothing from the early agents gets lost.
                'depends_on' => ['site_explorer', 'site_context'],
                'order' => 4,
                'role' => 'Анализира събраната от сайта информация: услуги, ценова структура, силни страни, позициониране и възможности.',
                'system_prompt' => 'Ти анализираш събраните данни за бизнеса от неговия сайт. Идентифицираш ключови услуги, ценова структура, силни и слаби страни, позициониране и възможности за подобрение. Запазваш всички конкретни данни (особено цени) и ги структурираш ясно. Пишеш на български.',
                'prompt_template' => "Анализирай задълбочено събраната информация за сайта: {{input}}. Извлечи и структурирай: пълен списък услуги с цени, ценова структура, силни страни, позициониране и конкретни възможности за подобрение. Запази ВСИЧКИ числови цени, контакти и адрес ДОСЛОВНО — не съкращавай ценовия списък. Изход на български.",
                // Голям контекст: тук се чете цялата база знания (60+ страници) и се
                // консолидира — без достатъчен num_ctx входът се отрязва и данни се губят.
                'config' => array_merge($qa('Провери дали анализът съдържа структурирани услуги с цени, силни страни и конкретни изводи, базирани на входните данни.'), [
                    'num_ctx' => 16384,
                ]),
            ],
            [
                'name' => 'Анализатор на ревютата',
                'type' => 'sentiment_analyzer',
                'uid' => 'review_sentiment',
                'depends_on' => ['review_finder', 'site_context'],
                'order' => 5,
                'role' => 'Структурира резултата от ревютата: общ sentiment, средна оценка, повтарящи се теми и препоръки на база обратната връзка.',
                'system_prompt' => 'Ти структурираш анализа на клиентски ревюта в ясни изводи: общ тон, средна оценка, повтарящи се похвали и оплаквания, препоръки. Базираш се само на предоставените реални ревюта. Ако ревюта липсват, го отбелязваш. Пишеш на български.',
                'prompt_template' => "Структурирай анализа на ревютата: {{input}}. Дай: общ sentiment, средна оценка (ако е налична), топ повтарящи се похвали и оплаквания, и конкретни препоръки за бизнеса на база обратната връзка. Без измислици. Изход на български.",
                'config' => $qa('Провери дали изводите за ревютата се базират на реалните входни данни и ясно отбелязват липсата на ревюта, ако такива няма.'),
            ],
            [
                'name' => 'Автор на доклад',
                'type' => 'report_writer',
                'uid' => 'report_author',
                // Fan-in от 5: двата анализа (структурата на доклада) ПЛЮС суровите
                // данни от изследователя и ревютата (за точни цени/контакти/цитати).
                // Така съдържанието от ранните агенти достига доклада без загуба от
                // двойното кондензиране през анализаторите.
                'depends_on' => ['content_analyzer', 'review_sentiment', 'site_explorer', 'review_finder', 'site_context'],
                'order' => 6,
                'role' => 'Сглобява изчерпателен финален доклад от анализа на съдържанието и анализа на ревютата — с executive summary, пълна таблица услуги/цени, контакти, секция ревюта и препоръки.',
                'system_prompt' => 'Ти пишеш изчерпателен бизнес доклад на български. Получаваш няколко входа: двата анализа (на съдържанието и на ревютата) — те са структурният гръбнак на доклада; и суровите събрани данни (обхождане на сайта + ревюта) — от тях вадиш точните цени, контакти и цитати. Включваш ВСИЧКИ конкретни данни — пълна таблица на услугите с цени, контакти, локации — и не съкращаваш фактологията. Структурата е ясна с раздели. За голям сайт докладът е подробен и дълъг. Не измисляш данни.',
                'prompt_template' => "Напиши изчерпателен доклад за бизнеса на български на база ВСИЧКИ входове по-долу: {{input}}.\nИзползвай анализите като структура, а суровите данни (обхождане + ревюта) — за да възстановиш точните цени, контакти и цитати. Нищо конкретно да не се изпуска.\nЗадължителни раздели:\n1. Executive summary\n2. Профил на бизнеса и направления\n3. Пълна таблица на услугите с цени (всички намерени услуги — не съкращавай)\n4. Контакти и локации\n5. Анализ на ревютата (тон, оценка, теми, цитати с източник)\n6. Силни страни и възможности\n7. Конкретни препоръки\nЗапази всички числови цени и контакти от входовете. Докладът трябва да е подробен, съответстващ на обема на сайта.",
                'config' => array_merge($qa('Провери дали докладът съдържа всички задължителни раздели, пълна таблица услуги/цени с реални числа и секция за ревютата. Езикът е български.'), [
                    'temperature' => 0.5,
                    'num_ctx' => 8192,
                ]),
            ],
            [
                'name' => 'Български коректор',
                'type' => self::BG_TEXT_CORRECTOR_TYPE,
                'uid' => 'bg_corrector',
                'depends_on' => ['report_author'],
                'order' => 7,
                'role' => 'Коригира правописа и стила на финалния доклад без да променя смисъла, фактите, цените или таблиците.',
                'system_prompt' => 'Ти си коректор на правопис. Поправяш САМО правописни и граматични грешки в кирилица. Запазваш структурата, таблиците, цените, контактите и числата непроменени. Не пренаписваш и не добавяш информация. Връщаш само коригирания текст без обяснения.',
                'prompt_template' => 'Прегледай доклада и поправи САМО правописните и граматичните грешки. Запази таблиците, цените и контактите непроменени. Върни само коригирания текст.',
                'config' => ['temperature' => 0.2],
            ],
            [
                'name' => 'QA Верификатор',
                'type' => self::QA_VERIFIER_TYPE,
                'uid' => 'qa_main',
                'depends_on' => ['bg_corrector'],
                'order' => 8,
                'is_verifier' => true,
                'qa_threshold' => self::DEFAULT_QA_THRESHOLD,
                'role' => 'Оценява качеството на финалния доклад по скала 0-100: пълнота, наличие на цени/контакти/ревюта, яснота и език.',
                'system_prompt' => 'Ти си QA специалист. Оценяваш финалния доклад обективно по пълнота (услуги, цени, контакти, ревюта), яснота, форматиране и български език. Не пренаписваш — само оценяваш дали е готов за употреба.',
                'prompt_template' => 'Оцени качеството на финалния доклад по скала 0-100: {{input}}. Провери дали съдържа пълна таблица услуги/цени, контакти, секция ревюта и ясни препоръки на правилен български. Върни структурирана оценка с кратко обяснение и pass/fail според прага.',
                'config' => ['temperature' => 0.1],
            ],
        ];
    }

    /**
     * Guarantee a clean, acyclic dependency graph the builder can lay out:
     *  - every agent gets a unique, stable uid;
     *  - depends_on references are validated against existing uids (self-refs dropped);
     *  - auto-added agents without deps are chained to the previous non-verifier agent;
     *  - any cycle collapses the whole graph to a sequential chain by order.
     */
    private function finalizeDependencyGraph(array $agents): array
    {
        $usedUids = [];
        foreach ($agents as $i => &$agent) {
            $uid = trim((string) ($agent['uid'] ?? ''));
            if ($uid === '' || isset($usedUids[$uid])) {
                $base = preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($agent['type'] ?? 'agent'));
                $uid = trim($base, '_').'_'.($i + 1);
            }
            $usedUids[$uid] = true;
            $agent['uid'] = $uid;
            $agent['depends_on'] = $this->normalizeDependsOn($agent['depends_on'] ?? null);
        }
        unset($agent);

        $validUids = array_fill_keys(array_column($agents, 'uid'), true);
        $prevNonVerifierUid = null;

        foreach ($agents as &$agent) {
            // Keep only references to existing agents; never depend on self.
            $agent['depends_on'] = array_values(array_filter(
                $agent['depends_on'],
                fn ($u) => isset($validUids[$u]) && $u !== $agent['uid'],
            ));

            // Auto-added agents (no deps, not first) chain to the previous step.
            if (empty($agent['depends_on']) && $prevNonVerifierUid && ! ($agent['is_verifier'] ?? false)) {
                $isFirstResearcher = (int) ($agent['order'] ?? 0) <= 1;
                if (! $isFirstResearcher) {
                    $agent['depends_on'] = [$prevNonVerifierUid];
                }
            }

            if (! ($agent['is_verifier'] ?? false)) {
                $prevNonVerifierUid = $agent['uid'];
            }
        }
        unset($agent);

        if ($this->hasDependencyCycle($agents)) {
            Log::warning('[AgentGenerator] depends_on cycle detected — falling back to sequential chain');
            $prev = null;
            foreach ($agents as &$agent) {
                $agent['depends_on'] = ($prev && ! ($agent['is_verifier'] ?? false)) ? [$prev] : [];
                if (! ($agent['is_verifier'] ?? false)) {
                    $prev = $agent['uid'];
                }
            }
            unset($agent);
        }

        return $agents;
    }

    /** Kahn-style cycle detection over the uid dependency graph. */
    private function hasDependencyCycle(array $agents): bool
    {
        $inDegree = [];
        $adj = [];
        foreach ($agents as $a) {
            $inDegree[$a['uid']] = $inDegree[$a['uid']] ?? 0;
            foreach ($a['depends_on'] as $dep) {
                $adj[$dep][] = $a['uid'];
                $inDegree[$a['uid']]++;
            }
        }

        $queue = array_keys(array_filter($inDegree, fn ($d) => $d === 0));
        $resolved = 0;
        while ($queue) {
            $u = array_shift($queue);
            $resolved++;
            foreach ($adj[$u] ?? [] as $v) {
                if (--$inDegree[$v] === 0) {
                    $queue[] = $v;
                }
            }
        }

        return $resolved < count($inDegree);
    }

    private function buildModelsContext(): string
    {
        // List ONLY installed + enabled models. Showing uninstalled tags made the
        // LLM recommend models the user doesn't have, so they were never pulled and
        // the run fell back unpredictably. The model is anyway re-selected by code
        // (ModelSelectorService) — this list is just guidance.
        $models = LlmModel::where('is_available', true)
            ->where('is_enabled', true)
            ->orderBy('category')
            ->orderBy('display_name')
            ->get();

        if ($models->isEmpty()) {
            return $this->getDefaultModelsContext();
        }

        return $models->map(function ($m) {
            $bestFor = $m->description ? " — {$m->description}" : '';
            return "- {$m->ollama_tag}{$bestFor}";
        })->join("\n");
    }

    private function buildTypesContext(): string
    {
        $lines = ['НЕОБХОДИМИ ТИПОВЕ АГЕНТИ (използвай САМО "type" от този списък — ЗАБРАНЕНО е да измисляш нови типове):'];

        // Списъкът с налични типове идва от АКТИВНИТЕ системни шаблони — изключен
        // в админ панела шаблон не се предлага на LLM. Fallback към config-а само
        // ако няма нито един активен шаблон (празна/несидирана база), за да не се
        // счупи генерирането.
        $templates = AgentTemplate::whereNull('company_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['type', 'description']);

        if ($templates->isEmpty()) {
            foreach (config('agent_types') as $slug => $info) {
                $lines[] = "- {$slug} → {$info['description']}";
            }
        } else {
            foreach ($templates->unique('type') as $t) {
                $lines[] = "- {$t->type} → {$t->description}";
            }
        }

        $lines[] = 'НЕ дублирай един и същ агент. За финална езикова корекция използвай ЕДИНСТВЕН bg_text_corrector — не създавай допълнителни „коректори" или „опростители".';

        return implode("\n", $lines);
    }

    private function getDefaultModelsContext(): string
    {
        // Fallback list when the LlmModel catalogue is empty — installed defaults only.
        return implode("\n", [
            '- todorov/bggpt — Bulgarian language text generation',
            '- mistral-nemo — JSON output, structured content, image prompts, analysis',
            '- qwen2.5:14b — analysis, structured reasoning',
            '- phi3.5 — fast QA verification, simple tasks',
        ]);
    }

    private function parseAgentJson(string $raw): array
    {
        // Strip markdown code blocks if present
        $cleaned = preg_replace('/```(?:json)?\s*([\s\S]*?)```/i', '$1', $raw);
        $cleaned = trim($cleaned);

        // Remove trailing commas before } or ] (common LLM artifact)
        $cleaned = preg_replace('/,\s*([\}\]])/m', '$1', $cleaned);

        // Find outermost JSON array
        $start = strpos($cleaned, '[');
        $end   = strrpos($cleaned, ']');

        if ($start === false || $end === false) {
            Log::error('[AgentGenerator] No JSON array found in response');
            return [];
        }

        $json   = substr($cleaned, $start, $end - $start + 1);
        $agents = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('[AgentGenerator] JSON parse error: ' . json_last_error_msg());
            Log::error('[AgentGenerator] Attempted JSON: ' . substr($json, 0, 500));

            // Try to recover truncated JSON by finding the last complete object
            $agents = $this->recoverTruncatedJson($json);
        }

        if (!is_array($agents)) {
            return [];
        }

        // Normalise and fill in defaults for each agent
        return array_values(array_filter(array_map(
            fn($a, $i) => $this->normalizeAgent($a, $i + 1),
            $agents,
            array_keys($agents)
        )));
    }

    private function recoverTruncatedJson(string $json): array
    {
        // Find the last complete } before the truncation point
        $agents   = [];
        $depth    = 0;
        $inString = false;
        $objStart = null;
        $escape   = false;

        for ($i = 0; $i < strlen($json); $i++) {
            $ch = $json[$i];

            if ($escape) { $escape = false; continue; }
            if ($ch === '\\' && $inString) { $escape = true; continue; }
            if ($ch === '"') { $inString = !$inString; continue; }
            if ($inString) continue;

            if ($ch === '[' || $ch === '{') {
                if ($ch === '{' && $depth === 1) $objStart = $i;
                $depth++;
            } elseif ($ch === ']' || $ch === '}') {
                $depth--;
                if ($ch === '}' && $depth === 1 && $objStart !== null) {
                    $objJson = substr($json, $objStart, $i - $objStart + 1);
                    $obj     = json_decode($objJson, true);
                    if (is_array($obj) && isset($obj['name'])) {
                        $agents[] = $obj;
                    }
                    $objStart = null;
                }
            }
        }

        Log::info('[AgentGenerator] Recovered ' . count($agents) . ' agents from truncated JSON');
        return $agents;
    }

    private function normalizeAgent(mixed $agent, int $fallbackOrder): ?array
    {
        if (!is_array($agent) || empty($agent['name'])) {
            return null;
        }

        $type = $agent['type'] ?? 'content';

        // The model is chosen by code (by agent type + description), NOT by the
        // generating LLM — which previously slapped the Bulgarian text model on
        // every agent. The selector returns the ideal model; the executor pulls it
        // on demand if it is not installed yet.
        $modelHint = trim(($agent['name'] ?? '').' '.($agent['role'] ?? '').' '.($agent['output_description'] ?? ''));
        $model = $this->modelSelector->selectModel($type, $modelHint);

        // Defensive fallbacks: a partial LLM response must never produce a
        // FlowNode with empty prompts — the executor would then send empty
        // user/system messages to Ollama.
        $role = trim((string) ($agent['role'] ?? $agent['name']));
        $promptTemplate = trim((string) ($agent['prompt_template'] ?? ''));
        if ($promptTemplate === '') {
            $promptTemplate = $role !== ''
                ? $role
                : ('Извърши задачата на агент "'.$agent['name'].'" и върни резултата.');
        }
        $systemPrompt = trim((string) ($agent['system_prompt'] ?? ''));
        if ($systemPrompt === '') {
            $systemPrompt = 'Ти си агент "'.$agent['name'].'". '
                . ($role !== '' ? $role.' ' : '')
                . 'Отговаряй на български език.';
        }

        return [
            'name'               => $agent['name'],
            'type'               => $type,
            'role'               => $role !== '' ? $role : $agent['name'],
            'capabilities'       => (array) ($agent['capabilities'] ?? []),
            'strengths'          => $agent['strengths'] ?? null,
            'limitations'        => $agent['limitations'] ?? null,
            'input_description'  => $agent['input_description'] ?? null,
            'output_description' => $agent['output_description'] ?? null,
            'prompt_template'    => $promptTemplate,
            'system_prompt'      => $systemPrompt,
            'model'              => $model,
            'model_reason'       => 'Автоматично избран според типа на агента ('.$type.') и наличните модели.',
            'order'              => (int) ($agent['order'] ?? $fallbackOrder),
            // Force qa_verifier type to always be a verifier even if AI forgot the flag
            'is_verifier'        => ($agent['type'] ?? '') === self::QA_VERIFIER_TYPE
                ? true
                : (bool) ($agent['is_verifier'] ?? false),
            'qa_threshold'       => ($agent['type'] ?? '') === self::QA_VERIFIER_TYPE
                ? $this->generatedQaThresholdOrDefault($agent['qa_threshold'] ?? null)
                : (isset($agent['qa_threshold']) ? (int) $agent['qa_threshold'] : null),
            'config'             => $this->normalizeAgentConfig($agent['config'] ?? null, $type),
            'uid'                => $agent['uid'] ?? null,
            // Branching DAG: uids of agents whose output this agent consumes.
            // Empty => the builder falls back to a sequential chain by order.
            'depends_on'         => $this->normalizeDependsOn($agent['depends_on'] ?? null),
        ];
    }

    /**
     * Normalize the LLM-provided dependency list into a clean array of uid strings.
     * The builder resolves these into graph edges (with a cycle guard).
     */
    private function normalizeDependsOn(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($v) => is_string($v) ? trim($v) : (is_scalar($v) ? (string) $v : null),
            $raw,
        ), fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Merge the LLM-generated config with code-owned overrides.
     * num_predict is always set by code (typeToNumPredict) so the LLM cannot
     * accidentally truncate outputs.
     */
    private function normalizeAgentConfig(mixed $raw, string $type): array
    {
        $config = is_array($raw) ? $raw : ['temperature' => 0.7];
        // Code is the source of truth for num_predict — ignore whatever the LLM wrote.
        $config['num_predict'] = $this->numPredictForType($type);
        return $config;
    }

    /**
     * Returns the appropriate num_predict (max output tokens) for an agent type.
     * -1 means unlimited — the model stops on its own (correct for research/correction agents).
     * The LLM's suggested value is ignored; this is the code's source of truth.
     */
    private function numPredictForType(string $type): int
    {
        // Unlimited: research agents must output the full gathered data; correctors reproduce full text.
        $unlimited = [
            'deep_researcher', 'researcher', 'multi_researcher',
            'competitor_profiler', 'review_analyzer', 'keyword_extractor',
            'bg_text_corrector',
        ];

        // Long: reports, analyses, summaries that regularly exceed 2 000 tokens.
        $long = [
            'report_writer', 'report_composer', 'analyzer', 'swot_builder',
            'sentiment_analyzer', 'summarizer', 'data_extractor', 'email_sequence_writer',
            'press_release_writer', 'calendar_planner', 'ab_test_generator',
            'survey_builder', 'persona_builder', 'chatbot_responder',
            'podcast_outline', 'video_script_writer', 'story_writer',
            'crm_note_writer', 'offer_builder', 'product_describer',
        ];

        // Medium: posts, emails, captions — substantial but bounded.
        $medium = [
            'content_bg', 'content_en', 'writer', 'caption_writer', 'hook_writer',
            'ad_copywriter', 'seo_writer', 'email_composer', 'newsletter_writer',
            'review_responder', 'whatsapp_message_writer', 'translator',
            'telegram_bot_responder', 'publisher',
        ];

        // Tiny: QA only needs a score + short justification.
        $tiny = ['qa_verifier', 'verifier'];

        if (in_array($type, $unlimited, true)) {
            return -1;
        }
        if (in_array($type, $long, true)) {
            return 6000;
        }
        if (in_array($type, $medium, true)) {
            return 3000;
        }
        if (in_array($type, $tiny, true)) {
            return 500;
        }

        // Default for hashtag generators, image_prompt, utility types, etc.
        return 1000;
    }

    private function generatedQaThresholdOrDefault(mixed $threshold): int
    {
        if ($threshold === null || $threshold === '' || (int) $threshold === 0) {
            return self::DEFAULT_QA_THRESHOLD;
        }

        return min(100, max(1, (int) $threshold));
    }

    private function needsWebResearch(string $description): bool
    {
        $keywords = ['новини', 'актуални', 'онлайн', 'web', 'search', 'изследвай', 'сайтове', 'интернет', 'trends', 'scrape'];

        foreach ($keywords as $keyword) {
            if (mb_stripos($description, $keyword) !== false) {
                return true;
            }
        }

        $response = $this->generator->chat(
            systemPrompt: 'Answer only YES or NO. No other text.',
            userMessage: "Does this flow description require fetching real-time web data or current news?\n\n{$description}",
            options: ['temperature' => 0.0, 'num_predict' => 5]
        );

        return str_starts_with(strtoupper(trim($response)), 'YES');
    }

    private function ensureResearcherFirst(array $agents): array
    {
        // Priority order: real site crawlers come first. deep_researcher actually
        // scrapes pages; 'scraper' is intentionally excluded because it maps to
        // ContentAgent (no scrape tool). trend_researcher only hunts viral topics
        // and must never lead a site-audit pipeline, so it is last.
        $researcherTypes = ['deep_researcher', 'researcher', 'multi_researcher', 'competitor_profiler', 'review_analyzer', 'keyword_extractor', 'trend_researcher'];

        $researcherIndex = null;
        foreach ($researcherTypes as $type) {
            foreach ($agents as $i => $agent) {
                if (($agent['type'] ?? '') === $type) {
                    $researcherIndex = $i;
                    break 2;
                }
            }
        }

        if ($researcherIndex === null || $researcherIndex === 0) {
            return $agents;
        }

        $researcher = array_splice($agents, $researcherIndex, 1)[0];
        array_unshift($agents, $researcher);

        foreach ($agents as $i => &$agent) {
            $agent['order'] = $i + 1;
        }
        unset($agent);

        return $agents;
    }

    private function ensureQaVerifierLast(array $agents): array
    {
        [$agents, $qaAgent] = $this->pullFirstAgentByType($agents, self::QA_VERIFIER_TYPE);
        $agents[] = $qaAgent ?? $this->defaultQaVerifierAgent();

        return $this->renumberAgents($agents);
    }

    private function ensureBgTextCorrectorBeforeQa(array $agents): array
    {
        $agents = $this->ensureQaVerifierLast($agents);
        $qaAgent = array_pop($agents);

        [$agents, $corrector] = $this->pullFirstAgentByType($agents, self::BG_TEXT_CORRECTOR_TYPE);

        $agents[] = $corrector ?? $this->defaultBgTextCorrectorAgent();
        $agents[] = $qaAgent;

        return $this->renumberAgents($agents);
    }

    private function pullFirstAgentByType(array $agents, string $type): array
    {
        $pulled = null;

        foreach ($agents as $index => $agent) {
            if (($agent['type'] ?? '') === $type) {
                if ($pulled === null) {
                    $pulled = $agent;
                }

                unset($agents[$index]);
            }
        }

        return [array_values($agents), $pulled];
    }

    private function renumberAgents(array $agents): array
    {
        foreach ($agents as $i => &$agent) {
            $agent['order'] = $i + 1;
        }
        unset($agent);

        return array_values($agents);
    }

    /**
     * Second-line defence for LLM-generated (non-URL) pipelines:
     *  - invented types that don't exist in config/agent_types.php are remapped to
     *    a real body type (otherwise they silently fall through to a generic agent);
     *  - exact-duplicate agents (same type + same name) are collapsed — this is what
     *    produced the nonsensical "Коректиране и улеснение ×2 + Български коректор".
     */
    private function dedupeAgents(array $agents): array
    {
        $knownTypes = array_keys(config('agent_types', []));
        $seen       = [];
        $out        = [];

        foreach ($agents as $agent) {
            $type = $agent['type'] ?? 'content_bg';

            if (! in_array($type, $knownTypes, true)) {
                Log::warning('[AgentGenerator] Unknown agent type "'.$type.'" remapped to content_bg');
                $type          = 'content_bg';
                $agent['type'] = $type;
            }

            $sig = $type.'|'.mb_strtolower(trim((string) ($agent['name'] ?? '')));
            if (isset($seen[$sig])) {
                Log::warning('[AgentGenerator] Dropping duplicate agent "'.($agent['name'] ?? '').'" ('.$type.')');

                continue;
            }
            $seen[$sig] = true;
            $out[]      = $agent;
        }

        return $this->renumberAgents($out);
    }

    private function defaultBgTextCorrectorAgent(): array
    {
        return [
            'name' => 'Български коректор',
            'type' => self::BG_TEXT_CORRECTOR_TYPE,
            'role' => 'Преглежда финалния български текст непосредствено преди QA. Коригира правопис, лексика, граматика и естественост на изказа, без да променя смисъла, фактите или формата.',
            'capabilities' => ['правописна корекция', 'лексикална корекция', 'граматична редакция', 'стилова гладкост'],
            'strengths' => 'Открива неестествени или грешни български думи и ги заменя с правилни изрази, като запазва първоначалната идея.',
            'limitations' => 'Не добавя нови факти, не променя оферти, цени, имена, линкове, хаштагове или CTA, освен ако има очевидна правописна грешка.',
            'input_description' => 'Финалният body текст от предходния агент.',
            'output_description' => 'Същият текст, коригиран на естествен и правилен български език.',
            'prompt_template' => 'Прегледай текста и поправи САМО правописните грешки на кирилица. Върни само коригирания текст.',
            'system_prompt' => 'Ти си коректор на правопис. Поправяш САМО правописни грешки в кирилица. Запазваш структурата, хаштаговете, емоджитата, линковете и CTA непроменени. Не пренаписваш, не преструктурираш, не добавяш нова информация. Връщаш само коригирания текст без обяснения.',
            'model' => $this->modelSelector->selectModel(self::BG_TEXT_CORRECTOR_TYPE),
            'model_reason' => 'Избран е модел, оптимизиран за естествен български език и редакция на текст.',
            'order' => 1,
            'is_verifier' => false,
            'qa_threshold' => null,
            'config' => ['temperature' => 0.2, 'num_predict' => -1],
        ];
    }

    private function defaultQaVerifierAgent(): array
    {
        return [
            'name' => 'QA Верификатор',
            'type' => self::QA_VERIFIER_TYPE,
            'role' => 'Проверява качеството на финалния коригиран изход. Оценява дали текстът отговаря на задачата, тона, формата и минималния праг за качество.',
            'capabilities' => ['оценка на качество', 'проверка на изисквания', 'финална верификация'],
            'strengths' => 'Открива пропуски в задачата, проблеми с формата и ниско качество на финалния изход.',
            'limitations' => 'Не редактира текста директно, а само оценява дали е готов за използване.',
            'input_description' => 'Финалният коригиран текст от предходния агент.',
            'output_description' => 'QA оценка и резултат за преминаване според зададения праг.',
            'prompt_template' => 'Оцени качеството на следния финален текст по скала 0-100: {{input}}. Провери дали текстът изпълнява целта на flow-а, дали е ясен, полезен, правилно форматиран и без сериозни езикови проблеми. Върни структурирана оценка с кратко обяснение и pass/fail резултат според прага.',
            'system_prompt' => 'Ти си QA специалист за AI-generated съдържание. Проверяваш финалния изход обективно по качество, релевантност, яснота, формат и език. Не пренаписваш съдържанието, а оценяваш дали е готово за употреба.',
            'model' => $this->modelSelector->selectModel(self::QA_VERIFIER_TYPE),
            'model_reason' => 'Избран е лек и бърз модел за финална QA проверка.',
            'order' => 1,
            'is_verifier' => true,
            'qa_threshold' => self::DEFAULT_QA_THRESHOLD,
            'config' => ['temperature' => 0.1, 'num_predict' => 500],
        ];
    }
}
