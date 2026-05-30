<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;
use Illuminate\Support\Facades\Http;

class WebhookSenderAgent extends BaseAgent
{
    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $config     = $agent->config ?? [];
        $webhookUrl = $config['webhook_url'] ?? null;

        if (! $webhookUrl) {
            return '⚠ No webhook_url configured in agent config.';
        }

        $payload = [
            'flow_run_id' => $agentRun->flow_run_id,
            'flow_topic'  => $context['flow_topic'] ?? null,
            'context'     => $context,
        ];

        try {
            $response = Http::timeout(10)->post($webhookUrl, $payload);

            if ($response->successful()) {
                return "✓ Webhook delivered successfully to {$webhookUrl} (HTTP {$response->status()}).";
            }

            return "⚠ Webhook delivery failed: HTTP {$response->status()} from {$webhookUrl}.";
        } catch (\Exception $e) {
            return "⚠ Webhook delivery error: " . $e->getMessage();
        }
    }
}
