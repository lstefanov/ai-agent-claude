<?php

namespace App\Services\Execution;

use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowRun;
use App\Models\NodeRun;

/**
 * Context assembly — the heart of the no-information-loss change: input is
 * assembled from direct predecessors' node_run.output (namespaced, never a
 * flat mutating array) and rendered into the node's prompt template.
 */
class NodePromptBuilder
{
    /**
     * @return array{seed: array<string,mixed>, upstream: array<string,string>, upstream_roles: array<string,?string>}
     *                                                                                                                 upstream + upstream_roles keyed by predecessor node NAME (falling back to node_key).
     */
    public function buildNodeInput(FlowRun $flowRun, FlowNode $node): array
    {
        $predecessorKeys = FlowEdge::where('flow_version_id', $node->flow_version_id)
            ->where('to_node_key', $node->node_key)
            ->pluck('from_node_key')
            ->all();

        $upstream = [];
        $upstreamRoles = [];
        if (! empty($predecessorKeys)) {
            $predecessors = FlowNode::where('flow_version_id', $node->flow_version_id)
                ->whereIn('node_key', $predecessorKeys)
                ->get(['node_key', 'name', 'output_role']);
            $names = $predecessors->pluck('name', 'node_key');
            $roles = $predecessors->pluck('output_role', 'node_key');

            $runs = NodeRun::where('flow_run_id', $flowRun->id)
                ->whereIn('node_key', $predecessorKeys)
                ->where('status', 'completed')
                ->get(['node_key', 'output']);

            foreach ($runs as $run) {
                if ($run->output === null || $run->output === '') {
                    continue;
                }
                $label = $names[$run->node_key] ?? $run->node_key;
                $upstream[$label] = $run->output;
                $upstreamRoles[$label] = $roles[$run->node_key] ?? null;
            }
        }

        return [
            'seed' => $flowRun->context['seed'] ?? [],
            'upstream' => $upstream,
            'upstream_roles' => $upstreamRoles,
        ];
    }

    /**
     * Build the user message: seed placeholders, explicit {{node:Name}} refs, and
     * a full named block of every not-yet-inlined predecessor output — so a fan-in
     * node sees ALL of them. Clean output (reasoning stripped) is passed downstream.
     */
    public function renderPrompt(FlowNode $node, array $ctx): string
    {
        $prompt = $node->prompt_template ?? '';
        $original = $prompt;

        foreach ($ctx['seed'] as $k => $v) {
            if (is_string($v)) {
                $prompt = str_replace(['{{'.$k.'}}', '{'.$k.'}'], $v, $prompt);
            }
        }

        // Explicit {{node:Name}} inline substitution — runs for ALL node types including
        // bg_text_corrector (so the prompt template can reference specific predecessors).
        foreach ($ctx['upstream'] as $label => $output) {
            $prompt = str_replace('{{node:'.$label.'}}', $output, $prompt);
        }

        // bg_text_corrector only gets its rendered prompt — no auto-appended context.
        if ($node->type === 'bg_text_corrector') {
            return $prompt;
        }

        // Append all not-yet-inlined upstream outputs as named blocks.
        $blocks = [];
        foreach ($ctx['upstream'] as $label => $output) {
            if (str_contains($original, '{{node:'.$label.'}}')) {
                continue; // already inlined explicitly
            }
            if ($this->promptReferencesKey($original, $label)) {
                continue; // referenced via {{AgentName}} placeholder
            }
            if (str_contains($prompt, $output)) {
                continue; // already present (e.g. inlined via seed)
            }
            $blocks[] = "[{$label}]:\n".$this->handoffText($output);
        }

        if ($blocks) {
            $prompt .= "\n\n--- Context from previous agents ---\n".implode("\n\n", $blocks);
        }

        return $prompt;
    }

    /**
     * Flat context array passed as agent's 3rd argument, mirroring the legacy
     * shape (seed keys + predecessor outputs keyed by name + input alias).
     */
    public function agentContext(array $ctx): array
    {
        $context = $ctx['seed'];

        foreach ($ctx['upstream'] as $label => $output) {
            $context[$label] = $output;
        }

        // `input` alias — union of all upstream outputs.
        if (! empty($ctx['upstream'])) {
            $context['input'] = implode("\n\n", array_values($ctx['upstream']));
        }

        return $context;
    }

    private function promptReferencesKey(string $prompt, string $key): bool
    {
        return str_contains($prompt, '{{'.$key.'}}')
            || str_contains($prompt, '{'.$key.'}');
    }

    private function handoffText(string $output, int $maxChars = 60000): string
    {
        if (mb_strlen($output) <= $maxChars) {
            return $output;
        }

        return mb_substr($output, 0, $maxChars)."\n\n[Truncated after {$maxChars} chars for node handoff.]";
    }
}
