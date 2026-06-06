<?php

namespace App\Http\Controllers;

use App\Models\Flow;
use App\Services\GraphNormalizer;
use App\Services\PlanLibraryService;
use App\Support\GraphTopology;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class FlowGraphController extends Controller
{
    public function __construct(
        private GraphNormalizer $normalizer,
        private PlanLibraryService $planLibrary,
    ) {}

    /**
     * Persist a Drawflow export: raw layout in flows.graph_layout (for 1:1 reload)
     * + normalized flow_nodes/flow_edges.
     *
     * Saving IS the plan approval: the snapshot lands in the plan library as a
     * 'candidate' and becomes a few-shot example once a run succeeds.
     */
    public function store(Request $request, Flow $flow): JsonResponse
    {
        $graph = $request->input('graph');

        if (! is_array($graph)) {
            return response()->json(['ok' => false, 'error' => 'Невалиден граф.'], 422);
        }

        try {
            DB::transaction(function () use ($graph, $flow) {
                $flow->update(['graph_layout' => $graph]);
                $this->normalizer->sync($flow, $graph);
            });
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'error' => 'Грешка при запис на графа.'], 500);
        }

        // Plan-library snapshot is best-effort — it must never fail the save.
        try {
            $this->planLibrary->captureApprovedPlan($flow->fresh());
        } catch (Throwable $e) {
            report($e);
        }

        return response()->json(['ok' => true]);
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
            $nodeKeys = $flow->nodes()->pluck('node_key')->all();
            $edgeList = $flow->edges()->get(['from_node_key', 'to_node_key'])
                ->map(fn ($e) => ['from' => $e->from_node_key, 'to' => $e->to_node_key])
                ->all();
        }

        $result = GraphTopology::analyze($nodeKeys, $edgeList);

        return response()->json([
            'ok' => $result['ok'],
            'errors' => $result['errors'],
            'wave_count' => count($result['waves']),
        ]);
    }
}
