<?php
// app/Agents/EmailAgent.php

namespace App\Agents;

use App\Mail\FlowRunReport;
use App\Models\Agent;
use App\Models\AgentRun;
use Illuminate\Support\Facades\Mail;

class EmailAgent extends BaseAgent
{
    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $description = $agent->flow->description ?? '';
        $flowName    = $agent->flow->name ?? 'FlowAI Report';

        $extracted = $this->ollama->chat(
            model: $agent->model,
            systemPrompt: 'Extract only the email address from the user message. Return the email address and nothing else. If there is no email address, return the word "none".',
            userMessage: $description,
            options: $this->buildOptions($agent)
        );

        preg_match('/[\w.+\-]+@[\w\-]+(?:\.[\w\-]+)+/', $extracted, $matches);
        $email = $matches[0] ?? '';

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '⚠ Не е намерен имейл адрес в описанието на Flow-а.';
        }

        Mail::to($email)->send(new FlowRunReport(
            reportContent: $agentRun->input,
            flowName: $flowName,
            flowRunId: $agentRun->flow_run_id,
        ));

        return "✓ Репортът е изпратен до {$email}";
    }
}
