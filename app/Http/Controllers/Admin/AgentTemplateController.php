<?php
// app/Http/Controllers/Admin/AgentTemplateController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentTemplate;
use App\Models\LlmModel;
use Illuminate\Http\Request;

class AgentTemplateController extends Controller
{
    public function index()
    {
        $templates = AgentTemplate::whereNull('company_id')
            ->orderBy('sort_order')->orderBy('name')->get();

        return view('admin.agent-templates.index', compact('templates'));
    }

    public function create()
    {
        $models = LlmModel::where('is_enabled', true)->orderBy('display_name')->get();

        return view('admin.agent-templates.create', compact('models'));
    }

    public function store(Request $request)
    {
        $data = $this->validateTemplate($request);
        $data['company_id'] = null;
        AgentTemplate::create($data);

        return redirect()->route('admin.agent-templates.index')
            ->with('success', 'Системният шаблон е създаден.');
    }

    public function edit(AgentTemplate $agentTemplate)
    {
        abort_if($agentTemplate->company_id !== null, 403);

        $models = LlmModel::where('is_enabled', true)->orderBy('display_name')->get();

        return view('admin.agent-templates.edit', compact('agentTemplate', 'models'));
    }

    public function update(Request $request, AgentTemplate $agentTemplate)
    {
        abort_if($agentTemplate->company_id !== null, 403);

        $agentTemplate->update($this->validateTemplate($request));

        return redirect()->route('admin.agent-templates.index')
            ->with('success', 'Системният шаблон е обновен.');
    }

    public function destroy(AgentTemplate $agentTemplate)
    {
        abort_if($agentTemplate->company_id !== null, 403);

        $agentTemplate->delete();

        return redirect()->route('admin.agent-templates.index')
            ->with('success', 'Системният шаблон е изтрит.');
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
