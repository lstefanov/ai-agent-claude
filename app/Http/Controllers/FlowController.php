<?php

namespace App\Http\Controllers;

use App\Models\AgentGenerationLog;
use App\Models\Company;
use App\Models\Flow;
use App\Models\LlmModel;
use App\Services\AgentGeneratorService;
use App\Services\GeneratorService;
use App\Support\ModelLevel;
use App\Support\PlannerPhases;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FlowController extends Controller
{
    /**
     * Model ids travel into shell-composed artisan args (plan-ab --variant) и
     * в Config::set — само безопасни идентификаторни знаци (без , = интервал).
     */
    public const MODEL_PATTERN = '/^[A-Za-z0-9._\/:-]+$/';

    public function __construct(
        private AgentGeneratorService $generator,
        private GeneratorService $llm,
    ) {}

    public function index()
    {
        // Not used — flows shown inside company show page
    }

    public function create(Company $company)
    {
        $models = LlmModel::where('is_available', true)
            ->where('is_enabled', true)
            ->orderBy('category')
            ->orderBy('display_name')
            ->get(['ollama_tag', 'display_name', 'category', 'description', 'is_default_for']);

        return view('flows.create', compact('company', 'models'));
    }

    public function store(Request $request, Company $company)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'status' => 'required|in:draft,active,paused',
            'schedule_cron' => 'nullable|string|max:100',
        ]);

        $flow = $company->flows()->create([
            'name' => $validated['name'],
            'description' => $validated['description'],
            'status' => $validated['status'],
            'schedule_cron' => $validated['schedule_cron'] ?? null,
        ]);

        // Agents are now built in the Graph Editor. Redirect there and kick off
        // AI generation automatically (the builder shows a progress popup and
        // auto-saves the resulting graph once).
        return redirect()->route('flows.builder', ['flow' => $flow, 'generate' => 1])
            ->with('success', 'Flow е създаден. Генерираме агентите…');
    }

    public function show(Flow $flow)
    {
        $flow->load('company');
        $versions = $flow->versions()
            ->withCount([
                'flowRuns as successful_runs_count' => fn ($query) => $query->where('status', 'completed'),
                'flowRuns as failed_runs_count' => fn ($query) => $query->where('status', 'failed'),
            ])
            ->with(['flowRuns' => fn ($query) => $query
                ->latest()
                ->withSum('nodeRuns as runtime_cost_usd', 'cost_usd')])
            ->latest()
            ->get()
            ->each(function ($version) {
                $runtimeCostUsd = $version->flowRuns->sum(fn ($run) => (float) ($run->runtime_cost_usd ?? 0));

                $version->setAttribute('last_run_at', $version->flowRuns->first()?->created_at);
                $version->setAttribute('total_cost_usd', (float) ($version->cost_usd ?? 0) + $runtimeCostUsd);
            });

        return view('flows.show', compact('flow', 'versions'));
    }

    public function runsHistory(Request $request, Flow $flow): JsonResponse
    {
        $query = $flow->flowRuns()->with('flowVersion')->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('triggered_by')) {
            $query->where('triggered_by', $request->triggered_by);
        }
        if ($request->filled('version_id')) {
            $query->where('flow_version_id', $request->integer('version_id'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('started_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('started_at', '<=', $request->date_to);
        }

        $perPage = $request->integer('per_page', 15);
        $paginator = $query->withSum('nodeRuns', 'cost_usd')
            ->paginate($perPage > 0 ? $perPage : PHP_INT_MAX);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'triggered_by' => $r->triggered_by,
                'version_name' => $r->flowVersion?->name,
                'started_at' => $r->started_at?->format('d.m.Y H:i'),
                'duration_secs' => $r->started_at && $r->completed_at
                    ? $r->started_at->diffInSeconds($r->completed_at) : null,
                'cost_usd' => $r->node_runs_sum_cost_usd > 0
                    ? number_format((float) $r->node_runs_sum_cost_usd, 4)
                    : null,
                'builder_url' => route('flows.builder', ['flow' => $flow, 'run' => $r->id]),
            ]),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $perPage,
            ],
        ]);
    }

    public function edit(Flow $flow)
    {
        return view('flows.edit', compact('flow'));
    }

    public function update(Request $request, Flow $flow)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'status' => 'required|in:draft,active,paused',
            'schedule_cron' => 'nullable|string|max:100',
        ]);

        $flow->update($validated);

        return redirect()->route('flows.show', $flow)
            ->with('success', 'Flow е обновен.');
    }

    /**
     * Persist flow-level settings (currently result delivery). Stored in the
     * flows.settings JSON bag and consumed by DeliveryService after a run.
     */
    public function updateSettings(Request $request, Flow $flow)
    {
        $validated = $request->validate([
            'delivery_channel' => 'required|in:none,email,slack,webhook,file',
            'delivery_target' => 'nullable|string|max:2000',
            'delivery_subject' => 'nullable|string|max:255',
        ]);

        $settings = (array) ($flow->settings ?? []);
        $settings['delivery'] = [
            'channel' => $validated['delivery_channel'],
            'target' => trim((string) ($validated['delivery_target'] ?? '')),
            'subject' => trim((string) ($validated['delivery_subject'] ?? '')),
        ];
        $flow->update(['settings' => $settings]);

        return back()->with('success', 'Настройките за доставка са запазени.');
    }

    public function archive(Flow $flow)
    {
        $flow->update([
            'is_archived' => true,
            'archived_at' => now(),
        ]);

        return back()->with('success', 'Flow "'.$flow->name.'" е архивиран.');
    }

    public function unarchive(Flow $flow)
    {
        $flow->update([
            'is_archived' => false,
            'archived_at' => null,
        ]);

        return back()->with('success', 'Flow "'.$flow->name.'" е възстановен.');
    }

    public function generateWebhookSecret(Flow $flow)
    {
        $flow->update(['webhook_secret' => Str::random(40)]);

        return back()->with('success', 'Webhook URL е генериран.');
    }

    public function revokeWebhookSecret(Flow $flow)
    {
        $flow->update(['webhook_secret' => null]);

        return back()->with('success', 'Webhook URL е деактивиран.');
    }

    public function destroy(Flow $flow)
    {
        $company = $flow->company;
        $name = $flow->name;
        $flow->delete();

        return redirect()->route('companies.show', $company)
            ->with('success', 'Flow "'.$name.'" е изтрит.');
    }

    /**
     * AJAX: improve a flow description using AI.
     */
    public function improveDescription(Request $request)
    {
        $request->validate([
            'description' => 'required|string|min:5',
            'name' => 'nullable|string',
            'company_id' => 'nullable|exists:companies,id',
        ]);

        $name = $request->name ?? '';
        $description = $request->description;

        $systemPrompt = 'Ти си експерт по бизнес автоматизация и дигитален маркетинг. Подобряваш описания на автоматизирани workflows. Отговаряй САМО с подобреното описание — без въведение, без обяснения, без кавички.';

        $userMessage = <<<MSG
Подобри следното описание на flow "{$name}".

Оригинално описание:
{$description}

Изисквания:
- Напиши 3-5 изречения на български
- Бъди конкретен за: какво прави flow-ът, за коя аудитория е, на какъв език е изходът, каква е структурата на pipeline-а
- Запази оригиналния смисъл, но го направи по-детайлен и по-ясен за AI агентите
- Върни САМО подобреното описание, без допълнителен текст
MSG;

        try {
            $improved = $this->llm->assist(
                systemPrompt: $systemPrompt,
                userMessage: $userMessage,
                options: ['temperature' => 0.4, 'num_predict' => 600]
            );
        } catch (\Exception $e) {
            return response()->json(['error' => 'AI услугата не е достъпна. Провери ASSIST_PROVIDER и API ключа.'], 503);
        }

        return response()->json(['improved' => trim($improved)]);
    }

    /**
     * Start agent generation in a background process and return a token.
     * The client polls generationStatus() every 2 seconds.
     */
    public function generateAgents(Request $request)
    {
        $request->validate([
            'company_id' => 'required|exists:companies,id',
            'flow_id' => 'required|exists:flows,id',
            'name' => 'required|string',
            'description' => 'required|string|min:10',
            'level' => ['nullable', Rule::enum(ModelLevel::class)],
            'phases' => 'nullable|array',
            'phases.*.provider' => ['required_with:phases', Rule::in(GeneratorService::PROVIDERS)],
            'phases.*.model' => ['nullable', 'string', 'max:120', 'regex:'.self::MODEL_PATTERN],
        ]);

        // Per-phase provider/model from the builder's generation popup. Only
        // the four known phases are honoured; missing ones run on the .env
        // defaults (GENERATOR_PROVIDER / PLANNER_*_PROVIDER).
        $phases = collect((array) $request->input('phases', []))
            ->only(PlannerPhases::PHASES)
            ->map(fn ($spec) => [
                'provider' => (string) $spec['provider'],
                'model' => isset($spec['model']) && $spec['model'] !== '' ? (string) $spec['model'] : null,
            ])
            ->all();

        if ($phases !== []) {
            $unavailable = collect($phases)
                ->pluck('provider')
                ->unique()
                ->reject(fn ($p) => $this->llm->providerAvailable($p));

            if ($unavailable->isNotEmpty()) {
                return response()->json([
                    'error' => 'Недостъпни провайдъри: '.$unavailable->implode(', ').' (липсва API ключ в .env или Ollama не отговаря).',
                ], 503);
            }
        } elseif (! $this->llm->isAvailable()) {
            return response()->json([
                'error' => 'AI planner-ът не е достъпен. Задай GENERATOR_PROVIDER=openai/anthropic (+API ключ) или ollama (работещ Ollama сървър) в .env.',
            ], 503);
        }

        $token = Str::uuid()->toString();

        // Store request data so the background command can read it
        Cache::put("agent_gen_request_{$token}", [
            'company_id' => $request->company_id,
            'flow_id' => (int) $request->flow_id,
            'name' => $request->name,
            'description' => $request->description,
            'level' => ModelLevel::fromRequest($request->input('level'))->value,
            'phases' => $phases,
        ], now()->addMinutes(15));

        // Initialise status so the poller immediately sees 'pending'
        Cache::put("agent_gen_{$token}", [
            'status' => 'pending',
            'agents' => [],
            'error' => null,
            'stage' => 'Стартиране...',
            'updated_at' => now()->timestamp,
        ], now()->addMinutes(15));

        // Launch background artisan command (won't be killed by Apache timeout)
        $php = env('PHP_CLI_BINARY', PHP_BINARY);
        $artisan = base_path('artisan');
        $tok = escapeshellarg($token);
        exec("{$php} {$artisan} flows:generate-agents {$tok} >> ".escapeshellarg(storage_path('logs/agent-gen.log')).' 2>&1 &');

        return response()->json(['token' => $token]);
    }

    /**
     * Poll endpoint: returns {status, agents, error} for a generation token.
     */
    public function generationStatus(string $token)
    {
        $result = Cache::get("agent_gen_{$token}");

        if (! $result) {
            return response()->json(['status' => 'expired', 'error' => 'Токенът е изтекъл. Опитай отново.'], 404);
        }

        return response()->json($result);
    }

    /**
     * Recent agent-generation runs for this flow's company, grouped by token
     * (one group per plan() invocation — its phases share the token), with
     * full per-phase detail and summed cost. Powers the builder's
     * "Лог на генерирането" panel.
     */
    public function generationLogs(Flow $flow)
    {
        $logs = AgentGenerationLog::query()
            ->where('flow_id', $flow->id)
            ->latest()
            ->limit(60)
            ->get([
                'id', 'token', 'provider', 'model', 'system_prompt', 'user_message',
                'options', 'raw_response', 'parsed_count', 'status', 'error',
                'duration_ms', 'prompt_tokens', 'completion_tokens', 'cost_usd', 'created_at',
            ]);

        // LlmUsage::take() stores null for zero-cost calls; with tokens present
        // that means "free tier", not "untracked" — surface it as $0.
        $phaseCost = fn ($l) => $l->cost_usd !== null
            ? (float) $l->cost_usd
            : (($l->prompt_tokens || $l->completion_tokens) ? 0.0 : null);

        $groups = $logs
            ->groupBy(fn ($l) => $l->token ?: 'log-'.$l->id)
            ->map(function ($phases) use ($phaseCost) {
                $phases = $phases->sortBy('id')->values();
                $statuses = $phases->pluck('status');
                $costs = $phases->map($phaseCost)->filter(fn ($c) => $c !== null);
                $final = $phases->last(fn ($l) => (int) $l->parsed_count > 0);

                return [
                    'id' => $phases->last()->id,
                    'created_at' => $phases->first()->created_at?->format('Y-m-d H:i:s'),
                    'status' => $statuses->contains('failed') ? 'failed'
                        : ($statuses->contains('running') ? 'running' : 'completed'),
                    'provider' => $phases
                        ->map(fn ($l) => preg_replace('/\s*\([^)]*\)$/', '', (string) $l->provider))
                        ->filter()->unique()->implode(' + '),
                    'model' => $phases->pluck('model')->filter()->unique()->implode(' + '),
                    'parsed_count' => $final?->parsed_count,
                    'cost_usd' => $costs->isEmpty() ? null : round((float) $costs->sum(), 6),
                    'duration_ms' => (int) $phases->sum('duration_ms'),
                    'phases' => $phases->map(fn ($l) => [
                        'id' => $l->id,
                        'provider' => $l->provider,
                        'model' => $l->model,
                        'system_prompt' => $l->system_prompt,
                        'user_message' => $l->user_message,
                        'options' => $l->options,
                        'raw_response' => $l->raw_response,
                        'parsed_count' => $l->parsed_count,
                        'status' => $l->status,
                        'error' => $l->error,
                        'duration_ms' => $l->duration_ms,
                        'prompt_tokens' => $l->prompt_tokens,
                        'completion_tokens' => $l->completion_tokens,
                        'cost_usd' => $phaseCost($l),
                        'created_at' => $l->created_at?->format('Y-m-d H:i:s'),
                    ])->values(),
                ];
            })
            ->sortByDesc('id')
            ->take(20)
            ->values();

        return response()->json(['groups' => $groups]);
    }
}
