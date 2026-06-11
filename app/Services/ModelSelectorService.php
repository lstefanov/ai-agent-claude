<?php

namespace App\Services;

use App\Models\LlmModel;

class ModelSelectorService
{
    /**
     * Profiles whose candidates are tuned for writing Bulgarian prose. Agents
     * resolving to these profiles must stay on the local BgGPT stack — paid
     * pins are stripped by AgentGeneratorService for bg-language output.
     */
    private const BG_WRITING_PROFILES = ['bg_writer', 'report'];

    /**
     * Data-gathering tools: a `custom` agent carrying any of these is a
     * researcher, not a generic analyst, and gets the research profile.
     */
    private const RESEARCH_TOOLS = [
        'web_search', 'pro_search', 'people_search', 'scrape_page',
        'crawl_site', 'discover_urls', 'google_reviews',
    ];

    /**
     * Profile → ordered candidate Ollama tags (best first). Every tag must exist
     * in the LlmModel catalogue so pull progress can be tracked. Candidates may be
     * not-yet-installed: selectModel() returns the ideal one (pulled on demand),
     * while resolveRunnable() restricts to what is actually installed.
     */
    private array $profiles = [
        // Long-form Bulgarian writing (social posts, emails). Capable models first —
        // BgGPT 3.0 12B (Gemma 3, 128K ctx) е най-добрият BG модел изобщо; the
        // tiny todorov/bggpt:latest collapsed on long context (run 71: 53K input → "Благо").
        'bg_writer' => ['todorov/bggpt:Gemma-3-12B-IT-Q5_K_M', 's_emanuilov/BgGPT-v1.0:9b', 'qwen3:14b', 'gemma4:12b'],
        // Detailed reports synthesised from large multi-agent input — needs a big
        // context window: BgGPT 3.0 12B държи 128K и пише най-естествения български.
        'report' => ['todorov/bggpt:Gemma-3-12B-IT-Q5_K_M', 'qwen3:14b', 's_emanuilov/BgGPT-v1.0:27b', 's_emanuilov/BgGPT-v1.0:9b', 'gemma4:12b'],
        'research' => ['qwen3:8b', 'mistral-nemo', 'qwen3:14b', 'qwen2.5:7b'],
        'analysis' => ['qwen3:14b', 'gemma4:12b', 'qwen2.5:14b', 'mistral-nemo'],
        // QA must emit clean JSON reliably — the 2.6b model could not. qwen3:4b
        // is the first SMALL model that passes; capable fallbacks after it.
        'qa' => ['qwen3:4b', 'qwen3:8b', 'qwen2.5:14b'],
        'en_writer' => ['gemma4:12b', 'qwen3:8b', 'llama3.1:8b'],
        'image_prompt' => ['mistral', 'mistral-nemo'],
        'translate' => ['aya-expanse:8b', 'todorov/bggpt:Gemma-3-12B-IT-Q5_K_M', 'qwen3:8b', 'qwen2.5:14b'],
        'code' => ['qwen2.5-coder:14b', 'qwen2.5-coder:7b'],
        'vision' => ['qwen2.5vl:7b', 'llama3.2-vision:11b'],
        'utility' => ['qwen3:4b', 'gemma4:12b', 'mistral'],
    ];

    /**
     * Explicit agent-type → profile mapping. Types not listed here fall back to a
     * profile derived from the type's output_role in config/agent_types.php.
     */
    private array $typeToProfile = [
        // researchers / data gatherers
        // site_context & review_analyzer extract structured facts and must NOT invent —
        // qwen2.5:14b (analysis profile) is far more reliable than mistral-nemo here.
        'site_context' => 'analysis',
        'review_analyzer' => 'analysis',
        'researcher' => 'research',
        'deep_researcher' => 'research',
        'people_researcher' => 'research',
        'multi_researcher' => 'research',
        'trend_researcher' => 'research',
        'competitor_profiler' => 'research',
        'keyword_extractor' => 'research',
        'scraper' => 'research',

        // analyzers / processors
        'analyzer' => 'analysis',
        'swot_builder' => 'analysis',
        'data_extractor' => 'analysis',
        'document_ocr' => 'analysis',
        'classifier' => 'analysis',
        'sentiment_analyzer' => 'analysis',
        'summarizer' => 'analysis',
        'decision' => 'analysis',
        'lead_scorer' => 'analysis',
        'price_optimizer' => 'analysis',
        'budget_analyzer' => 'analysis',
        'persona_builder' => 'analysis',
        'customer_segmenter' => 'analysis',
        'brand_voice_checker' => 'analysis',
        'influencer_finder' => 'analysis',

        // bulgarian body writers
        'content_bg' => 'bg_writer',
        'writer' => 'bg_writer',
        'caption_writer' => 'bg_writer',
        'hook_writer' => 'bg_writer',
        'ad_copywriter' => 'bg_writer',
        'report_writer' => 'report',
        'report_composer' => 'report',
        'newsletter_writer' => 'bg_writer',
        'email_composer' => 'bg_writer',
        'seo_writer' => 'bg_writer',
        'offer_builder' => 'bg_writer',
        'bg_text_corrector' => 'bg_writer',

        // english writers
        'content_en' => 'en_writer',

        // specialised profiles
        'translator' => 'translate',
        'image_prompt' => 'image_prompt',
        'image_describer' => 'vision',
        'vision' => 'vision',
        'code' => 'code',

        // quality
        'qa_verifier' => 'qa',
        'verifier' => 'qa',

        // hidden utilities
        'formatter' => 'utility',
        'publisher' => 'utility',
        'orchestrator' => 'utility',
        'webhook_sender' => 'utility',
        'slack_notifier' => 'utility',
        'google_sheets_writer' => 'utility',
        'airtable_writer' => 'utility',
    ];

