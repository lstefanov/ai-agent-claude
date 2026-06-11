<?php

namespace App\Services;

use App\Models\AgentTemplate;
use App\Models\AssistantMessage;
use App\Models\AssistantNote;
use App\Models\Flow;
use App\Models\LlmModel;
use App\Support\GraphTopology;
use App\Support\LlmContext;
use App\Support\LlmUsage;
use App\Support\PaidModel;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builder Copilot — the chat assistant inside the flow builder.
 *
 * One turn = an agentic tool-calling loop over the builder's CURRENT graph
 * (the unsaved Drawflow export travels with the request): read tools answer
 * from the working copy + DB, write tools mutate ONLY the working copy and
 * accumulate ops[] that the client applies on the canvas. Saving the graph
 * stays the approval step — the copilot never writes flow_nodes directly.
 *
 * The LLM proposes, code guarantees: every mutation is validated here
 * (existing keys, known types, available models, cycle-free topology).
 */
class BuilderAssistantService
{
    private const MAX_STEPS_DEFAULT = 8;

    /** Working copy: node_key => node array (GraphNormalizer::parse shape). */
    private array $nodes = [];

    /** Working copy: list of ['from_node_key' => ..., 'to_node_key' => ...]. */
    private array $edges = [];

    /** Graph operations for the client (applied on the Drawflow canvas). */
    private array $ops = [];

    /** UI actions for the client (open_node, ...). */
    private array $ui = [];

    private int $newNodeCounter = 0;

    public function __construct(
        private GeneratorService $generator,
        private FlowPlannerService $planner,
        private GraphNormalizer $normalizer,
    ) {}

    /**
     * The provider+model the copilot would use. Falls back to the planner's
     * provider when it is a cloud one (ollama lacks dependable tool calling).
     *
     * @return array{provider: string, model: string}
     */
    public function providerModel(): array
    {
        $provider = (string) config('services.builder_assistant.provider', '');

        if ($provider === '' || $provider === 'ollama' || ! in_array($provider, GeneratorService::PROVIDERS, true)) {
            $generatorProvider = (string) config('services.generator.provider', 'openai');
            $provider = $generatorProvider !== 'ollama' && in_array($generatorProvider, GeneratorService::PROVIDERS, true)
                ? $generatorProvider
                : 'openai';
        }

        $model = (string) config('services.builder_assistant.model', '')
            ?: (string) config("services.{$provider}.model", '');

        return ['provider' => $provider, 'model' => $model];
    }

    public function isAvailable(): bool
    {
        return ! empty(config('services.'.$this->providerModel()['provider'].'.api_key'));
    }

