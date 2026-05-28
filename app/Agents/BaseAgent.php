<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\OllamaService;

abstract class BaseAgent
{
    public function __construct(
        protected OllamaService $ollama
    ) {}

    abstract public function run(Agent $agent, AgentRun $agentRun, array $context): string;

    protected function buildPrompt(Agent $agent, array $context): string
    {
        $prompt = $agent->prompt_template;

        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $prompt = str_replace("{{{$key}}}", $value, $prompt);
            }
        }

        return $prompt;
    }

    protected function chat(Agent $agent, string $userMessage): string
    {
        return $this->ollama->chat(
            model: $agent->model,
            systemPrompt: $agent->role,
            userMessage: $userMessage
        );
    }
}
