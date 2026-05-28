<?php

namespace App\Agents;

use App\Models\Agent;
use App\Services\ComfyUIService;
use App\Services\OllamaService;

class AgentFactory
{
    public function __construct(
        private OllamaService $ollama,
        private ComfyUIService $comfyui
    ) {}

    public function make(Agent $agent): BaseAgent
    {
        return match ($agent->type) {
            'image_prompt'  => new ImagePromptAgent($this->ollama, $this->comfyui),
            'qa_verifier'   => new QaVerifierAgent($this->ollama),
            'analyzer'      => new AnalyzerAgent($this->ollama),
            'researcher'    => new ResearcherAgent($this->ollama),
            'summarizer'    => new SummarizerAgent($this->ollama),
            'decision'      => new DecisionAgent($this->ollama),
            'publisher'     => new PublisherAgent($this->ollama),
            'translator'    => new TranslatorAgent($this->ollama),
            'orchestrator'  => new OrchestratorAgent($this->ollama),
            default         => new ContentAgent($this->ollama),  // content_bg, content_en, unknown
        };
    }
}
