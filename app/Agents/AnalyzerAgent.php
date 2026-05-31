<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Support\PricingOutputMetrics;

class AnalyzerAgent extends BaseAgent
{
    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $output = $this->chat($agent, $agentRun->input);
        $guard = $this->qualityGuard($agent);

        if (! $guard || $this->passesQualityGuard($output, $guard)) {
            return $output;
        }

        $retryInput = $agentRun->input."\n\nQUALITY RETRY:\n"
            .'The previous extraction was too thin. Return ONLY the required markdown table, with at least '
            .$guard['min_priced_rows'].' rows with numeric prices. Ignore rows without concrete numeric prices.';

        $maxRetries = $guard['max_retries'];
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $output = $this->chat($agent, $retryInput);
            if ($this->passesQualityGuard($output, $guard)) {
                break;
            }
        }

        return $output;
    }

    /**
     * @return array{min_priced_rows: int, max_retries: int}|null
     */
    private function qualityGuard(Agent $agent): ?array
    {
        $guard = ($agent->config ?? [])['quality_guard'] ?? null;
        if (! is_array($guard)) {
            return null;
        }

        return [
            'min_priced_rows' => max(0, (int) ($guard['min_priced_rows'] ?? 0)),
            'max_retries' => min(3, max(0, (int) ($guard['max_retries'] ?? 0))),
        ];
    }

    /**
     * @param  array{min_priced_rows: int, max_retries: int}  $guard
     */
    private function passesQualityGuard(string $output, array $guard): bool
    {
        if ($guard['min_priced_rows'] <= 0) {
            return true;
        }

        return (PricingOutputMetrics::fromOutput($output)['priced_rows'] ?? 0) >= $guard['min_priced_rows'];
    }
}
