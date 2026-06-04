<?php

namespace App\Http\Controllers;

use App\Models\AgentTemplate;
use App\Models\Flow;
use App\Models\FlowRun;
use App\Models\LlmModel;
use Illuminate\Http\Request;

class FlowBuilderController extends Controller
{
    public function show(Flow $flow, Request $request)
    {
        $flow->load('company');

        $agentTypes = collect(config('agent_types', []))
            ->map(fn ($meta, $type) => [
                'type' => $type,
                'label' => $meta['label'] ?? $type,
                'description' => $meta['description'] ?? '',
                'output_role' => $meta['output_role'] ?? 'body',
            ])
            ->values()
            ->all();

        $models = LlmModel::where('is_available', true)
            ->where('is_enabled', true)
            ->orderBy('category')
            ->orderBy('display_name')
            ->get(['ollama_tag', 'display_name', 'category', 'description', 'is_default_for']);

        $templateIcons = AgentTemplate::query()
            ->where(fn ($query) => $query
                ->whereNull('company_id')
                ->orWhere('company_id', $flow->company_id)
            )
            ->whereNotNull('icon')
            ->orderByRaw('company_id is not null')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['type', 'icon'])
            ->pluck('icon', 'type');

        // Auto-cancel runs stuck for >2 hours (queue worker was not running).
        // This prevents the builder from opening in permanent "locked run" mode
        // after a developer session where `composer dev` wasn't running.
        $flow->flowRuns()
            ->where('status', 'running')
            ->where('started_at', '<', now()->subHours(2))
            ->update(['status' => 'failed', 'completed_at' => now()]);

        // A specific historical run was requested → read-only "view" mode.
        $viewRun = null;
        if ($request->filled('run')) {
            $viewRun = $flow->flowRuns()->find($request->integer('run'));
        }

        // Latest still-active run → builder colors nodes live for it.
        $activeRun = $flow->flowRuns()
            ->whereIn('status', ['pending', 'running'])
            ->latest()
            ->first();

        // Mode: run (if ?run= points to a still-active run) > view (historical) > run (latest active) > edit.
        if ($viewRun && in_array($viewRun->status, ['pending', 'running'])) {
            $mode = 'run';
            $pollRun = $viewRun;
        } elseif ($viewRun) {
            $mode = 'view';
            $pollRun = $viewRun;
        } elseif ($activeRun) {
            $mode = 'run';
            $pollRun = $activeRun;
        } else {
            $mode = 'edit';
            $pollRun = null;
        }

        $config = [
            'saveUrl' => route('flows.graph.store', $flow),
            'validateUrl' => route('flows.graph.validate', $flow),
            'runUrl' => route('flow-runs.store', $flow),
            'pollUrl' => $pollRun ? route('flow-runs.poll', $pollRun) : null,
            'pickerUrl' => route('agent-templates.picker'),
            'generateFieldUrl' => route('agents.generate-field'),
            'generateUrl' => route('flows.generate-agents'),
            'generationStatusUrlBase' => url('flows/generation-status'),
            'generationLogsUrl' => route('flows.generation-logs', $flow),
            'activeRunId' => $activeRun?->id,
            'mode' => $mode,
            'autoOpenFinal' => $mode === 'view',
            'generate' => (bool) $request->boolean('generate'),
            'csrf' => csrf_token(),
            'companyId' => $flow->company_id,
            'flowName' => $flow->name,
            'flowDescription' => $flow->description,
            'agentTypes' => $agentTypes,
            'templateIcons' => $templateIcons,
            'models' => $models,
            // In view mode, use the snapshot taken at run start so the historical
            // viewer shows the graph exactly as it was when the run executed.
            'graphLayout' => $mode === 'view' && $viewRun?->graph_snapshot
                ? $viewRun->graph_snapshot
                : $flow->graph_layout,
            'outputPrefs' => config('output_preferences'),
        ];

        return view('flows.builder', [
            'flow' => $flow,
            'config' => $config,
        ]);
    }
}
