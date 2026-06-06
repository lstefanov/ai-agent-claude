<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

/**
 * Routing node: picks ONE outgoing branch based on the input so the executor
 * can skip the branches that are not taken.
 *
 *   config.branches = [
 *     { "port": "output_1", "label": "Позитивни", "when": "когато ревютата са добри" },
 *     { "port": "output_2", "label": "Негативни", "when": "когато има оплаквания" },
 *   ]
 *
 * The chosen port is exposed via chosenBranch(); NodeExecutorService records it
 * in the run context and GraphFlowExecutor prunes edges whose from_port differs.
 * With fewer than two branches (or when the model can't decide) NO choice is
 * recorded and every branch runs — a safe, backward-compatible fallback.
 */
class DecisionAgent extends BaseAgent
{
    private ?string $chosenPort = null;

    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $branches = $this->branches($agent);

        if (count($branches) < 2) {
            // Nothing to route — behave as a plain pass-through node.
            return $this->chat($agent, $agentRun->input);
        }

        $options = [];
        foreach ($branches as $b) {
            $options[] = $b['port'].': '.$b['label'].($b['when'] !== '' ? ' — '.$b['when'] : '');
        }

        $keys = implode(', ', array_column($branches, 'port'));
        $instruction = $agentRun->input
            ."\n\n--- ВЪЗМОЖНИ КЛОНОВЕ ---\n".implode("\n", $options)
            ."\n\nПрецени входа и избери НАЙ-ПОДХОДЯЩИЯ клон. Върни САМО ключа на клона ("
            .$keys.') на първия ред, после кратка обосновка на нов ред.';

        $response = $this->chat($agent, $instruction);

        $this->chosenPort = $this->matchPort($response, $branches);

        return $response;
    }

    /** The output port the router picked, or null when undecided / not routing. */
    public function chosenBranch(): ?string
    {
        return $this->chosenPort;
    }

    /** @return array<int, array{port: string, label: string, when: string}> */
    private function branches(Agent $agent): array
    {
        $out = [];
        $i = 0;
        foreach ((array) ($agent->config['branches'] ?? []) as $b) {
            $i++;
            if (! is_array($b)) {
                continue;
            }
            $label = trim((string) ($b['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $out[] = [
                'port' => trim((string) ($b['port'] ?? '')) ?: ('output_'.$i),
                'label' => $label,
                'when' => trim((string) ($b['when'] ?? '')),
            ];
        }

        return $out;
    }

    /** Find the branch the model picked: explicit port key first, then label. */
    private function matchPort(string $response, array $branches): ?string
    {
        $lower = mb_strtolower($response);

        foreach ($branches as $b) {
            if (str_contains($lower, mb_strtolower($b['port']))) {
                return $b['port'];
            }
        }
        foreach ($branches as $b) {
            if (str_contains($lower, mb_strtolower($b['label']))) {
                return $b['port'];
            }
        }

        return null;
    }
}
