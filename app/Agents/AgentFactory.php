<?php

namespace App\Agents;

use App\Agents\EmailAgent;
use App\Agents\MultiResearcherAgent;
use App\Agents\DeepResearcherAgent;
use App\Agents\Tools\BraveSearchTool;
use App\Agents\Tools\WebScraperTool;
use App\Models\Agent;
use App\Services\CrawlService;
use App\Services\BraveSearchService;
use App\Services\ComfyUIService;
use App\Services\OllamaService;

class AgentFactory
{
    public function __construct(
        private OllamaService $ollama,
        private ComfyUIService $comfyui,
        private BraveSearchService $braveSearch,
    ) {}

    public function make(Agent $agent): BaseAgent
    {
        return match ($agent->type) {
            'image_prompt'  => new ImagePromptAgent($this->ollama, $this->comfyui),
            'qa_verifier'   => new QaVerifierAgent($this->ollama),
            'analyzer'      => new AnalyzerAgent($this->ollama),
            'researcher'    => new ResearcherAgent($this->ollama, [new BraveSearchTool($this->braveSearch)]),
            'multi_researcher' => new MultiResearcherAgent($this->ollama, [new BraveSearchTool($this->braveSearch)]),
            'deep_researcher'  => new DeepResearcherAgent($this->ollama, [
                new BraveSearchTool($this->braveSearch),
                new WebScraperTool(new CrawlService()),
            ]),
            'summarizer'    => new SummarizerAgent($this->ollama),
            'decision'      => new DecisionAgent($this->ollama),
            'publisher'     => new PublisherAgent($this->ollama),
            'translator'    => new TranslatorAgent($this->ollama),
            'orchestrator'  => new OrchestratorAgent($this->ollama),
            'email'         => new EmailAgent($this->ollama),
            default         => new ContentAgent($this->ollama),
        };
    }
}
