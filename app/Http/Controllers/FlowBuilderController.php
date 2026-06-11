<?php

namespace App\Http\Controllers;

use App\Models\AgentTemplate;
use App\Models\Flow;
use App\Models\LlmModel;
use App\Services\GeneratorService;
use App\Support\PlannerPhases;
use App\Support\UrlExtractor;
use Illuminate\Http\Request;

class FlowBuilderController extends Controller
{
    public function show(Flow $flow, Request $request, GeneratorService $generator)
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
            ->get(['ollama_tag', 'display_name', 'category', 'description', 'is_default_for', 'strengths']);

        // Paid-provider options for the node "Модел" picker (☁) — an explicit
        // "openai/<model>" / "anthropic/<model>" pin executes that node on the
        // paid provider. Only providers with an API key are offered. The two
        // slots per provider: runtime (бърз/евтин) и generator (най-силният).
        $paidDescriptions = [
            'runtime' => 'Бърз и евтин cloud модел — масови стъпки, стриктен JSON, надеждно следване на инструкции.',
            'generator' => 'Най-силният модел на провайдъра — сложен fan-in синтез, дълги доклади, критични стъпки.',
        ];

        $paidModels = collect([
            'openai' => ['runtime' => config('services.openai.runtime_model'), 'generator' => config('services.openai.model')],
            'anthropic' => ['runtime' => config('services.anthropic.runtime_model'), 'generator' => config('services.anthropic.model')],
            'deepseek' => ['runtime' => config('services.deepseek.runtime_model'), 'generator' => config('services.deepseek.model')],
            'gemini' => ['runtime' => config('services.gemini.runtime_model'), 'generator' => config('services.gemini.model')],
            'xai' => ['runtime' => config('services.xai.runtime_model'), 'generator' => config('services.xai.model')],
            'qwen' => ['runtime' => config('services.qwen.runtime_model'), 'generator' => config('services.qwen.model')],
        ])
            ->filter(fn ($m, $provider) => ! empty(config("services.{$provider}.api_key")))
            ->flatMap(fn ($slots, $provider) => collect($slots)
                ->filter()
                ->unique()
                ->map(fn ($m, $slot) => [
                    'value' => "{$provider}/{$m}",
                    'label' => ucfirst($provider).' · '.$m,
                    'description' => $paidDescriptions[$slot] ?? '',
                ])
                // values() — иначе flatMap слива по slot ключовете ('runtime'/
                // 'generator') и вторият provider презаписва първия.
                ->values())
            ->values()
            ->all();

        $templateIcons = AgentTemplate::query()
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->whereNull('company_id')->where('is_active', true))
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

        // Graph versions ("шаблони"): the dropdown switches which version the
        // editor edits; ?version= selects a non-active one.
        $versions = $flow->versions()
            ->latest()
            ->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'is_active' => $v->is_active,
                'generator_label' => $v->generatorLabel(),
            ])
            ->values();

        $activeVersion = $flow->activeVersion;
        $selectedVersion = $request->filled('version')
            ? $flow->versions()->find($request->integer('version'))
            : null;
        $selectedVersion ??= $activeVersion;

        // Editing a non-active version loads ITS layout; the run/view snapshot
        // logic below still wins in those modes.
        $editLayout = $selectedVersion && ! $selectedVersion->is_active
            ? $selectedVersion->graph_layout
            : $flow->graph_layout;

        $config = [
            'saveUrl' => route('flows.graph.store', $flow),
            'validateUrl' => route('flows.graph.validate', $flow),
            'builderUrl' => route('flows.builder', $flow),
            'versionStoreUrl' => route('flows.versions.store', $flow),
            'versions' => $versions,
            'selectedVersionId' => $selectedVersion?->id,
            'activeVersionId' => $activeVersion?->id,
            // Generation config popup: per-phase defaults (.env resolved),
            // provider availability + model lists + pricing for the estimate.
            'plannerDefaults' => $generator->resolveAllPhases(),
            'plannerProviders' => GeneratorService::PROVIDERS,
            'plannerAvailability' => collect(GeneratorService::PROVIDERS)
                ->mapWithKeys(fn ($p) => [$p => $generator->providerAvailable($p)])
                ->all(),
            'cloudModels' => PlannerPhases::cloudModels(),
            'pricing' => PlannerPhases::pricing(),
            'newTemplate' => (bool) $request->boolean('new_template'),
            'runUrl' => route('flow-runs.store', $flow),
            'pollUrl' => $pollRun ? route('flow-runs.poll', $pollRun) : null,
            // Full node payloads are fetched on demand — the poll is metadata-only.
            'nodeDetailUrlBase' => $pollRun ? url("runs/{$pollRun->id}/nodes") : null,
            // Тест на агент: poll URL for ad-hoc test tokens (POST URLs derive
            // from nodeDetailUrlBase: …/{key}/test and …/{key}/apply-test).
            'nodeTestStatusUrlBase' => url('node-test-status'),
            'pickerUrl' => route('agent-templates.picker'),
            'generateFieldUrl' => route('agents.generate-field'),
            'generateUrl' => route('flows.generate-agents'),
            'generationStatusUrlBase' => url('flows/generation-status'),
            // Builder Copilot (чат асистентът): send + status poll + history.
            'assistantSendUrl' => route('flows.assistant.send', $flow),
            'assistantStatusUrlBase' => url('flows/assistant-status'),
            'assistantHistoryUrl' => route('flows.assistant.history', $flow),
            'generationLogsUrl' => route('flows.generation-logs', $flow),
            'activeRunId' => $activeRun?->id,
            'mode' => $mode,
            'autoOpenFinal' => $mode === 'view',
            'generate' => (bool) $request->boolean('generate'),
            'csrf' => csrf_token(),
            'companyId' => $flow->company_id,
            'flowId' => $flow->id,
            'flowName' => $flow->name,
            'flowDescription' => $flow->description,
            // Per-run inputs: default {{topic}} + any custom placeholders the
            // flow declares in settings.inputs ([{key,label}]). Rendered as
            // fields on the run trigger so one flow serves many inputs.
            'flowTopic' => $flow->topic,
            // Site flows get a "Сайт (URL)" run input that overrides the seed
            // {{url}} (buildSeed falls back to this description URL when empty).
            'flowTargetUrl' => UrlExtractor::first($flow->description ?? '') ?? '',
            'runInputs' => array_values((array) ($flow->settings['inputs'] ?? [])),
            'agentTypes' => $agentTypes,
            'templateIcons' => $templateIcons,
            'models' => $models,
            'paidModels' => $paidModels,
            // In view mode, use the snapshot taken at run start so the historical
            // viewer shows the graph exactly as it was when the run executed.
            'graphLayout' => $mode === 'view' && $viewRun?->graph_snapshot
                ? $viewRun->graph_snapshot
                : $editLayout,
            'outputPrefs' => config('output_preferences'),
        ];

        return view('flows.builder', [
            'flow' => $flow,
            'config' => $config,
        ]);
    }
}