    /**
     * The ideal model for an agent. May be a not-yet-installed tag — the caller is
     * expected to pull it on demand. Use resolveRunnable() when you need a tag that
     * is guaranteed to be installed right now.
     */
    public function selectModel(string $agentType, ?string $hint = null, array $tools = []): string
    {
        $candidates = $this->candidatesFor($agentType, $hint, $tools);

        return $candidates[0] ?? $this->globalFallback();
    }

    /**
     * Like selectModel(), but returns the first candidate that is actually
     * installed. Falls back to a guaranteed-installed model so a run never points
     * at a missing tag.
     */
    public function resolveRunnable(string $agentType, ?string $hint = null, array $tools = []): string
    {
        $installed = $this->installedTags();

        foreach ($this->candidatesFor($agentType, $hint, $tools) as $tag) {
            if (in_array($tag, $installed, true)) {
                return $tag;
            }
        }

        return $this->globalFallback();
    }

    /**
     * True when the agent type resolves to a Bulgarian-prose profile — these
     * stay on local BgGPT regardless of what the planner asked for.
     */
    public function isBgWritingType(string $agentType): bool
    {
        return in_array($this->profileForType($agentType), self::BG_WRITING_PROFILES, true);
    }

    /**
     * True when the agent type resolves to the vision profile — image inputs
     * need a local multimodal model, so these are never cloud-pinned.
     */
    public function isVisionType(string $agentType): bool
    {
        return $this->profileForType($agentType) === 'vision';
    }

    /**
     * Ordered candidate tags for an agent, honouring description/name heuristics.
     *
     * @return array<int, string>
     */
    private function candidatesFor(string $agentType, ?string $hint, array $tools = []): array
    {
        $profile = $this->profileForType($agentType);

        if ($agentType === 'custom' && array_intersect($tools, self::RESEARCH_TOOLS) !== []) {
            $profile = 'research';
        }

        // Description/name heuristics can override the type-based profile.
        if ($hint !== null && $hint !== '') {
            if (preg_match('/изображен|снимк|image|vision|визуал/iu', $hint)) {
                $profile = $agentType === 'image_prompt' ? 'image_prompt' : 'vision';
            } elseif (preg_match('/превод|translat/iu', $hint)) {
                $profile = 'translate';
            } elseif (preg_match('/\bкод\b|програм|\bcode\b|coding/iu', $hint)) {
                $profile = 'code';
            }
        }

        return $this->profiles[$profile] ?? $this->profiles['utility'];
    }

    public function profileForType(string $agentType): string
    {
        if (isset($this->typeToProfile[$agentType])) {
            return $this->typeToProfile[$agentType];
        }

        // Derive from the type's output_role when the type is not mapped explicitly.
        $outputRole = config("agent_types.{$agentType}.output_role");

        return match ($outputRole) {
            'body' => 'bg_writer',
            'quality' => 'qa',
            'appendix' => 'utility',
            default => 'analysis', // hidden / unknown → general structured work
        };
    }

    /**
     * @return array<int, string>
     */
    public function installedTags(): array
    {
        return LlmModel::where('is_available', true)
            ->where('is_enabled', true)
            ->pluck('ollama_tag')
            ->all();
    }

    /**
     * A model guaranteed to be installed: the configured fallback tag if it is
     * installed, otherwise any installed+enabled model, otherwise the configured
     * fallback tag as a last resort.
     */
    private function globalFallback(): string
    {
        $installed = $this->installedTags();

        $fallback = (string) config('services.ollama.fallback_model', 'llama3.1:8b');
        if (in_array($fallback, $installed, true)) {
            return $fallback;
        }

        return $installed[0] ?? $fallback;
    }
}
