<?php

namespace App\Services;

use App\Models\FlowVersion;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY place that understands the Drawflow export format.
 *
 * Normalizes a raw Drawflow export (`editor.export()`) into our library-agnostic
 * flow_nodes + flow_edges model, scoped to ONE FlowVersion — every version owns
 * its own materialized node/edge rows. If the graph library is ever swapped,
 * only this class changes.
 *
 * Drawflow export shape:
 *   { "drawflow": { "Home": { "data": {
 *       "<id>": {
 *           "id": <id>, "name": "<module>", "pos_x": .., "pos_y": ..,
 *           "data": { type, name, config, prompt_template, ... },   // our payload
 *           "outputs": { "output_1": { "connections": [ { "node": "<id>", "output": "input_1" } ] } },
 *           "inputs":  { "input_1":  { "connections": [ ... ] } }
 *       }
 *   } } } }
 *
 * Note: in a Drawflow output connection, `node` is the TARGET node id and
 * `output` is the TARGET's input port name (Drawflow's confusing naming). We
 * derive edges from outputs only, so each connection is counted exactly once.
 */
class GraphNormalizer
{
    private const NODE_DATA_FIELDS = [
        'name', 'role', 'type', 'icon', 'prompt_template', 'system_prompt', 'model',
        'output_language', 'output_tone', 'output_style', 'output_format', 'output_role',
    ];

    /**
     * Persist the Drawflow export into the version's flow_nodes + flow_edges.
     */
    public function sync(FlowVersion $version, array $export): void
    {
        [$nodes, $edges] = $this->parse($export);

        DB::transaction(function () use ($version, $nodes, $edges) {
            $keptKeys = [];

            foreach ($nodes as $node) {
                $version->nodes()->updateOrCreate(
                    ['node_key' => $node['node_key']],
                    $node + ['flow_id' => $version->flow_id],
                );
                $keptKeys[] = $node['node_key'];
            }

            // Drop nodes removed in the editor (cascades their historical node_runs).
            $version->nodes()->whereNotIn('node_key', $keptKeys ?: [''])->delete();

            // Edges have no run-history references — recreate wholesale.
            $version->edges()->delete();
            foreach ($edges as $edge) {
                $version->edges()->create($edge + ['flow_id' => $version->flow_id]);
            }
        });
    }

    /**
     * Pure parse step (no DB) — also reused by tests and validation.
     *
     * @return array{0: list<array<string,mixed>>, 1: list<array<string,string>>}
     */
    public function parse(array $export): array
    {
        $modules = $export['drawflow'] ?? [];

        $nodes = [];
        $edges = [];

        foreach ($modules as $module) {
            $data = $module['data'] ?? [];
            $boundaryKeys = [];

            foreach ($data as $raw) {
                $payload = $raw['data'] ?? [];
                if ($this->isBoundaryPayload($payload)) {
                    $boundaryKeys[] = (string) ($raw['id'] ?? '');
                }
            }

            foreach ($data as $raw) {
                $nodeKey = (string) ($raw['id'] ?? '');
                if ($nodeKey === '') {
                    continue;
                }

                $payload = $raw['data'] ?? [];
                if ($this->isBoundaryPayload($payload)) {
                    continue;
                }

                $node = [
                    'node_key' => $nodeKey,
                    'pos_x' => (int) ($raw['pos_x'] ?? 0),
                    'pos_y' => (int) ($raw['pos_y'] ?? 0),
                    'config' => $payload['config'] ?? [],
                    'is_active' => (bool) ($payload['is_active'] ?? true),
                ];

                foreach (self::NODE_DATA_FIELDS as $field) {
                    $node[$field] = $payload[$field] ?? null;
                }

                // Sensible fallbacks: Drawflow module name doubles as type/name.
                $node['type'] = $node['type'] ?: ($raw['name'] ?? 'content_agent');
                $node['name'] = $node['name'] ?: ($raw['name'] ?? 'Възел');

                $nodes[] = $node;

                foreach ($raw['outputs'] ?? [] as $fromPort => $port) {
                    foreach ($port['connections'] ?? [] as $conn) {
                        $targetKey = (string) ($conn['node'] ?? '');
                        if (in_array($targetKey, $boundaryKeys, true)) {
                            continue;
                        }

                        $edges[] = [
                            'from_node_key' => $nodeKey,
                            'to_node_key' => $targetKey,
                            'from_port' => (string) $fromPort,
                            'to_port' => (string) ($conn['output'] ?? 'input_1'),
                        ];
                    }
                }
            }
        }

        return [$nodes, $edges];
    }

    private function isBoundaryPayload(array $payload): bool
    {
        if ((bool) ($payload['is_boundary'] ?? false)) {
            return true;
        }

        return in_array($payload['type'] ?? null, ['flow_start', 'flow_end'], true);
    }
}
