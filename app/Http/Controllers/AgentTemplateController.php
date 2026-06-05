<?php

namespace App\Http\Controllers;

use App\Models\AgentTemplate;
use App\Models\Company;
use App\Models\LlmModel;
use Illuminate\Http\Request;

class AgentTemplateController extends Controller
{
    public function picker(Request $request)
    {
        $companyId = $request->integer('company_id');

        $system = AgentTemplate::whereNull('company_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id','name','description','icon','type','role','system_prompt',
                   'prompt_template','model','capabilities','strengths','limitations',
                   'input_description','output_description','is_verifier',
                   'qa_threshold','config']);

        $company = AgentTemplate::where('company_id', $companyId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id','name','description','icon','type','role','system_prompt',
                   'prompt_template','model','capabilities','strengths','limitations',
                   'input_description','output_description','is_verifier',
                   'qa_threshold','config']);

        return response()->json(compact('system', 'company'));
    }

    public function index(Company $company)
    {
        $templates = AgentTemplate::where('company_id', $company->id)
            ->orderBy('sort_order')->orderBy('name')->get();

        return view('companies.agent-templates.index', compact('company', 'templates'));
    }

    public function create(Company $company)
    {
        $models = LlmModel::where('is_enabled', true)
            ->orderBy('display_name')->get();

        return view('companies.agent-templates.create', compact('company', 'models'));
    }

    public function store(Request $request, Company $company)
    {
        $data = $this->validateTemplate($request);
        $data['company_id'] = $company->id;
        AgentTemplate::create($data);

        return redirect()->route('companies.agent-templates.index', $company)
            ->with('success', 'Шаблонът е създаден.');
    }

    public function edit(Company $company, AgentTemplate $agentTemplate)
    {
        abort_if($agentTemplate->company_id !== $company->id, 403);

        $models = LlmModel::where('is_enabled', true)
            ->orderBy('display_name')->get();

        return view('companies.agent-templates.edit', compact('company', 'agentTemplate', 'models'));
    }

    public function update(Request $request, Company $company, AgentTemplate $agentTemplate)
    {
        abort_if($agentTemplate->company_id !== $company->id, 403);

        $agentTemplate->update($this->validateTemplate($request));

        return redirect()->route('companies.agent-templates.index', $company)
            ->with('success', 'Шаблонът е обновен.');
    }

    public function destroy(Company $company, AgentTemplate $agentTemplate)
    {
        abort_if($agentTemplate->company_id !== $company->id, 403);

        $agentTemplate->delete();

        return redirect()->route('companies.agent-templates.index', $company)
            ->with('success', 'Шаблонът е изтрит.');
    }

    private function validateTemplate(Request $request): array
    {
        return $request->validate([
            'name'               => 'required|string|max:255',
            'description'        => 'required|string|max:500',
            'icon'               => 'required|string|max:10',
            'type'               => 'required|string|max:50',
            'role'               => 'nullable|string',
            'system_prompt'      => 'nullable|string',
            'prompt_template'    => 'nullable|string',
            'model'              => 'nullable|string|max:100',
            'is_verifier'        => 'boolean',
            'qa_threshold'       => 'nullable|integer|min:0|max:100',
            'sort_order'         => 'integer|min:0',
            'config.temperature' => 'nullable|numeric|min:0|max:2',
            'config.num_predict' => 'nullable|integer|min:-1',
        ]);
    }
}
