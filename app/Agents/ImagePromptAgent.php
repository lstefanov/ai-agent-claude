<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\ComfyUIService;

class ImagePromptAgent extends BaseAgent
{
    public function __construct(
        \App\Services\OllamaService $ollama,
        private ComfyUIService $comfyui
    ) {
        parent::__construct($ollama);
    }

    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $prompt = $this->buildPrompt($agent, $context);

        // Generate a ComfyUI workflow JSON using Ollama
        $workflowJson = $this->chat($agent, $prompt);

        if (!$this->comfyui->isAvailable()) {
            return "ComfyUI недостъпен. Workflow JSON:\n" . $workflowJson;
        }

        $promptId = $this->comfyui->generate($workflowJson);
        $imageUrl = $this->comfyui->getResult($promptId);

        return $imageUrl ?? "Изображението не беше генерирано. Prompt ID: {$promptId}";
    }
}
