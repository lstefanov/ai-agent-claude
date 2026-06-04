<?php

use App\Models\Agent;
use App\Models\Flow;
use App\Services\GraphNormalizer;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration: build a proper Граф (Старт → агенти → Край) for every flow
 * that still relies on the legacy sequential `agents` pipeline.
 *
 * The app moved from linear execution to the parallel DAG engine
 * (GraphFlowExecutor), which reads flow_nodes + flow_edges. Legacy flows have
 * agents but no graph, so they cannot run on the new engine and show only the
 * Start/End boundaries in the editor.
 *
 * For each flow with agents we assemble a Drawflow export that chains the agents
 * (ordered by `agents.order`) one after another, then push it through the
 * existing GraphNormalizer::sync — the single source of truth for the
 * Drawflow <-> flow_nodes/flow_edges mapping. This guarantees the persisted
 * graph is byte-for-byte what a manual save from the editor would produce.
 *
 * Decisions:
 *  - Scope: every flow with >= 1 agent; existing graphs are overwritten.
 *  - QA verifiers (is_verifier / type qa_verifier) are skipped — they are not
 *    standalone steps in the chain.
 */
return new class extends Migration
{
    public function up(): void
    {
        $normalizer = app(GraphNormalizer::class);

        Flow::has('agents')->get()->each(function (Flow $flow) use ($normalizer) {
            $agents = $flow->agents()
                ->where('is_verifier', false)
                ->where('type', '!=', 'qa_verifier')
                ->orderBy('order')
                ->orderBy('id')
                ->get();

            if ($agents->isEmpty()) {
                return; // nothing to chain
            }

            $export = $this->buildDrawflowExport($agents);

            $flow->update(['graph_layout' => $export]);
            $normalizer->sync($flow, $export);
        });
    }

    public function down(): void
    {
        // Data migration. Graphs may be hand-edited after this runs, so there is
        // no safe automatic rollback. Clear a flow's graph manually if needed.
    }

    /**
     * Assemble a Drawflow export: Старт → agent → … → agent → Край.
     *
     * @param \Illuminate\Support\Collection<int, Agent> $agents
     */
    private function buildDrawflowExport($agents): array
    {
        $startId = 1;
        $endId = $agents->count() + 2;       // start(1) + agents + end
        $firstAgentId = 2;

        $data = [];

        // Старт — boundary, single output into the first agent.
        $data[(string) $startId] = $this->boundaryNode(
            id: $startId,
            type: 'flow_start',
            name: 'Старт',
            icon: '▶',
            boundary: 'start',
            posX: 60,
            posY: 120,
            inputs: [],
            outputs: ['output_1' => [['node' => (string) $firstAgentId, 'output' => 'input_1']]],
        );

        // Агенти — chained in order.
        foreach ($agents->values() as $index => $agent) {
            $nodeId = $firstAgentId + $index;
            $prevId = $nodeId - 1;            // start for the first agent
            $nextId = $nodeId + 1;            // end for the last agent

            $data[(string) $nodeId] = [
                'id' => $nodeId,
                'name' => $agent->type,
                'data' => $this->agentPayload($agent),
                'class' => 'flow-node',
                'html' => '<div>'.e($agent->name).'</div>',
                'typenode' => false,
                'inputs' => [
                    'input_1' => ['connections' => [['node' => (string) $prevId, 'input' => 'output_1']]],
                ],
                'outputs' => [
                    'output_1' => ['connections' => [['node' => (string) $nextId, 'output' => 'input_1']]],
                ],
                'pos_x' => 160 + ($index + 1) * 330,
                'pos_y' => 180,
            ];
        }

        // Край — boundary, single input from the last agent.
        $lastAgentId = $endId - 1;
        $data[(string) $endId] = $this->boundaryNode(
            id: $endId,
            type: 'flow_end',
            name: 'Край',
            icon: '■',
            boundary: 'end',
            posX: 160 + ($agents->count() + 1) * 330,
            posY: 120,
            inputs: ['input_1' => [['node' => (string) $lastAgentId, 'input' => 'output_1']]],
            outputs: [],
        );

        return ['drawflow' => ['Home' => ['data' => $data]]];
    }

    /**
     * Map an Agent to the Drawflow `data` payload. Fields mirror
     * GraphNormalizer::NODE_DATA_FIELDS so the sync round-trips losslessly.
     */
    private function agentPayload(Agent $agent): array
    {
        return [
            'type' => $agent->type,
            'name' => $agent->name,
            'icon' => $agent->icon,
            'role' => $agent->role,
            'prompt_template' => $agent->prompt_template,
            'system_prompt' => $agent->system_prompt,
            'model' => $agent->model,
            'config' => $agent->config ?? [],
            'output_language' => $agent->output_language,
            'output_tone' => $agent->output_tone,
            'output_style' => $agent->output_style,
            'output_format' => $agent->output_format,
            'output_role' => $agent->output_role,
            'is_active' => (bool) $agent->is_active,
            'is_boundary' => false,
        ];
    }

    /**
     * Build a Старт/Край boundary node. `connections` arrays are wrapped under
     * the Drawflow `connections` key to match editor.export() output.
     */
    private function boundaryNode(
        int $id,
        string $type,
        string $name,
        string $icon,
        string $boundary,
        int $posX,
        int $posY,
        array $inputs,
        array $outputs,
    ): array {
        $wrap = fn (array $ports) => array_map(
            fn (array $connections) => ['connections' => $connections],
            $ports,
        );

        return [
            'id' => $id,
            'name' => $type,
            'data' => [
                'type' => $type,
                'name' => $name,
                'icon' => $icon,
                'boundary' => $boundary,
                'is_boundary' => true,
                'is_active' => false,
                'locked' => true,
                'config' => [],
            ],
            'class' => 'flow-boundary',
            'html' => '<div>'.e($icon).' '.e($name).'</div>',
            'typenode' => false,
            'inputs' => $wrap($inputs),
            'outputs' => $wrap($outputs),
            'pos_x' => $posX,
            'pos_y' => $posY,
        ];
    }
};
