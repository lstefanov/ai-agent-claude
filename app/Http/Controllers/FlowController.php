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
        $models = LlmModel::orderBy('category')->orderBy('display_name')->get();
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
            'agents.*.name'             => 'required|string',
            'agents.*.type'             => 'required|string',
            'agents.*.role'             => 'required|string',
            'agents.*.model'            => 'required|string',
            'agents.*.prompt_template'  => 'required|string',
            'agents.*.order'            => 'required|integer|min:1',
            'agents.*.system_prompt'    => 'nullable|string',
        ]);

        $flow = $company->flows()->create([
            'name'          => $validated['name'],
            'description'   => $validated['description'],
            'status'        => $validated['status'],
            'schedule_cron' => $validated['schedule_cron'] ?? null,
        ]);

        foreach ($validated['agents'] as $agentData) {
            $flow->agents()->create([
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
                'is_verifier'       => (bool) ($agentData['is_verifier'] ?? false),
                'qa_threshold'      => isset($agentData['qa_threshold']) ? (int) $agentData['qa_threshold'] : null,
                'config'            => isset($agentData['config']) ? (array) $agentData['config'] : null,
                'is_active'         => true,
            ]);
        }

        return redirect()->route('flows.show', $flow)
            ->with('success', 'Flow е създаден успешно с ' . count($validated['agents']) . ' агента.');
    }

    public function show(Flow $flow)
    {
        $flow->load(['company', 'agents' => fn($q) => $q->orderBy('order')]);
        $runs = $flow->flowRuns()->latest()->take(10)->get();
        return view('flows.show', compact('flow', 'runs'));
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
                options: ['temperature' => 0.4, 'num_predict' => 300]
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
}
