<?php

namespace App\Http\Controllers;

use App\Models\Flow;
use App\Services\FlowVersionService;
use App\Services\GeneratorService;
use App\Services\GraphNormalizer;
use App\Support\GraphTopology;
use App\Support\PlannerPhases;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        try {
            if ($flow->versions()->doesntExist()) {
                $version = $this->versions->createFromLayout($flow, $graph, 'Default', true, $agents, [
                    'intent' => $intent,
                    'generator' => $generator ?? ['label' => PlannerPhases::label(app(GeneratorService::class)->resolveAllPhases())],
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
}
