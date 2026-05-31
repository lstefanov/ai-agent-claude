<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Company;
use App\Models\Flow;
use App\Models\LlmModel;
use App\Services\AgentGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class FlowController extends Controller
{
    public function __construct(
        private AgentGeneratorService $generator,
        private \App\Services\OllamaService $ollama,
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
            'name'          => 'required|string|max:255',
            'description'   => 'required|string',
            'status'        => 'required|in:draft,active,paused',
            'schedule_cron' => 'nullable|string|max:100',
            'agents'        => 'required|array|min:1',
            'agents.*._uid'             => 'nullable|string|max:100',
            'agents.*.name'             => 'required|string',
            'agents.*.type'             => 'required|string',
            'agents.*.role'             => 'required|string',
            'agents.*.model'            => 'required|string',
            'agents.*.prompt_template'  => 'required|string',
            'agents.*.order'            => 'required|integer|min:1',
            'agents.*.system_prompt'    => 'nullable|string',
            'agents.*.is_verifier'      => 'nullable|boolean',
            'agents.*.qa_threshold'     => 'nullable|integer|min:0|max:100',
            'agents.*.config'           => 'nullable|array',
            'agents.*.config.temperature' => 'nullable|numeric|min:0|max:2',
            'agents.*.config.top_p' => 'nullable|numeric|min:0|max:1',
            'agents.*.config.top_k' => 'nullable|integer|min:1|max:200',
            'agents.*.config.repeat_penalty' => 'nullable|numeric|min:0|max:2',
            'agents.*.config.num_predict' => 'nullable|integer|min:-1',
            'agents.*.config.qa' => 'nullable|array',
            'agents.*.config.qa.enabled' => 'nullable|boolean',
            'agents.*.config.qa.verifier_agent_uid' => 'nullable|string|max:100',
            'agents.*.config.qa.verifier_agent_order' => 'nullable|integer|min:1',
            'agents.*.config.qa.threshold' => 'nullable|integer|min:0|max:100',
            'agents.*.config.qa.max_retries' => 'nullable|integer|min:0|max:10',
            'agents.*.config.qa.custom_prompt' => 'nullable|string|max:2000',
        ]);

        $flow = $company->flows()->create([
            'name'          => $validated['name'],
            'description'   => $validated['description'],
            'status'        => $validated['status'],
            'schedule_cron' => $validated['schedule_cron'] ?? null,
        ]);

        $createdAgents = [];

        foreach ($validated['agents'] as $agentData) {
            $isVerifier = (bool) ($agentData['is_verifier'] ?? false) || $agentData['type'] === 'qa_verifier';
            $config = isset($agentData['config']) ? (array) $agentData['config'] : ['temperature' => 0.7, 'num_predict' => 1000];
            unset($config['qa']);

            $agent = $flow->agents()->create([
                'name'              => $agentData['name'],
                'type'              => $agentData['type'],
                'role'              => $agentData['role'],
                'capabilities'      => isset($agentData['capabilities']) ? (array) $agentData['capabilities'] : [],
                'strengths'         => $agentData['strengths'] ?? null,
                'limitations'       => $agentData['limitations'] ?? null,
                'input_description' => $agentData['input_description'] ?? null,
                'output_description'=> $agentData['output_description'] ?? null,
                'prompt_template'   => $agentData['prompt_template'],
                'system_prompt'     => $agentData['system_prompt'] ?? null,
                'model'             => $agentData['model'],
                'model_reason'      => $agentData['model_reason'] ?? null,
                'order'             => (int) $agentData['order'],
                'is_verifier'       => $isVerifier,
                'qa_threshold'      => $isVerifier ? (int) ($agentData['qa_threshold'] ?? 75) : null,
                'config'            => $config,
                'is_active'         => true,
            ]);

            $createdAgents[] = ['agent' => $agent, 'source' => $agentData];
        }

        $this->applyInitialStepQaPolicies($createdAgents);

        return redirect()->route('flows.show', $flow)
            ->with('success', 'Flow е създаден успешно с ' . count($validated['agents']) . ' агента.');
    }

    public function show(Flow $flow)
    {
        $flow->load(['company', 'agents' => fn($q) => $q->orderBy('order')]);
        $runs = $flow->flowRuns()->latest()->take(10)->get();
        $models = LlmModel::where('is_available', true)
            ->where('is_enabled', true)
            ->orderBy('category')
            ->orderBy('display_name')
            ->get(['ollama_tag', 'display_name', 'category', 'description', 'is_default_for', 'is_available']);
        return view('flows.show', compact('flow', 'runs', 'models'));
    }

    public function edit(Flow $flow)
    {
        return view('flows.edit', compact('flow'));
    }

    public function update(Request $request, Flow $flow)
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'description'   => 'required|string',
            'status'        => 'required|in:draft,active,paused',
            'schedule_cron' => 'nullable|string|max:100',
        ]);

        $flow->update($validated);

        return redirect()->route('flows.show', $flow)
            ->with('success', 'Flow е обновен.');
    }

    public function archive(Flow $flow)
    {
        $flow->update([
            'is_archived' => true,
            'archived_at' => now(),
        ]);

        return back()->with('success', 'Flow "' . $flow->name . '" е архивиран.');
    }

    public function unarchive(Flow $flow)
    {
        $flow->update([
            'is_archived' => false,
            'archived_at' => null,
        ]);

        return back()->with('success', 'Flow "' . $flow->name . '" е възстановен.');
    }

    public function destroy(Flow $flow)
    {
        $company = $flow->company;
        $name    = $flow->name;
        $flow->delete();
        return redirect()->route('companies.show', $company)
            ->with('success', 'Flow "' . $name . '" е изтрит.');
    }

    /**
     * AJAX: improve a flow description using AI.
     */
    public function improveDescription(Request $request)
    {
        $request->validate([
            'description' => 'required|string|min:5',
            'name'        => 'nullable|string',
            'company_id'  => 'nullable|exists:companies,id',
        ]);

        $name        = $request->name ?? '';
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
            $improved = $this->ollama->chat(
                model: config('services.ollama.generator_model', 'mistral-nemo'),
                systemPrompt: $systemPrompt,
                userMessage: $userMessage,
                options: ['temperature' => 0.4, 'num_predict' => 600]
            );
        } catch (\Exception $e) {
            return response()->json(['error' => 'AI услугата не е достъпна. Провери дали Ollama работи.'], 503);
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
            'company_id'  => 'required|exists:companies,id',
            'name'        => 'required|string',
            'description' => 'required|string|min:10',
        ]);

        $token = Str::uuid()->toString();

        // Store request data so the background command can read it
        Cache::put("agent_gen_request_{$token}", [
            'company_id'  => $request->company_id,
            'name'        => $request->name,
            'description' => $request->description,
        ], now()->addMinutes(15));

        // Initialise status so the poller immediately sees 'pending'
        Cache::put("agent_gen_{$token}", [
            'status' => 'pending',
            'agents' => [],
            'error'  => null,
        ], now()->addMinutes(15));

        // Launch background artisan command (won't be killed by Apache timeout)
        $php     = env('PHP_CLI_BINARY', PHP_BINARY);
        $artisan = base_path('artisan');
        $tok     = escapeshellarg($token);
        exec("{$php} {$artisan} flows:generate-agents {$tok} >> " . escapeshellarg(storage_path('logs/agent-gen.log')) . " 2>&1 &");

        return response()->json(['token' => $token]);
    }

    /**
     * Poll endpoint: returns {status, agents, error} for a generation token.
     */
    public function generationStatus(string $token)
    {
        $result = Cache::get("agent_gen_{$token}");

        if (!$result) {
            return response()->json(['status' => 'expired', 'error' => 'Токенът е изтекъл. Опитай отново.'], 404);
        }

        return response()->json($result);
    }

    private function applyInitialStepQaPolicies(array $createdAgents): void
    {
        $byUid = collect($createdAgents)
            ->filter(fn ($item) => ! empty($item['source']['_uid']))
            ->mapWithKeys(fn ($item) => [$item['source']['_uid'] => $item['agent']]);
        $byOrder = collect($createdAgents)
            ->mapWithKeys(fn ($item) => [(int) $item['agent']->order => $item['agent']]);

        foreach ($createdAgents as $item) {
            /** @var Agent $agent */
            $agent = $item['agent'];
            $source = $item['source'];
            $config = $agent->config ?? [];

            if ($agent->is_verifier) {
                unset($config['qa']);
                $agent->update(['config' => $config]);
                continue;
            }

            $qa = $source['config']['qa'] ?? [];
            if (! filter_var($qa['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $config['qa'] = ['enabled' => false];
                $agent->update(['config' => $config]);
                continue;
            }

            $verifier = null;
            if (! empty($qa['verifier_agent_uid'])) {
                $verifier = $byUid->get($qa['verifier_agent_uid']);
            }
            if (! $verifier && ! empty($qa['verifier_agent_order'])) {
                $verifier = $byOrder->get((int) $qa['verifier_agent_order']);
            }

            if (! $verifier || ! $verifier->is_verifier || (int) $verifier->id === (int) $agent->id) {
                $config['qa'] = ['enabled' => false];
                $agent->update(['config' => $config]);
                continue;
            }

            $config['qa'] = [
                'enabled' => true,
                'verifier_agent_id' => (int) $verifier->id,
                'threshold' => (int) ($qa['threshold'] ?? $verifier->qa_threshold ?? 75),
                'max_retries' => min(10, max(0, (int) ($qa['max_retries'] ?? 3))),
                'custom_prompt' => trim($qa['custom_prompt'] ?? ''),
            ];

            $agent->update(['config' => $config]);
        }
    }
}
