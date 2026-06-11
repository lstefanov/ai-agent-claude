<?php

namespace App\Agents;

use App\Agents\Tools\BraveSearchTool;
use App\Agents\Tools\DocumentOcrTool;
use App\Agents\Tools\GoogleReviewsTool;
use App\Agents\Tools\PeopleSearchTool;
use App\Agents\Tools\PerplexitySearchTool;
use App\Agents\Tools\SiteCrawlerTool;
use App\Agents\Tools\SiteDiscoveryTool;
use App\Agents\Tools\WebScraperTool;
use App\Models\Agent;
use App\Services\AgentLoop;
use App\Services\BraveSearchService;
use App\Services\ComfyUIService;
use App\Services\CrawlService;
use App\Services\GooglePlacesService;
use App\Services\MistralOcrService;
use App\Services\OllamaService;
use App\Services\PerplexitySearchService;

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
            'image_prompt' => new ImagePromptAgent($this->ollama, $this->comfyui),
            'qa_verifier' => new QaVerifierAgent($this->ollama),
            'analyzer' => new AnalyzerAgent($this->ollama),
            'site_context' => new SiteContextAgent($this->ollama, [
                new WebScraperTool(new CrawlService),
                new SiteDiscoveryTool(new CrawlService),
            ]),
            'researcher' => new ResearcherAgent($this->ollama, [new BraveSearchTool($this->braveSearch)]),
            'multi_researcher' => new MultiResearcherAgent($this->ollama, [new BraveSearchTool($this->braveSearch)]),
            'deep_researcher' => new DeepResearcherAgent($this->ollama, [
                new BraveSearchTool($this->braveSearch),
                new PerplexitySearchTool(new PerplexitySearchService),
                new WebScraperTool(new CrawlService),
                new SiteCrawlerTool(new CrawlService),
                new SiteDiscoveryTool(new CrawlService),
                new DocumentOcrTool(new MistralOcrService),
            ], new CrawlService),
            'summarizer' => new SummarizerAgent($this->ollama),
            'report_composer' => new ReportComposerAgent($this->ollama),
            'decision' => new DecisionAgent($this->ollama),
            'publisher' => new PublisherAgent($this->ollama),
            'translator' => new TranslatorAgent($this->ollama),
            'orchestrator' => new OrchestratorAgent($this->ollama),
            'email' => new EmailAgent($this->ollama),
            'trend_researcher' => new TrendResearcherAgent($this->ollama, [new BraveSearchTool($this->braveSearch)]),
            'competitor_profiler' => new CompetitorProfilerAgent($this->ollama, [
                new BraveSearchTool($this->braveSearch),
                new PerplexitySearchTool(new PerplexitySearchService),
                new WebScraperTool(new CrawlService),
            ]),
            'people_researcher' => new PeopleResearcherAgent($this->ollama, [
                new PeopleSearchTool(new PerplexitySearchService),
                new PerplexitySearchTool(new PerplexitySearchService),
            ]),
            'document_ocr' => new DocumentOcrAgent($this->ollama, [
                new DocumentOcrTool(new MistralOcrService),
            ]),
            'review_analyzer' => new ReviewAnalyzerAgent($this->ollama, [
                new GoogleReviewsTool(new GooglePlacesService),
                new BraveSearchTool($this->braveSearch),
                new WebScraperTool(new CrawlService),
            ]),
            'keyword_extractor' => new KeywordExtractorAgent($this->ollama, [new BraveSearchTool($this->braveSearch)]),
            'webhook_sender' => new WebhookSenderAgent($this->ollama),
            'slack_notifier' => new SlackNotifierAgent($this->ollama),
            'hashtag_generator' => new HashtagGeneratorAgent($this->ollama),
            'bg_text_corrector' => new BgTextCorrectorAgent($this->ollama),
            // Pause nodes never reach the factory — NodeExecutorService pauses
            // the run before instantiating an agent. Defensive guard only.
            'human_approval' => throw new \RuntimeException('human_approval nodes pause the run — they are never executed as agents.'),
            // Planner-composed "on the fly" agent: gets the full tool belt, but only
            // runs the tools whitelisted in its config['tools'] (see GenericAgent).
            // The AgentLoop powers its agentic mode on paid models.
            'custom' => new GenericAgent($this->ollama, [
                new BraveSearchTool($this->braveSearch),
                new PerplexitySearchTool(new PerplexitySearchService),
                new PeopleSearchTool(new PerplexitySearchService),
                new WebScraperTool(new CrawlService),
                new SiteCrawlerTool(new CrawlService),
                new SiteDiscoveryTool(new CrawlService),
                new DocumentOcrTool(new MistralOcrService),
                new GoogleReviewsTool(new GooglePlacesService),
            ], app(AgentLoop::class)),
            // All remaining LLM-only types (swot_builder, report_writer, seo_writer, etc.) use ContentAgent intentionally
            default => new ContentAgent($this->ollama),
        };
    }
}
