<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Company;
use App\Models\Flow;
use App\Models\LlmModel;
use App\Services\AgentGeneratorService;
use Illuminate\Http\Request;

class FlowController extends Controller
{
    public function __construct(private AgentGeneratorService $generator) {}

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

    public function destroy(Flow $flow)
    {
        $company = $flow->company;
        $flow->delete();
        return redirect()->route('companies.show', $company)
            ->with('success', 'Flow е изтрит.');
    }

    public function generateAgents(Request $request)
    {
        $request->validate([
            'company_id'  => 'required|exists:companies,id',
            'name'        => 'required|string',
            'description' => 'required|string|min:10',
        ]);

        $company = Company::findOrFail($request->company_id);

        // Temporary unsaved flow for context
        $flow              = new Flow(['name' => $request->name, 'description' => $request->description]);
        $flow->company_id  = $company->id;
        $flow->setRelation('company', $company);

        try {
            $agents = $this->generator->generate($flow);

            if (empty($agents)) {
                return response()->json(['error' => 'AI не върна валиден отговор. Опитай отново.'], 422);
            }

            return response()->json(['agents' => $agents]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Грешка при генериране: ' . $e->getMessage()], 500);
        }
    }
}
