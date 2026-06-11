<?php

namespace App\Http\Controllers;

use App\Models\Flow;
use App\Services\AgentGeneratorService;
use App\Services\FlowVersionService;
use App\Services\GeneratorService;
use App\Services\GraphNormalizer;
use App\Services\ModelSelectorService;
use App\Support\GraphTopology;
use App\Support\LlmUsage;
use App\Support\ModelLevel;
use App\Support\PaidModel;
use App\Support\PlannerPhases;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class FlowGraphController extends Controller
{
    public function __construct(
        private GraphNormalizer $normalizer,
        private FlowVersionService $versions,
    ) {}

    /**
     * Persist a Drawflow export into the flow's SELECTED version (version_id,
     * default: the active one). Every save re-materializes the version's own
     * flow_nodes/flow_edges; saving the active one also feeds the plan library
     * (saving IS the plan approval).
     *
     * A flow with zero versions bootstraps a "Default" active version from
     * this save — covers the new-flow ?generate=1 auto-save and first saves.
     */
    public function store(Request $request, Flow $flow): JsonResponse
    {
        $graph = $request->input('graph');

        if (! is_array($graph)) {
            return response()->json(['ok' => false, 'error' => 'Невалиден граф.'], 422);
        }

        $agents = is_array($request->input('agents')) ? $request->input('agents') : null;
        $generator = is_array($request->input('generator')) ? $request->input('generator') : null;
        $intent = is_array($request->input('intent')) ? $request->input('intent') : null;
        $modelLevel = $this->modelLevelInput($request);

        try {
            if ($flow->versions()->doesntExist()) {
                $version = $this->versions->createFromLayout($flow, $graph, 'Default', true, $agents, [
                    'intent' => $intent,
                    'generator' => $generator ?? ['label' => PlannerPhases::label(app(GeneratorService::class)->resolveAllPhases())],
                    'model_level' => $modelLevel,
                ]);
            } else {
                $version = $request->filled('version_id')
                    ? $flow->versions()->find($request->integer('version_id'))
                    : $flow->activeVersion;

                if (! $version) {
                    return response()->json(['ok' => false, 'error' => 'Шаблонът не е намерен.'], 422);
                }

                $this->versions->updateLayout($version, $graph, [
                    'agents' => $agents,
                    'generator' => $generator,
                    'plan_intent' => $intent,
                    'model_level' => $modelLevel,
                ]);
                $version->refresh();
            }
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'error' => 'Грешка при запис на графа.'], 500);
        }

        return response()->json(['ok' => true, 'version' => [
            'id' => $version->id,
            'name' => $version->name,
            'is_active' => $version->is_active,
            'generator_label' => $version->generatorLabel(),
            'model_level' => $version->model_level,
        ]]);
    }

    /**
     * Validate a posted graph (or the persisted one) — cycles, dangling edges,
     * missing terminal node. Drives the builder's "Validate" button before a run.
     */
    public function validateGraph(Request $request, Flow $flow): JsonResponse
    {
        $graph = $request->input('graph');

        if (is_array($graph)) {
            [$nodes, $edges] = $this->normalizer->parse($graph);
            $nodeKeys = array_map(fn ($n) => $n['node_key'], $nodes);
            $edgeList = array_map(fn ($e) => ['from' => $e['from_node_key'], 'to' => $e['to_node_key']], $edges);
        } else {
            $version = $request->filled('version_id')
                ? $flow->versions()->find($request->integer('version_id'))
                : $flow->activeVersion;
            $nodeKeys = $version ? $version->nodes()->pluck('node_key')->all() : [];
            $edgeList = $version
                ? $version->edges()->get(['from_node_key', 'to_node_key'])
                    ->map(fn ($e) => ['from' => $e->from_node_key, 'to' => $e->to_node_key])
                    ->all()
                : [];
        }

        $result = GraphTopology::analyze($nodeKeys, $edgeList);

        return response()->json([
            'ok' => $result['ok'],
            'errors' => $result['errors'],
            'wave_count' => count($result['waves']),
        ]);
    }

    /** Token assumptions per node when the version has no completed run yet. */
    private const EST_INPUT_TOKENS = 6000;

    private const EST_OUTPUT_TOKENS = 3000;

    /**
     * Preview a model-level switch: deterministically re-pin every agent's
     * model for the target level and estimate the per-run cost with the NEW
     * models. Nothing is persisted — the builder applies the returned models
     * client-side and saves through the normal graph store.
     */
    public function relevel(Request $request, Flow $flow, AgentGeneratorService $generator, ModelSelectorService $selector): JsonResponse
    {
        $request->validate([
            'graph' => 'required|array',
            'level' => ['required', Rule::enum(ModelLevel::class)],
            'version_id' => 'nullable|integer',
        ]);

        $level = ModelLevel::from((string) $request->input('level'));
        [$nodes, $edges] = $this->normalizer->parse($request->input('graph'));

        $assignments = $generator->assignModelsForLevel($nodes, $edges, $level);
        $tokens = $this->lastRunTokens($flow, $request->filled('version_id') ? $request->integer('version_id') : null);

        $out = [];
        $total = 0.0;

        foreach ($nodes as $node) {
            $key = (string) $node['node_key'];
            $model = $assignments[$key]['model'] ?? '';

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $numPredict = (int) ($config['num_predict'] ?? 0);
            [$inTokens, $outTokens] = $tokens[$key]
                ?? [self::EST_INPUT_TOKENS, $numPredict > 0 ? $numPredict : self::EST_OUTPUT_TOKENS];

            $provider = PaidModel::provider($model);
            $cost = $provider === null
                ? 0.0
                : LlmUsage::costFor($provider, PaidModel::strip($model), $inTokens, $outTokens);

            $tools = array_values(array_map('strval', (array) ($config['tools'] ?? [])));
            $hint = trim((string) ($node['name'] ?? '').' '.(string) ($node['role'] ?? ''));

            $out[] = [
                'key' => $key,
                'name' => (string) ($node['name'] ?? ''),
                'old_model' => (string) ($node['model'] ?? ''),
                'new_model' => $model,
                // Какво реално ще върви: за '' (локален авто) — резолвнатият таг.
                'display_model' => $model !== '' ? $model : $selector->selectModel((string) $node['type'], $hint, $tools),
                'reason' => (string) ($assignments[$key]['reason'] ?? ''),
                'est_cost' => round($cost, 6),
            ];
            $total += $cost;
        }

        return response()->json([
            'ok' => true,
            'nodes' => $out,
            'total_usd' => round($total, 4),
            'basis' => $tokens === [] ? 'assumptions' : 'last_run',
        ]);
    }

    /** 'low'|'medium'|'high'|'ultra'|'custom' от заявката, или null. */
    private function modelLevelInput(Request $request): ?string
    {
        $level = (string) $request->input('model_level', '');

        return $level === 'custom' || ModelLevel::tryFrom($level) !== null ? $level : null;
    }

    /**
     * Per-node real token usage from the version's most recent completed run —
     * the best basis for the relevel cost estimate. node_key → [in, out].
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private function lastRunTokens(Flow $flow, ?int $versionId): array
    {
        $version = $versionId ? $flow->versions()->find($versionId) : $flow->activeVersion;
        if (! $version) {
            return [];
        }

        $run = $version->flowRuns()->where('status', 'completed')->latest('id')->first();
        if (! $run) {
            return [];
        }

        $map = [];
        foreach ($run->nodeRuns()->get(['node_key', 'prompt_tokens', 'completion_tokens']) as $nodeRun) {
            if ((int) $nodeRun->prompt_tokens > 0 || (int) $nodeRun->completion_tokens > 0) {
                $map[(string) $nodeRun->node_key] = [(int) $nodeRun->prompt_tokens, (int) $nodeRun->completion_tokens];
            }
        }

        return $map;
    }
}
