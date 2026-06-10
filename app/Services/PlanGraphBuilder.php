<?php

namespace App\Services;

use App\Models\AgentTemplate;

/**
 * Server-side counterpart of the builder's applyGeneratedGraph(): converts a
 * FINALIZED planner agents array (uid/depends_on shape, post
 * AgentGeneratorService::finalizePlannedAgents) into a raw Drawflow export —
 * the same shape GraphNormalizer::parse() consumes and editor.import() renders.
 *
 * Lets flow versions be created and ACTIVATED without a browser round-trip
 * (A/B page "Запази" saves a template straight from the plan cache).
 *
 * Layout constants and the layered longest-path algorithm mirror the builder
 * JS (builder.blade.php applyGeneratedGraph) so server-built and client-built
 * graphs look identical.
 */
class PlanGraphBuilder
{
    private const COL_X0 = 260;

    private const COL_W = 340;

    private const ROW_Y0 = 80;

    private const ROW_H = 260;

    private const START_ID = 1;

    private const END_ID = 2;

    /**
     * @param  array<int, array<string, mixed>>  $agents
     * @param  int|null  $companyId  company whose AgentTemplate icons apply (system icons otherwise)
     * @return array<string, mixed> Drawflow export
     */
    public function build(array $agents, ?int $companyId = null): array
    {
        // qa_verifier agents are not graph nodes — the step-QA gate synthesizes
        // a verifier from each gated node's own qa config (same rule as the JS).
        $chain = array_values(array_filter(
            $agents,
            fn ($a) => ! (($a['is_verifier'] ?? false) || ($a['type'] ?? '') === 'qa_verifier'),
        ));

        if ($chain === []) {
            return $this->export([]);
        }

        // depends_on (uid) → predecessor index list; sequential fallback when
        // no agent declares dependencies.
        $uidToIdx = [];
        foreach ($chain as $i => $agent) {
            if (! empty($agent['uid'])) {
                $uidToIdx[(string) $agent['uid']] = $i;
            }
        }
        $anyDeps = collect($chain)->contains(fn ($a) => ! empty($a['depends_on']));
        $preds = [];
        foreach ($chain as $i => $agent) {
            if (! $anyDeps) {
                $preds[$i] = $i === 0 ? [] : [$i - 1];

                continue;
            }
            $preds[$i] = collect((array) ($agent['depends_on'] ?? []))
                ->map(fn ($uid) => $uidToIdx[(string) $uid] ?? null)
                ->filter(fn ($j) => $j !== null && $j !== $i)
                ->values()
                ->all();
        }

        // Layered layout: depth = longest path from a root.
        $depth = array_fill(0, count($chain), 0);
        foreach ($chain as $pass => $unused) {
            foreach ($preds as $i => $ps) {
                foreach ($ps as $p) {
                    $depth[$i] = max($depth[$i], $depth[$p] + 1);
                }
            }
        }

        $icons = $this->templateIcons($companyId);

        $rowInCol = [];
        $nodes = [];
        $yCenters = [];
        foreach ($chain as $i => $agent) {
            $col = $depth[$i];
            $row = $rowInCol[$col] = ($rowInCol[$col] ?? 0) + 1;
            $x = self::COL_X0 + $col * self::COL_W;
            $y = self::ROW_Y0 + ($row - 1) * self::ROW_H;
            $yCenters[] = $y;

            $nodes[$i] = $this->agentNode($i + 3, $agent, $x, $y, $icons);
        }

        $maxCol = max($depth);
        $yMid = (int) round((min($yCenters) + max($yCenters)) / 2);

        $entries = [
            self::START_ID => $this->boundaryNode('start', 60, $yMid),
            self::END_ID => $this->boundaryNode('end', self::COL_X0 + ($maxCol + 1) * self::COL_W, $yMid),
        ];
        foreach ($nodes as $node) {
            $entries[$node['id']] = $node;
        }

        // Edges: preds → node; roots ← Старт; sinks → Край. Both connection
        // sides are written — Drawflow import needs the inputs mirror, while
        // GraphNormalizer derives edges from outputs only.
        $hasSucc = array_fill(0, count($chain), false);
        foreach ($preds as $ps) {
            foreach ($ps as $p) {
                $hasSucc[$p] = true;
            }
        }

        foreach ($chain as $i => $agent) {
            $targetId = $nodes[$i]['id'];
            if ($preds[$i] === []) {
                $this->connect($entries, self::START_ID, $targetId);
            } else {
                foreach ($preds[$i] as $p) {
                    $this->connect($entries, $nodes[$p]['id'], $targetId);
                }
            }
            if (! $hasSucc[$i]) {
                $this->connect($entries, $targetId, self::END_ID);
            }
        }

        return $this->export($entries);
    }

