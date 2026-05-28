<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Flow;
use App\Models\LlmModel;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function edit(Flow $flow, Agent $agent)
    {
        $models = LlmModel::orderBy('category')->orderBy('display_name')->get();
        return view('agents.edit', compact('flow', 'agent', 'models'));
    }

    public function update(Request $request, Flow $flow, Agent $agent)
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:255',
            'role'             => 'required|string',
            'prompt_template'  => 'required|string',
            'model'            => 'required|string',
            'is_active'        => 'boolean',
            'qa_threshold'     => 'nullable|integer|min:0|max:100',
            'config'           => 'nullable|array',
        ]);

        $agent->update($validated);

        return redirect()->route('flows.show', $flow)
            ->with('success', 'Агентът е обновен.');
    }
}
