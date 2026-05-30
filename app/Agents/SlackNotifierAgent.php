<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;
use Illuminate\Support\Facades\Http;

class SlackNotifierAgent extends BaseAgent
{
    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $config     = $agent->config ?? [];
        $webhookUrl = $config['webhook_url'] ?? null;

        if (! $webhookUrl) {
            return '⚠ No webhook_url configured in agent config.';
        }

        // Generate a brief Slack-formatted summary from the context
        $summaryPrompt = "Write a brief 2-3 line Slack notification summarising the following content. "
            . "Use plain text suitable for Slack. Be concise and informative.\n\n"
            . $agentRun->input;

        $summary = $this->chat($agent, $summaryPrompt);

        try {
            $response = Http::timeout(10)->post($webhookUrl, ['text' => $summary]);

            if ($response->successful()) {
                return "✓ Slack notification sent successfully to {$webhookUrl}.";
            }

            return "⚠ Slack notification failed: HTTP {$response->status()} from {$webhookUrl}.";
        } catch (\Exception $e) {
            return "⚠ Slack notification error: " . $e->getMessage();
        }
    }
}
