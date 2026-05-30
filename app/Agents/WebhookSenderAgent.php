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

        // Validate URL scheme (SSRF prevention)
        $parsed = parse_url($webhookUrl);
        if (!in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
            return '⚠ Invalid webhook_url — must start with http:// or https://.';
        }

        // Build payload: by default only send flow_run_id, flow_topic, and input
        $payload = [
            'flow_run_id' => $agentRun->flow_run_id,
            'flow_topic'  => $context['flow_topic'] ?? null,
            'input'       => $agentRun->input,
        ];

        // If send_full_context is enabled, include the full context
        if ($config['send_full_context'] === true) {
            $payload['context'] = $context;
        }

        try {
            $response = Http::timeout(10)->post($webhookUrl, $payload);

            if ($response->successful()) {
                return "✓ Webhook delivered successfully.";
            }

            return "⚠ Webhook delivery failed: HTTP {$response->status()}.";
        } catch (\Exception $e) {
            return "⚠ Webhook delivery error: " . $e->getMessage();
        }
    }
}