    /**
     * Run one assistant turn: agentic loop until the model answers without
     * tool calls (or the step budget runs out).
     *
     * @param  AssistantMessage  $userMessage  the persisted user message (history = everything before it)
     * @param  array|null  $export  raw Drawflow export of the CURRENT canvas (null → saved graph)
     * @param  string  $mode  edit | run | view — write tools only in edit
     * @return array{content: string, ops: array, ui: array, cost_usd: float|null, steps: int}
     */
    public function turn(Flow $flow, AssistantMessage $userMessage, ?array $export, string $mode, ?callable $onStage = null): array
    {
        $flow->loadMissing('company');

        $this->loadWorkingCopy($flow, $export);
        $this->ops = [];
        $this->ui = [];

        ['provider' => $provider, 'model' => $model] = $this->providerModel();
        $maxSteps = max(1, (int) config('services.builder_assistant.max_steps', self::MAX_STEPS_DEFAULT));
        $tools = $this->toolSchemas($mode);

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($flow, $mode)],
            ...$this->history($flow, $userMessage),
            ['role' => 'user', 'content' => (string) $userMessage->content],
        ];

        LlmUsage::take(); // reset the accumulator — this turn's calls only
        LlmContext::set([
            'purpose' => 'assistant',
            'session_id' => $userMessage->session,
            'company_id' => $flow->company_id,
            'flow_id' => $flow->id,
        ]);

        $final = null;
        $steps = 0;

        try {
            for ($step = 1; $step <= $maxSteps; $step++) {
                $steps = $step;
                $result = $this->generator->chatTurn($provider, $model, $messages, $tools, [
                    'temperature' => 0.2,
                    'num_predict' => 3000,
                    'http_timeout' => 180,
                ]);

                if ($result['tool_calls'] === []) {
                    $final = $result['content'];
                    break;
                }

                $messages[] = [
                    'role' => 'assistant',
                    'content' => $result['content'],
                    'tool_calls' => $result['tool_calls'],
                ];

                foreach ($result['tool_calls'] as $call) {
                    if ($onStage) {
                        $onStage($this->stageLabel($call['name'], $call['arguments']));
                    }

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $call['id'],
                        'content' => $this->executeTool($flow, $call['name'], $call['arguments'], $mode),
                    ];
                }
            }

            // Step budget exhausted mid-investigation — ask for a wrap-up
            // without tools so the user still gets a useful answer.
            if ($final === null) {
                $messages[] = [
                    'role' => 'user',
                    'content' => '(Системно: лимитът на стъпки е изчерпан. Обобщи накратко какво откри/направи дотук — без повече инструменти.)',
                ];
                $result = $this->generator->chatTurn($provider, $model, $messages, [], [
                    'temperature' => 0.2,
                    'num_predict' => 1500,
                    'http_timeout' => 120,
                ]);
                $final = $result['content'];
            }
        } finally {
            LlmContext::clear();
        }

        $usage = LlmUsage::take();

        return [
            'content' => trim((string) $final),
            'ops' => $this->ops,
            'ui' => $this->ui,
            'cost_usd' => $usage['cost_usd'],
            'steps' => $steps,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Working copy
    // ──────────────────────────────────────────────────────────────────────

    private function loadWorkingCopy(Flow $flow, ?array $export): void
    {
        if (is_array($export) && $export !== []) {
            [$nodes, $edges] = $this->normalizer->parse($export);
        } else {
            $nodes = $flow->nodes()->get()->map(fn ($n) => [
                'node_key' => $n->node_key,
                'name' => $n->name, 'role' => $n->role, 'type' => $n->type, 'icon' => $n->icon,
                'prompt_template' => $n->prompt_template, 'system_prompt' => $n->system_prompt,
                'model' => $n->model, 'output_language' => $n->output_language,
                'output_tone' => $n->output_tone, 'output_style' => $n->output_style,
                'output_format' => $n->output_format, 'output_role' => $n->output_role,
                'config' => $n->config ?? [], 'is_active' => $n->is_active,
                'pos_x' => $n->pos_x, 'pos_y' => $n->pos_y,
            ])->all();
            $edges = $flow->edges()->get()
                ->map(fn ($e) => ['from_node_key' => $e->from_node_key, 'to_node_key' => $e->to_node_key])
                ->all();
        }

        $this->nodes = collect($nodes)->keyBy('node_key')->all();
        $this->edges = array_values(array_map(
            fn ($e) => ['from_node_key' => (string) $e['from_node_key'], 'to_node_key' => (string) $e['to_node_key']],
            $edges,
        ));
        $this->newNodeCounter = 0;
    }

    /** @return list<string> predecessors (node keys) of a node in the working copy */
    private function predecessorsOf(string $nodeKey): array
    {
        return array_values(array_unique(array_column(
            array_filter($this->edges, fn ($e) => $e['to_node_key'] === $nodeKey),
            'from_node_key',
        )));
    }

    /** @return list<string> successors (node keys) of a node in the working copy */
    private function successorsOf(string $nodeKey): array
    {
        return array_values(array_unique(array_column(
            array_filter($this->edges, fn ($e) => $e['from_node_key'] === $nodeKey),
            'to_node_key',
        )));
    }

    /**
     * Resolve a node reference the model used — exact node_key first, then
     * case-insensitive name match.
     */
    private function resolveNodeKey(string $ref): ?string
    {
        $ref = trim($ref);

        if (isset($this->nodes[$ref])) {
            return $ref;
        }

        foreach ($this->nodes as $key => $node) {
            if (mb_strtolower((string) ($node['name'] ?? '')) === mb_strtolower($ref)) {
                return $key;
            }
        }

        return null;
    }

    private function unknownNodeError(string $ref): string
    {
        return $this->json([
            'error' => "Няма възел „{$ref}“.",
            'налични' => collect($this->nodes)
                ->map(fn ($n, $k) => $k.' = '.($n['name'] ?? '?'))
                ->values()->all(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // System prompt + history
    // ──────────────────────────────────────────────────────────────────────

    private function systemPrompt(Flow $flow, string $mode): string
    {
        $graphLines = [];
        foreach ($this->nodes as $key => $node) {
            $qa = (bool) data_get($node, 'config.qa.enabled', false);
            $deps = $this->predecessorsOf((string) $key);
            $depNames = array_map(fn ($d) => $this->nodes[$d]['name'] ?? $d, $deps);
            $graphLines[] = sprintf(
                '- [%s] %s (type: %s)%s%s%s%s',
                $key,
                $node['name'] ?? '?',
                $node['type'] ?? '?',
                ! empty($node['model']) ? ' · модел: '.$node['model'] : ' · модел: авто',
                ($node['is_active'] ?? true) ? '' : ' · ИЗКЛЮЧЕН',
                $qa ? ' · QA gate: вкл (праг '.data_get($node, 'config.qa.threshold', 60).')' : '',
                $deps === [] ? ' · вход: seed (описанието/run inputs)' : ' · зависи от: '.implode(', ', $depNames),
            );
        }

        $notes = AssistantNote::where('company_id', $flow->company_id)
            ->where(fn ($q) => $q->whereNull('flow_id')->orWhere('flow_id', $flow->id))
            ->latest()->limit(20)->get();

        $notesBlock = $notes->isEmpty()
            ? ''
            : "\n\nЗАПОМНЕНИ БЕЛЕЖКИ (предпочитания на потребителя — спазвай ги):\n"
                .$notes->map(fn ($n) => '- '.($n->flow_id ? '[този flow] ' : '[компанията] ').$n->note)->implode("\n");

        $modeBlock = $mode === 'edit'
            ? 'Режим: РЕДАКЦИЯ — можеш да променяш графа чрез инструментите. Промените се прилагат върху канваса като ПРЕДЛОЖЕНИЯ; стават реални чак когато потребителят запази графа.'
            : 'Режим: '.($mode === 'run' ? 'ИЗПЪЛНЕНИЕ' : 'ПРЕГЛЕД').' — графът е заключен. Можеш да четеш, анализираш и съветваш, но НЕ можеш да правиш промени (кажи на потребителя да отвори builder-а в режим редакция).';

        $settings = $flow->settings ?? [];
        $inputs = collect((array) ($settings['inputs'] ?? []))->pluck('key')->implode(', ');
        $delivery = data_get($settings, 'delivery.channel');

        return <<<PROMPT
Ти си Copilot на Flow Builder-а в система за multi-agent AI workflows. Помагаш на потребителя
да разбира, настройва и подобрява своя flow от агенти — отговаряш на въпроси, преглеждаш стари
изпълнения, оценяваш настройките и правиш промени по графа чрез инструментите.

КАК РАБОТИ СИСТЕМАТА (накратко):
- Flow = DAG от агенти (възли) + връзки. Изпълнява се на „вълни“ с реален паралелизъм;
  изходите на преките предшественици са вход на възела.
- Всеки възел има system_prompt, prompt_template, модел и настройки. Placeholder-и в
  промптовете: {{input}} (обединен вход), {{node:Име}} (изход на конкретен възел),
  {{url}} (целевият сайт), {{topic}} и декларирани run inputs.
- Моделите: празно = кодът избира локален Ollama модел автоматично; "openai/<m>",
  "anthropic/<m>", "deepseek/<m>", "gemini/<m>", "xai/<m>", "qwen/<m>" закотвят възела
  на платен cloud провайдър.
- QA gate на възел: проверка на изхода със зададени критерии и праг; при провал — retry,
  а от втория retry planner-ът ревизира агента (адаптивно препланиране).
- Записът на графа в builder-а Е одобрението: захранва версиите (шаблони) и plan library
  (доказани планове стават few-shot примери за бъдещо планиране).
- Финалният текст обикновено минава през bg_text_corrector (предпоследен) и qa_verifier (последен).

ТЕКУЩ FLOW:
- Име: {$flow->name}
- Компания: {$flow->company?->name}
- Описание: {$this->excerpt((string) $flow->description, 600)}
- Декларирани run inputs: {$inputs}
- Доставка на резултата: {$delivery}
{$modeBlock}

ТЕКУЩ ГРАФ (работно копие от канваса — може да има незапазени промени):
{$this->joinLines($graphLines)}
{$notesBlock}

ПРАВИЛА:
1. Отговаряй НА БЪЛГАРСКИ, кратко и конкретно. Реферирай възлите по ИМЕ (не по ключ).
2. Преди да твърдиш нещо за промптове/настройки/изпълнения — провери с инструментите.
3. Промени прави САМО чрез инструментите (никога не описвай JSON в отговора). След
   промяна обобщи какво предлагаш и напомни, че трябва да се прегледа и запази.
4. Ако потребителят каже предпочитание, което важи занапред („винаги…“, „запомни…“) —
   запази го с remember_note.
5. Когато нещо не е еднозначно (кой възел, какъв праг) — попитай, не предполагай.
PROMPT;
    }

    /** @return list<array<string, mixed>> prior session messages (oldest first) */
    private function history(Flow $flow, AssistantMessage $userMessage): array
    {
        $limit = max(0, (int) config('services.builder_assistant.history_limit', 20));

        return AssistantMessage::where('flow_id', $flow->id)
            ->where('session', $userMessage->session)
            ->where('id', '<', $userMessage->id)
            ->where('status', 'completed')
            ->whereIn('role', ['user', 'assistant'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $this->excerpt((string) $m->content, 4000)])
            ->values()
            ->all();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Tool registry
    // ──────────────────────────────────────────────────────────────────────

    /** @return list<array{name: string, description: string, parameters: array}> */
    private function toolSchemas(string $mode): array
    {
        $obj = fn (array $props, array $required = []) => [
            'type' => 'object',
            'properties' => $props === [] ? new \stdClass : $props,
            'required' => $required,
            'additionalProperties' => false,
        ];
        $str = fn (string $desc) => ['type' => 'string', 'description' => $desc];

        $tools = [
            ['name' => 'get_node', 'description' => 'Пълните настройки на един възел: промптове, модел, config, QA, output полета.',
                'parameters' => $obj(['node_key' => $str('node_key или точното име на възела')], ['node_key'])],
            ['name' => 'get_flow_info', 'description' => 'Метаданни на flow-а: описание, настройки (run inputs, доставка), версии (шаблони), plan intent.',
                'parameters' => $obj([])],
            ['name' => 'get_capabilities', 'description' => 'Каталогът на системата: налични типове агенти, tools за custom агенти, модели (локални + платени).',
                'parameters' => $obj([])],
            ['name' => 'list_runs', 'description' => 'Последните изпълнения на flow-а: статус, време, цена, грешка.',
                'parameters' => $obj(['limit' => ['type' => 'integer', 'description' => 'Брой (по подразбиране 5, макс 10)']])],
            ['name' => 'get_run', 'description' => 'Детайли за едно изпълнение: статус по възли, QA резултати, адаптивни ревизии, грешки, цена.',
                'parameters' => $obj(['run_id' => ['type' => 'integer', 'description' => 'ID на изпълнението (от list_runs)']], ['run_id'])],
            ['name' => 'get_node_run_output', 'description' => 'Входът и изходът на конкретен възел в конкретно изпълнение (откъси).',
                'parameters' => $obj([
                    'run_id' => ['type' => 'integer', 'description' => 'ID на изпълнението'],
                    'node_key' => $str('node_key или име на възела'),
                ], ['run_id', 'node_key'])],
            ['name' => 'get_docs', 'description' => 'Документация на системата. Без параметър — списък със секциите; със section — съдържанието ѝ.',
                'parameters' => $obj(['section' => $str('Име (или част от името) на секция от документацията')])],
            ['name' => 'validate_graph', 'description' => 'Структурна проверка на текущия граф: цикли, висящи връзки, брой вълни.',
                'parameters' => $obj([])],
            ['name' => 'evaluate_flow', 'description' => 'AI оценка на целия flow спрямо заданието: покритие на задачите, зависимости, качество на промптовете, модели. По-бавен инструмент — ползвай го при изрично поискана оценка.',
                'parameters' => $obj([])],
            ['name' => 'remember_note', 'description' => 'Запомня траен факт/предпочитание на потребителя за бъдещи разговори.',
                'parameters' => $obj([
                    'scope' => ['type' => 'string', 'enum' => ['flow', 'company'], 'description' => 'flow = само този flow; company = всички flows на компанията'],
                    'note' => $str('Самият факт, кратко и еднозначно'),
                ], ['scope', 'note'])],
            ['name' => 'open_node', 'description' => 'Отваря панела с настройките на възел пред потребителя (UI действие).',
                'parameters' => $obj(['node_key' => $str('node_key или име на възела')], ['node_key'])],
        ];

        if ($mode !== 'edit') {
            return $tools;
        }

        $agentFields = [
            'name' => $str('Кратко българско име (2–5 думи)'),
            'role' => $str('Едно изречение — защо съществува възелът'),
            'system_prompt' => $str('Подробен системен промпт на български'),
            'prompt_template' => $str('Подробен потребителски промпт; placeholder-и: {{input}}, {{url}}, {{node:Име}}'),
            'model' => $str('Празно = авто (локален Ollama); или Ollama tag; или платен пин като openai/gpt-4o-mini'),
            'temperature' => ['type' => 'number', 'description' => '0.1–0.3 за анализ/QA, 0.6–0.8 за креативно'],
            'output_language' => $str('Език на изхода, напр. bg'),
            'qa_enabled' => ['type' => 'boolean', 'description' => 'QA gate на изхода'],
            'qa_threshold' => ['type' => 'integer', 'description' => 'Праг 0–100 (обикновено 60)'],
            'qa_custom_prompt' => $str('Конкретните QA критерии за този възел'),
            'tools' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Само за type=custom: web_search, scrape_page, crawl_site, discover_urls, google_reviews'],
        ];

        return [...$tools,
            ['name' => 'add_agent', 'description' => 'Добавя нов агент (възел) в графа. Свържи го чрез depends_on (входове) и feeds (към кого отива изходът му).',
                'parameters' => $obj([
                    'type' => $str('Тип от каталога (get_capabilities) или custom'),
                    'depends_on' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Възли (ключ/име), чиито изходи са му вход'],
                    'feeds' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Възли (ключ/име), които да получат изхода му'],
                    ...$agentFields,
                ], ['type', 'name'])],
            ['name' => 'update_agent', 'description' => 'Променя настройки на съществуващ възел. Подай САМО полетата, които променяш.',
                'parameters' => $obj([
                    'node_key' => $str('node_key или име на възела'),
                    'is_active' => ['type' => 'boolean', 'description' => 'Изключен възел не се изпълнява'],
                    'num_predict' => ['type' => 'integer', 'description' => 'Лимит на изходните токени; -1 = без лимит'],
                    'type' => $str('Нов тип от каталога (сменяй внимателно)'),
                    ...$agentFields,
                ], ['node_key'])],
            ['name' => 'remove_agent', 'description' => 'Премахва възел; връзките на предшествениците се пренасочват към наследниците (мост).',
                'parameters' => $obj(['node_key' => $str('node_key или име на възела')], ['node_key'])],
            ['name' => 'connect_agents', 'description' => 'Свързва два възела (изходът на from става вход на to).',
                'parameters' => $obj(['from' => $str('node_key/име'), 'to' => $str('node_key/име')], ['from', 'to'])],
            ['name' => 'disconnect_agents', 'description' => 'Премахва връзката между два възела.',
                'parameters' => $obj(['from' => $str('node_key/име'), 'to' => $str('node_key/име')], ['from', 'to'])],
        ];
    }

    private function stageLabel(string $tool, array $args): string
    {
        $ref = (string) ($args['node_key'] ?? $args['name'] ?? $args['from'] ?? '');
        $name = $ref !== '' ? ($this->nodes[$this->resolveNodeKey($ref) ?? '']['name'] ?? $ref) : '';

        return match ($tool) {
            'get_node' => 'Чета възел „'.$name.'“…',
            'get_flow_info' => 'Чета настройките на flow-а…',
            'get_capabilities' => 'Преглеждам каталога…',
            'list_runs' => 'Преглеждам изпълненията…',
            'get_run' => 'Чета изпълнение #'.($args['run_id'] ?? '').'…',
            'get_node_run_output' => 'Чета изхода на „'.$name.'“…',
            'get_docs' => 'Чета документацията…',
            'validate_graph' => 'Валидирам графа…',
            'evaluate_flow' => 'Оценявам плана (отнема малко повече)…',
            'add_agent' => 'Добавям агент „'.($args['name'] ?? '').'“…',
            'update_agent' => 'Променям „'.$name.'“…',
            'remove_agent' => 'Премахвам „'.$name.'“…',
            'connect_agents' => 'Свързвам възли…',
            'disconnect_agents' => 'Разкачам връзка…',
            'remember_note' => 'Запомням бележка…',
            'open_node' => 'Отварям „'.$name.'“…',
            default => 'Работя…',
        };
    }

    // ──────────────────────────────────────────────────────────────────────
    // Tool execution
    // ──────────────────────────────────────────────────────────────────────

    private function executeTool(Flow $flow, string $tool, array $args, string $mode): string
    {
        $writeTools = ['add_agent', 'update_agent', 'remove_agent', 'connect_agents', 'disconnect_agents'];

        if ($mode !== 'edit' && in_array($tool, $writeTools, true)) {
            return $this->json(['error' => 'Графът е заключен в този режим — промени са възможни само в режим редакция.']);
        }

        try {
            return match ($tool) {
                'get_node' => $this->toolGetNode($args),
                'get_flow_info' => $this->toolGetFlowInfo($flow),
                'get_capabilities' => $this->toolGetCapabilities(),
                'list_runs' => $this->toolListRuns($flow, $args),
                'get_run' => $this->toolGetRun($flow, $args),
                'get_node_run_output' => $this->toolGetNodeRunOutput($flow, $args),
                'get_docs' => $this->toolGetDocs($args),
                'validate_graph' => $this->toolValidateGraph(),
                'evaluate_flow' => $this->toolEvaluateFlow($flow),
                'add_agent' => $this->toolAddAgent($args),
                'update_agent' => $this->toolUpdateAgent($args),
                'remove_agent' => $this->toolRemoveAgent($args),
                'connect_agents' => $this->toolConnect($args),
                'disconnect_agents' => $this->toolDisconnect($args),
                'remember_note' => $this->toolRememberNote($flow, $args),
                'open_node' => $this->toolOpenNode($args),
                default => $this->json(['error' => "Непознат инструмент: {$tool}"]),
            };
        } catch (Throwable $e) {
            Log::warning("[BuilderAssistant] Tool {$tool} failed: ".$e->getMessage());

            return $this->json(['error' => 'Инструментът се провали: '.$e->getMessage()]);
        }
    }

    private function toolGetNode(array $args): string
    {
        $key = $this->resolveNodeKey((string) ($args['node_key'] ?? ''));
        if ($key === null) {
            return $this->unknownNodeError((string) ($args['node_key'] ?? ''));
        }

        $node = $this->nodes[$key];

        return $this->json([
            'node_key' => $key,
            'name' => $node['name'], 'type' => $node['type'], 'role' => $node['role'],
            'is_active' => (bool) ($node['is_active'] ?? true),
            'model' => $node['model'] ?: '(авто — локален Ollama)',
            'system_prompt' => $node['system_prompt'],
            'prompt_template' => $node['prompt_template'],
            'output' => array_filter([
                'language' => $node['output_language'] ?? null,
                'tone' => $node['output_tone'] ?? null,
                'style' => $node['output_style'] ?? null,
                'format' => $node['output_format'] ?? null,
                'role' => $node['output_role'] ?? null,
            ]),
            'config' => $node['config'] ?? [],
            'зависи_от' => array_map(fn ($k) => $this->nodes[$k]['name'] ?? $k, $this->predecessorsOf($key)),
            'захранва' => array_map(fn ($k) => $this->nodes[$k]['name'] ?? $k, $this->successorsOf($key)),
        ]);
    }

    private function toolGetFlowInfo(Flow $flow): string
    {
        return $this->json([
            'име' => $flow->name,
            'описание' => $this->excerpt((string) $flow->description, 1500),
            'topic' => $flow->topic,
            'settings' => $flow->settings ?? [],
            'plan_intent' => $flow->plan_intent,
            'версии' => $flow->versions()->latest()->get()
                ->map(fn ($v) => ['id' => $v->id, 'име' => $v->name, 'активна' => (bool) $v->is_active])->all(),
            'график' => $flow->schedule_cron,
        ]);
    }

    private function toolGetCapabilities(): string
    {
        $types = collect(config('agent_types', []))->keys()
            ->merge(AgentTemplate::whereNull('company_id')->where('is_active', true)->pluck('type'))
            ->unique()->values()->all();

        return $this->json([
            'каталог' => $this->planner->capabilityCatalogText(),
            'валидни_типове' => $types,
        ]);
    }

    private function toolListRuns(Flow $flow, array $args): string
    {
        $limit = min(10, max(1, (int) ($args['limit'] ?? 5)));

        $runs = $flow->flowRuns()->latest()->limit($limit)
            ->withSum('nodeRuns as run_cost', 'cost_usd')
            ->get()
            ->map(fn ($r) => [
                'run_id' => $r->id,
                'статус' => $r->status,
                'started' => $r->started_at?->toDateTimeString(),
                'продължителност_сек' => $r->started_at && $r->completed_at
                    ? (int) abs($r->completed_at->diffInSeconds($r->started_at)) : null,
                'грешка' => data_get($r->context, 'failure_message'),
                'цена_usd' => $r->run_cost > 0 ? round((float) $r->run_cost, 4) : null,
            ])->all();

        return $this->json($runs === [] ? ['info' => 'Този flow още няма изпълнения.'] : $runs);
    }

    private function toolGetRun(Flow $flow, array $args): string
    {
        $run = $flow->flowRuns()->find((int) ($args['run_id'] ?? 0));
        if (! $run) {
            return $this->json(['error' => 'Няма такова изпълнение за този flow. Ползвай list_runs за валидни ID-та.']);
        }

        $context = $run->context ?? [];

        return $this->json([
            'run_id' => $run->id,
            'статус' => $run->status,
            'started' => $run->started_at?->toDateTimeString(),
            'completed' => $run->completed_at?->toDateTimeString(),
            'грешка' => data_get($context, 'failure_message'),
            'qa_резултати' => data_get($context, 'step_qa_results', []),
            'адаптивни_ревизии' => data_get($context, 'replan', []),
            'доставка' => data_get($context, 'delivery'),
            'възли' => $run->nodeRuns()->orderBy('id')->get()
                ->map(fn ($nr) => array_filter([
                    'node_key' => $nr->node_key,
                    'име' => $this->nodes[$nr->node_key]['name'] ?? null,
                    'статус' => $nr->status,
                    'модел' => $nr->model_used,
                    'време_ms' => $nr->duration_ms,
                    'грешка' => $nr->error,
                    'изход_откъс' => $this->excerpt((string) $nr->output, 200),
                ], fn ($v) => $v !== null && $v !== ''))->all(),
            'финален_изход_откъс' => $this->excerpt((string) $run->final_output, 600),
        ]);
    }

    private function toolGetNodeRunOutput(Flow $flow, array $args): string
    {
        $run = $flow->flowRuns()->find((int) ($args['run_id'] ?? 0));
        if (! $run) {
            return $this->json(['error' => 'Няма такова изпълнение за този flow.']);
        }

        $ref = (string) ($args['node_key'] ?? '');
        $key = $this->resolveNodeKey($ref) ?? $ref;

        $nodeRun = $run->nodeRuns()->where('node_key', $key)->orderByDesc('id')->first();
        if (! $nodeRun) {
            return $this->json([
                'error' => "В изпълнение #{$run->id} няма възел „{$ref}“.",
                'налични' => $run->nodeRuns()->pluck('node_key')->unique()->values()->all(),
            ]);
        }

        return $this->json([
            'node_key' => $nodeRun->node_key,
            'статус' => $nodeRun->status,
            'модел' => $nodeRun->model_used,
            'грешка' => $nodeRun->error,
            'вход_откъс' => $this->excerpt((string) $nodeRun->input, 1500),
            'изход' => $this->excerpt((string) $nodeRun->output, 4000),
        ]);
    }

    private function toolGetDocs(array $args): string
    {
        $sections = $this->docsSections();
        $wanted = mb_strtolower(trim((string) ($args['section'] ?? '')));

        if ($wanted === '') {
            return $this->json(['секции' => array_keys($sections)]);
        }

        foreach ($sections as $title => $content) {
            if (str_contains(mb_strtolower($title), $wanted)) {
                return $this->json(['секция' => $title, 'съдържание' => $this->excerpt($content, 8000)]);
            }
        }

        return $this->json(['error' => 'Няма такава секция.', 'секции' => array_keys($sections)]);
    }

    /** @return array<string, string> section title => content */
    private function docsSections(): array
    {
        $sections = [];

        $types = collect(config('agent_types', []))
            ->map(fn ($meta, $slug) => "- {$slug}: ".($meta['label'] ?? $slug).' — '.($meta['description'] ?? ''))
            ->implode("\n");
        $sections['Типове агенти (каталог)'] = $types;

        $path = base_path('docs/DYNAMIC-AGENT-PLANNER.md');
        if (is_file($path)) {
            $current = null;
            foreach (preg_split('/\r?\n/', (string) file_get_contents($path)) as $line) {
                if (preg_match('/^##\s+(.+)$/', $line, $m)) {
                    $current = trim($m[1]);
                    $sections[$current] = '';

                    continue;
                }
                if ($current !== null) {
                    $sections[$current] .= $line."\n";
                }
            }
        }

        return $sections;
    }

    private function toolValidateGraph(): string
    {
        $result = GraphTopology::analyze(
            array_keys($this->nodes),
            array_map(fn ($e) => ['from' => $e['from_node_key'], 'to' => $e['to_node_key']], $this->edges),
        );

        return $this->json([
            'ok' => $result['ok'],
            'грешки' => $result['errors'],
            'брой_вълни' => count($result['waves']),
        ]);
    }

    private function toolEvaluateFlow(Flow $flow): string
    {
        $agents = [];
        foreach ($this->nodes as $key => $node) {
            $agents[] = [
                'name' => $node['name'],
                'type' => $node['type'],
                'role' => $node['role'],
                'system_prompt' => $this->excerpt((string) $node['system_prompt'], 600),
                'prompt_template' => $this->excerpt((string) $node['prompt_template'], 900),
                'model' => $node['model'] ?: 'авто',
                'temperature' => data_get($node, 'config.temperature'),
                'qa_gate' => (bool) data_get($node, 'config.qa.enabled', false),
                'depends_on' => array_map(fn ($k) => $this->nodes[$k]['name'] ?? $k, $this->predecessorsOf((string) $key)),
                'is_active' => (bool) ($node['is_active'] ?? true),
            ];
        }

        return $this->json($this->planner->critiqueExistingPlan($flow, $agents));
    }

    // ── write tools ───────────────────────────────────────────────────────

    private function toolAddAgent(array $args): string
    {
        $type = trim((string) ($args['type'] ?? ''));
        $name = trim((string) ($args['name'] ?? ''));

        if ($name === '') {
            return $this->json(['error' => 'name е задължително.']);
        }

        $validTypes = collect(config('agent_types', []))->keys()
            ->merge(AgentTemplate::whereNull('company_id')->where('is_active', true)->pluck('type'))
            ->push('custom')->unique();

        if (! $validTypes->contains($type)) {
            return $this->json(['error' => "Непознат тип „{$type}“.", 'валидни_типове' => $validTypes->values()->all()]);
        }

        if ($warning = $this->modelProblem((string) ($args['model'] ?? ''))) {
            return $this->json(['error' => $warning]);
        }

        $dependsOn = [];
        foreach ((array) ($args['depends_on'] ?? []) as $ref) {
            $key = $this->resolveNodeKey((string) $ref);
            if ($key === null) {
                return $this->unknownNodeError((string) $ref);
            }
            $dependsOn[] = $key;
        }

        $feeds = [];
        foreach ((array) ($args['feeds'] ?? []) as $ref) {
            $key = $this->resolveNodeKey((string) $ref);
            if ($key === null) {
                return $this->unknownNodeError((string) $ref);
            }
            $feeds[] = $key;
        }

        $nodeKey = 'new_'.(++$this->newNodeCounter);

        $config = ['temperature' => $this->clampTemperature($args['temperature'] ?? null)];
        if ($type === 'custom') {
            $config['tools'] = array_values(array_intersect(
                array_map('strval', (array) ($args['tools'] ?? [])),
                ['web_search', 'scrape_page', 'crawl_site', 'discover_urls', 'google_reviews'],
            ));
        }
        if (! in_array($type, ['qa_verifier', 'verifier'], true)) {
            $config['qa'] = [
                'enabled' => (bool) ($args['qa_enabled'] ?? false),
                'threshold' => min(100, max(0, (int) ($args['qa_threshold'] ?? 60))),
                'max_retries' => 3,
                'custom_prompt' => trim((string) ($args['qa_custom_prompt'] ?? ''))
                    ?: 'Провери дали изходът изпълнява описаната роля на агента, базиран е на реалните входни данни и е на правилния език.',
            ];
        }

        [$posX, $posY] = $this->placeNear($dependsOn, $feeds);

        $node = [
            'node_key' => $nodeKey,
            'name' => $name,
            'role' => trim((string) ($args['role'] ?? '')),
            'type' => $type,
            'icon' => AgentTemplate::whereNull('company_id')->where('type', $type)->value('icon'),
            'system_prompt' => trim((string) ($args['system_prompt'] ?? '')),
            'prompt_template' => trim((string) ($args['prompt_template'] ?? '')),
            'model' => trim((string) ($args['model'] ?? '')),
            'output_language' => trim((string) ($args['output_language'] ?? '')) ?: 'bg',
            'output_tone' => null, 'output_style' => null, 'output_format' => null,
            'output_role' => config("agent_types.{$type}.output_role"),
            'config' => $config,
            'is_active' => true,
            'pos_x' => $posX,
            'pos_y' => $posY,
        ];

        // Tentative topology check (cycles can only come from feeds ∩ ancestors of deps).
        $newEdges = [
            ...array_map(fn ($k) => ['from_node_key' => $k, 'to_node_key' => $nodeKey], $dependsOn),
            ...array_map(fn ($k) => ['from_node_key' => $nodeKey, 'to_node_key' => $k], $feeds),
        ];
        $check = GraphTopology::analyze(
            [...array_keys($this->nodes), $nodeKey],
            array_map(fn ($e) => ['from' => $e['from_node_key'], 'to' => $e['to_node_key']], [...$this->edges, ...$newEdges]),
        );
        if (! $check['ok']) {
            return $this->json(['error' => 'Тази комбинация от връзки чупи графа: '.implode('; ', $check['errors'])]);
        }

        $this->nodes[$nodeKey] = $node;
        array_push($this->edges, ...$newEdges);

        $this->ops[] = ['op' => 'add', 'node_key' => $nodeKey, 'data' => $node, 'pos_x' => $posX, 'pos_y' => $posY];
        foreach ($newEdges as $edge) {
            $this->ops[] = ['op' => 'connect', 'from' => $edge['from_node_key'], 'to' => $edge['to_node_key']];
        }

        return $this->json([
            'ok' => true,
            'node_key' => $nodeKey,
            'info' => "Възел „{$name}“ е добавен в работното копие"
                .($dependsOn ? ', вход от: '.implode(', ', array_map(fn ($k) => $this->nodes[$k]['name'], $dependsOn)) : '')
                .($feeds ? ', захранва: '.implode(', ', array_map(fn ($k) => $this->nodes[$k]['name'], $feeds)) : '')
                .'. Потребителят трябва да прегледа и запази.',
        ]);
    }

    private function toolUpdateAgent(array $args): string
    {
        $key = $this->resolveNodeKey((string) ($args['node_key'] ?? ''));
        if ($key === null) {
            return $this->unknownNodeError((string) ($args['node_key'] ?? ''));
        }

        if (array_key_exists('model', $args) && ($warning = $this->modelProblem((string) $args['model']))) {
            return $this->json(['error' => $warning]);
        }

        $node = $this->nodes[$key];
        $changed = [];

        foreach (['name', 'role', 'system_prompt', 'prompt_template', 'model', 'type', 'output_language'] as $field) {
            if (array_key_exists($field, $args) && is_string($args[$field])) {
                $node[$field] = trim($args[$field]);
                $changed[] = $field;
            }
        }

        if (array_key_exists('is_active', $args)) {
            $node['is_active'] = (bool) $args['is_active'];
            $changed[] = 'is_active';
        }

        $config = (array) ($node['config'] ?? []);
        if (array_key_exists('temperature', $args) && is_numeric($args['temperature'])) {
            $config['temperature'] = $this->clampTemperature($args['temperature']);
            $changed[] = 'temperature';
        }
        if (array_key_exists('num_predict', $args) && is_numeric($args['num_predict'])) {
            $config['num_predict'] = (int) $args['num_predict'];
            $changed[] = 'num_predict';
        }
        if (array_key_exists('tools', $args) && is_array($args['tools'])) {
            $config['tools'] = array_values(array_intersect(
                array_map('strval', $args['tools']),
                ['web_search', 'scrape_page', 'crawl_site', 'discover_urls', 'google_reviews'],
            ));
            $changed[] = 'tools';
        }

        $qa = (array) ($config['qa'] ?? []);
        if (array_key_exists('qa_enabled', $args)) {
            $qa['enabled'] = (bool) $args['qa_enabled'];
            $changed[] = 'qa_enabled';
        }
        if (array_key_exists('qa_threshold', $args) && is_numeric($args['qa_threshold'])) {
            $qa['threshold'] = min(100, max(0, (int) $args['qa_threshold']));
            $changed[] = 'qa_threshold';
        }
        if (array_key_exists('qa_custom_prompt', $args) && is_string($args['qa_custom_prompt'])) {
            $qa['custom_prompt'] = trim($args['qa_custom_prompt']);
            $changed[] = 'qa_custom_prompt';
        }
        if ($qa !== []) {
            $qa += ['enabled' => false, 'threshold' => 60, 'max_retries' => 3];
            $config['qa'] = $qa;
        }

        if ($changed === []) {
            return $this->json(['error' => 'Не подаде нито едно поле за промяна.']);
        }

        $node['config'] = $config;
        $this->nodes[$key] = $node;

        $this->ops[] = ['op' => 'update', 'node_key' => $key, 'data' => $node];

        return $this->json([
            'ok' => true,
            'info' => 'Променени полета на „'.$node['name'].'“: '.implode(', ', $changed).'. Потребителят трябва да прегледа и запази.',
        ]);
    }

    private function toolRemoveAgent(array $args): string
    {
        $key = $this->resolveNodeKey((string) ($args['node_key'] ?? ''));
        if ($key === null) {
            return $this->unknownNodeError((string) ($args['node_key'] ?? ''));
        }

        $name = $this->nodes[$key]['name'] ?? $key;
        $preds = $this->predecessorsOf($key);
        $succs = $this->successorsOf($key);

        unset($this->nodes[$key]);
        $this->edges = array_values(array_filter(
            $this->edges,
            fn ($e) => $e['from_node_key'] !== $key && $e['to_node_key'] !== $key,
        ));

        $this->ops[] = ['op' => 'remove', 'node_key' => $key];

        // Bridge the gap so the pipeline stays connected.
        $bridged = [];
        foreach ($preds as $from) {
            foreach ($succs as $to) {
                if ($this->edgeExists($from, $to)) {
                    continue;
                }
                $this->edges[] = ['from_node_key' => $from, 'to_node_key' => $to];
                $this->ops[] = ['op' => 'connect', 'from' => $from, 'to' => $to];
                $bridged[] = ($this->nodes[$from]['name'] ?? $from).' → '.($this->nodes[$to]['name'] ?? $to);
            }
        }

        return $this->json([
            'ok' => true,
            'info' => "Възел „{$name}“ е премахнат от работното копие."
                .($bridged ? ' Мостови връзки: '.implode('; ', $bridged).'.' : ''),
        ]);
    }

    private function toolConnect(array $args): string
    {
        $from = $this->resolveNodeKey((string) ($args['from'] ?? ''));
        $to = $this->resolveNodeKey((string) ($args['to'] ?? ''));

        if ($from === null || $to === null) {
            return $this->unknownNodeError((string) ($from === null ? ($args['from'] ?? '') : ($args['to'] ?? '')));
        }
        if ($from === $to) {
            return $this->json(['error' => 'Възелът не може да се свърже със себе си.']);
        }
        if ($this->edgeExists($from, $to)) {
            return $this->json(['error' => 'Тази връзка вече съществува.']);
        }

        $check = GraphTopology::analyze(
            array_keys($this->nodes),
            array_map(fn ($e) => ['from' => $e['from_node_key'], 'to' => $e['to_node_key']],
                [...$this->edges, ['from_node_key' => $from, 'to_node_key' => $to]]),
        );
        if (! $check['ok']) {
            return $this->json(['error' => 'Връзката би създала проблем: '.implode('; ', $check['errors'])]);
        }

        $this->edges[] = ['from_node_key' => $from, 'to_node_key' => $to];
        $this->ops[] = ['op' => 'connect', 'from' => $from, 'to' => $to];

        return $this->json(['ok' => true, 'info' => ($this->nodes[$from]['name'] ?? $from).' → '.($this->nodes[$to]['name'] ?? $to)]);
    }

    private function toolDisconnect(array $args): string
    {
        $from = $this->resolveNodeKey((string) ($args['from'] ?? ''));
        $to = $this->resolveNodeKey((string) ($args['to'] ?? ''));

        if ($from === null || $to === null || ! $this->edgeExists($from, $to)) {
            return $this->json(['error' => 'Няма такава връзка.']);
        }

        $this->edges = array_values(array_filter(
            $this->edges,
            fn ($e) => ! ($e['from_node_key'] === $from && $e['to_node_key'] === $to),
        ));
        $this->ops[] = ['op' => 'disconnect', 'from' => $from, 'to' => $to];

        return $this->json(['ok' => true, 'info' => 'Връзката е премахната (в работното копие).']);
    }

    private function toolRememberNote(Flow $flow, array $args): string
    {
        $note = trim((string) ($args['note'] ?? ''));
        if ($note === '') {
            return $this->json(['error' => 'Празна бележка.']);
        }

        AssistantNote::create([
            'company_id' => $flow->company_id,
            'flow_id' => ($args['scope'] ?? 'flow') === 'company' ? null : $flow->id,
            'note' => mb_substr($note, 0, 1000),
        ]);

        return $this->json(['ok' => true, 'info' => 'Запомнено.']);
    }

    private function toolOpenNode(array $args): string
    {
        $key = $this->resolveNodeKey((string) ($args['node_key'] ?? ''));
        if ($key === null) {
            return $this->unknownNodeError((string) ($args['node_key'] ?? ''));
        }

        $this->ui[] = ['action' => 'open_node', 'node_key' => $key];

        return $this->json(['ok' => true, 'info' => 'Панелът на „'.($this->nodes[$key]['name'] ?? $key).'“ ще се отвори при потребителя.']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function edgeExists(string $from, string $to): bool
    {
        foreach ($this->edges as $edge) {
            if ($edge['from_node_key'] === $from && $edge['to_node_key'] === $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reject models that cannot run: unknown paid prefix / missing API key.
     * Unknown local tags are allowed (the executor auto-pulls Ollama models).
     */
    private function modelProblem(string $model): ?string
    {
        $model = trim($model);
        if ($model === '' || ! str_contains($model, '/')) {
            return null;
        }

        $provider = strstr($model, '/', true);

        // Ollama tags can legitimately contain '/' (e.g. todorov/bggpt:…) —
        // only validate when the prefix names a paid provider.
        if (! array_key_exists((string) $provider, PaidModel::PREFIXES)) {
            return LlmModel::where('ollama_tag', $model)->exists()
                ? null
                : "Моделът „{$model}“ не е сред инсталираните и няма платен префикс — провери get_capabilities.";
        }

        if (! PaidModel::available((string) $provider)) {
            return "Провайдърът {$provider} няма API ключ в момента — избери друг модел.";
        }

        return null;
    }

    private function clampTemperature(mixed $value): float
    {
        return is_numeric($value) ? max(0.0, min(1.2, (float) $value)) : 0.3;
    }

    /** @return array{0: int, 1: int} a canvas position near the node's neighbours */
    private function placeNear(array $dependsOn, array $feeds): array
    {
        $anchorKeys = $dependsOn !== [] ? $dependsOn : $feeds;
        $anchors = array_values(array_filter(array_map(fn ($k) => $this->nodes[$k] ?? null, $anchorKeys)));

        if ($anchors === []) {
            $maxX = max([0, ...array_map(fn ($n) => (int) ($n['pos_x'] ?? 0), $this->nodes)]);

            return [$maxX + 280, 120];
        }

        $avgY = (int) (array_sum(array_map(fn ($n) => (int) ($n['pos_y'] ?? 0), $anchors)) / count($anchors));
        $x = $dependsOn !== []
            ? max(array_map(fn ($n) => (int) ($n['pos_x'] ?? 0), $anchors)) + 280
            : min(array_map(fn ($n) => (int) ($n['pos_x'] ?? 0), $anchors)) - 280;

        return [max(40, $x), max(40, $avgY + 40)];
    }

    private function excerpt(string $text, int $limit): string
    {
        $text = trim($text);

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit).'… [съкратено]' : $text;
    }

    private function joinLines(array $lines): string
    {
        return $lines === [] ? '(празен граф — все още няма възли)' : implode("\n", $lines);
    }

    private function json(mixed $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
