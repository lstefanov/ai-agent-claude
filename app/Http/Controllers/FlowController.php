<?php

namespace App\Http\Controllers;

use App\Models\AgentGenerationLog;
use App\Models\Company;
use App\Models\Flow;
use App\Models\LlmModel;
use App\Services\AgentGeneratorService;
use App\Services\GeneratorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class FlowController extends Controller
{
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
        $runs = $flow->flowRuns()->latest()->take(10)->get();
        $models = LlmModel::where('is_available', true)
            ->where('is_enabled', true)
            ->orderBy('category')
            ->orderBy('display_name')
            ->get(['ollama_tag', 'display_name', 'category', 'description', 'is_default_for', 'is_available']);

        $agentTypes = collect(config('agent_types', []))
            ->map(fn ($meta, $type) => [
                'type' => $type,
                'label' => $meta['label'] ?? $type,
                'output_role' => $meta['output_role'] ?? 'body',
            ])
            ->values()
            ->all();

        $graphPreviewConfig = [
            // Nodes sorted by horizontal position for a left-to-right preview.
            // We load them from the DB (not graph_layout JSON) for reliability.
            'nodes' => $flow->nodes()
                ->orderBy('pos_x')
                ->get(['node_key', 'name', 'type', 'icon', 'output_role', 'pos_x'])
                ->toArray(),
            'hasGraph' => $flow->nodes()->exists(),
            'builderUrl' => route('flows.builder', $flow),
            'agentTypes' => $agentTypes,
        ];

        return view('flows.show', compact('flow', 'runs', 'models', 'graphPreviewConfig'));
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
            $improved = $this->llm->chat(
                systemPrompt: $systemPrompt,
                userMessage: $userMessage,
                options: ['temperature' => 0.4, 'num_predict' => 600]
            );
        } catch (\Exception $e) {
            return response()->json(['error' => 'AI услугата не е достъпна. Провери GENERATOR_PROVIDER и API ключа.'], 503);
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
        ]);

        if (! $this->llm->isAvailable()) {
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
     * Recent agent-generation logs for this flow's company, with full detail
     * (system/user prompt, options, raw response). Powers the builder's
     * "Лог на генерирането" panel.
     */
    public function generationLogs(Flow $flow)
    {
        $logs = AgentGenerationLog::query()
            ->where(fn ($q) => $q
                ->where('flow_id', $flow->id)
                ->orWhere('company_id', $flow->company_id)
            )
            ->latest()
            ->limit(20)
            ->get([
                'id', 'provider', 'model', 'system_prompt', 'user_message',
                'options', 'raw_response', 'parsed_count', 'status', 'error',
                'duration_ms', 'created_at',
            ]);

        return response()->json([
            'logs' => $logs->map(fn ($l) => [
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
                'created_at' => $l->created_at?->format('Y-m-d H:i:s'),
            ])->values(),
        ]);
    }
}