    /** @param array<int, array<string, mixed>> $entries */
    private function export(array $entries): array
    {
        // String keys, like a real editor.export() — JSON object, not array.
        $data = [];
        foreach ($entries as $id => $entry) {
            $data[(string) $id] = $entry;
        }

        return ['drawflow' => ['Home' => ['data' => $data]]];
    }

    private function connect(array &$entries, int $fromId, int $toId): void
    {
        // Drawflow's confusing naming: in an output connection `output` is the
        // TARGET's input port; in an input connection `input` is the SOURCE's
        // output port.
        $entries[$fromId]['outputs']['output_1']['connections'][] = [
            'node' => (string) $toId,
            'output' => 'input_1',
        ];
        $entries[$toId]['inputs']['input_1']['connections'][] = [
            'node' => (string) $fromId,
            'input' => 'output_1',
        ];
    }

    private function boundaryNode(string $boundary, int $x, int $y): array
    {
        $definition = $boundary === 'start'
            ? ['type' => 'flow_start', 'name' => 'Старт', 'icon' => '▶', 'inputs' => [], 'outputs' => ['output_1' => ['connections' => []]]]
            : ['type' => 'flow_end', 'name' => 'Край', 'icon' => '■', 'inputs' => ['input_1' => ['connections' => []]], 'outputs' => []];

        return [
            'id' => $boundary === 'start' ? self::START_ID : self::END_ID,
            'name' => $definition['type'],
            'data' => [
                'type' => $definition['type'],
                'name' => $definition['name'],
                'icon' => $definition['icon'],
                'boundary' => $boundary,
                'is_boundary' => true,
                'is_active' => false,
                'locked' => true,
                'config' => [],
            ],
            'class' => 'flow-boundary',
            'html' => '',
            'typenode' => false,
            'inputs' => $definition['inputs'],
            'outputs' => $definition['outputs'],
            'pos_x' => $x,
            'pos_y' => $y,
        ];
    }

    /** Port of the builder's generatedToNodeData() + normalizeNodeData() defaults. */
    private function agentNode(int $id, array $agent, int $x, int $y, array $icons): array
    {
        $type = (string) ($agent['type'] ?? 'content_bg');
        $name = (string) ($agent['name'] ?? 'Агент');
        $role = (string) ($agent['role'] ?? $agent['output_description'] ?? $name);

        $promptTemplate = trim((string) ($agent['prompt_template'] ?? '')) !== ''
            ? $agent['prompt_template']
            : ($role ?: 'Извърши задачата на агент "'.$name.'" и върни резултата.');
        $systemPrompt = trim((string) ($agent['system_prompt'] ?? '')) !== ''
            ? $agent['system_prompt']
            : ('Ти си агент "'.$name.'". '.($role !== '' ? $role.' ' : '').'Отговаряй на български език.');

        $config = is_array($agent['config'] ?? null) ? $agent['config'] : [];
        $qa = is_array($config['qa'] ?? null) ? $config['qa'] : [];
        $config['qa'] = [
            'enabled' => (bool) ($qa['enabled'] ?? false),
            'threshold' => (int) ($qa['threshold'] ?? 60),
            'verifier_node_key' => (string) ($qa['verifier_node_key'] ?? ''),
            'custom_prompt' => (string) ($qa['custom_prompt'] ?? ''),
        ] + $qa;

        $icon = (string) ($agent['icon'] ?? '');
        if ($icon === '' || $icon === '🤖') {
            $icon = $icons[$type] ?? '🤖';
        }

        return [
            'id' => $id,
            'name' => $type,
            'data' => [
                'type' => $type,
                'name' => $name,
                'icon' => $icon,
                'role' => $role,
                'model' => (string) ($agent['model'] ?? ''),
                'prompt_template' => $promptTemplate,
                'system_prompt' => $systemPrompt,
                'output_language' => (string) ($agent['output_language'] ?? 'bg'),
                'output_tone' => (string) ($agent['output_tone'] ?? ''),
                'output_style' => (string) ($agent['output_style'] ?? ''),
                'output_format' => (string) ($agent['output_format'] ?? ''),
                'output_role' => (string) ($agent['output_role'] ?? config("agent_types.{$type}.output_role", '')),
                'is_active' => true,
                'config' => $config,
            ],
            'class' => 'flow-node',
            'html' => '',
            'typenode' => false,
            'inputs' => ['input_1' => ['connections' => []]],
            'outputs' => ['output_1' => ['connections' => []]],
            'pos_x' => $x,
            'pos_y' => $y,
        ];
    }

    /** @return array<string, string> type → icon (same source as the builder's templateIcons) */
    private function templateIcons(?int $companyId): array
    {
        return AgentTemplate::query()
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->whereNull('company_id')->where('is_active', true))
                ->when($companyId !== null, fn ($q) => $q->orWhere('company_id', $companyId))
            )
            ->whereNotNull('icon')
            ->orderByRaw('company_id is not null')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('icon', 'type')
            ->all();
    }
}
