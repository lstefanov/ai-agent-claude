<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Flow;
use App\Models\LlmModel;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function store(Request $request, Flow $flow)
    {
        $request->validate([
            'name'  => 'required|string|max:255',
            'model' => 'required|string',
        ]);

        $order = ($flow->agents()->max('order') ?? 0) + 1;

        $agent = $flow->agents()->create([
            'name'            => $request->input('name'),
            'model'           => $request->input('model'),
            'type'            => $request->input('type', 'content_bg'),
            'role'            => $request->input('role') ?? '',
            'system_prompt'   => $request->input('system_prompt') ?? '',
            'prompt_template' => $request->input('prompt_template') ?? '',
            'order'           => $order,
            'config'          => ['temperature' => 0.7, 'num_predict' => 1000],
            'is_active'       => true,
        ]);

        return response()->json(['agent' => $agent], 201);
    }

    public function edit(Flow $flow, Agent $agent)
    {
        $models = LlmModel::where('is_enabled', true)
            ->where('is_available', true)
            ->orderBy('category')
            ->orderBy('display_name')
            ->get(['ollama_tag', 'display_name', 'category', 'description', 'is_default_for']);

        return view('agents.edit', compact('flow', 'agent', 'models'));
    }

    public function update(Request $request, Flow $flow, Agent $agent)
    {
        $validated = $request->validate([
            'name'                  => 'required|string|max:255',
            'type'                  => 'nullable|string|max:50',
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
            'output_role'           => 'nullable|in:body,appendix,hidden',
            // Model parameters (stored in config JSON)
            'config'                => 'nullable|array',
            'config.temperature'    => 'nullable|numeric|min:0|max:2',
            'config.top_p'          => 'nullable|numeric|min:0|max:1',
            'config.top_k'          => 'nullable|integer|min:1|max:200',
            'config.repeat_penalty' => 'nullable|numeric|min:0|max:2',
            'config.num_predict'    => 'nullable|integer|min:-1',
        ]);

        // Convert empty string to null for output_role
        if (array_key_exists('output_role', $validated)) {
            $validated['output_role'] = $validated['output_role'] ?: null;
        }

        $agent->update($validated);

        if ($request->expectsJson()) {
            return response()->json(['agent' => $agent->fresh()]);
        }

        return redirect()->route('flows.show', $flow)
            ->with('success', 'Агентът е обновен.');
    }

    public function destroy(Flow $flow, Agent $agent)
    {
        $agent->delete();

        $flow->agents()->orderBy('order')->get()
             ->each(fn($a, $i) => $a->update(['order' => $i + 1]));

        return response()->noContent();
    }

    public function reorder(Request $request, Flow $flow)
    {
        $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'integer',
        ]);

        foreach ($request->ids as $position => $id) {
            $flow->agents()->where('id', $id)->update(['order' => $position + 1]);
        }

        return response()->json(['ok' => true]);
    }
}
