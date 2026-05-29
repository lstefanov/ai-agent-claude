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
        $models = LlmModel::where('is_enabled', true)
            ->orderBy('category')
            ->orderBy('display_name')
            ->get();

        return view('agents.edit', compact('flow', 'agent', 'models'));
    }

    public function update(Request $request, Flow $flow, Agent $agent)
    {
        $validated = $request->validate([
            'name'                  => 'required|string|max:255',
            'role'                  => 'required|string',
            'system_prompt'         => 'nullable|string',
            'prompt_template'       => 'required|string',
            'model'                 => 'required|string',
            'is_active'             => 'boolean',
            'qa_threshold'          => 'nullable|integer|min:0|max:100',
            // Output preferences
            'output_language'       => 'nullable|string|max:10',
            'output_tone'           => 'nullable|string|max:30',
            'output_style'          => 'nullable|string|max:30',
            'output_format'         => 'nullable|string|max:30',
            // Model parameters (stored in config JSON)
            'config'                => 'nullable|array',
            'config.temperature'    => 'nullable|numeric|min:0|max:2',
            'config.top_p'          => 'nullable|numeric|min:0|max:1',
            'config.top_k'          => 'nullable|integer|min:1|max:200',
            'config.repeat_penalty' => 'nullable|numeric|min:0|max:2',
            'config.num_predict'    => 'nullable|integer|min:-1',
        ]);

        $agent->update($validated);

        return redirect()->route('flows.show', $flow)
            ->with('success', 'Агентът е обновен.');
    }
}
