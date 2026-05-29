<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\ComfyUIService;
use Illuminate\Support\Facades\Log;

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
        // ── 1. Build user message from context ────────────────────────────
        $contextSummary = $this->buildContextSummary($context);

        // ── 2. Ask Ollama to write an English Stable Diffusion prompt ──────
        // IMPORTANT: We bypass buildOutputInstructions() completely.
        // SD models REQUIRE English prompts — the agent's output_language is irrelevant here.
        $rawText  = $this->ollama->chat(
            model:        $agent->model,
            systemPrompt: $this->buildSdSystemPrompt(),
            userMessage:  "Create a Stable Diffusion image generation prompt for this social media content:\n\n{$contextSummary}",
            options:      $this->buildOptions($agent)
        );

        $sdPrompt = $this->extractSdPrompt($rawText);

        // If the model refused or returned a refusal message, use a safe fallback
        if ($this->isRefusal($sdPrompt)) {
            Log::warning("[ImagePromptAgent] Model refused. Using fallback prompt.");
            $sdPrompt = $this->buildFallbackPrompt($contextSummary);
        }

        Log::info("[ImagePromptAgent] SD prompt: {$sdPrompt}");

        // ── 3. If ComfyUI is not running — return the text prompt only ─────
        if (! $this->comfyui->isAvailable()) {
            return implode("\n\n", [
                '**🎨 Image Prompt (ComfyUI не е стартиран)**',
                '```',
                $sdPrompt,
                '```',
                '_Стартирай ComfyUI и стартирай flow отново за реална генерация._',
            ]);
        }

        // ── 4. Build workflow and submit ───────────────────────────────────
        try {
            $workflow = $this->comfyui->buildWorkflow($sdPrompt);
            $promptId = $this->comfyui->generate($workflow);

            Log::info("[ImagePromptAgent] ComfyUI prompt_id: {$promptId}");

            // ── 5. Wait for the image (up to 3 minutes) ──────────────────
            $imageUrl = $this->comfyui->getResult($promptId, 180);

            if ($imageUrl) {
                return implode("\n\n", [
                    "![Generated Image]({$imageUrl})",
                    "**SD Prompt:** {$sdPrompt}",
                ]);
            }

            return "**Image generation timed out.**\n\nPrompt ID: `{$promptId}`\n\nPrompt used:\n```\n{$sdPrompt}\n```";

        } catch (\Throwable $e) {
            Log::error('[ImagePromptAgent] ComfyUI error: ' . $e->getMessage());

            return implode("\n\n", [
                '**⚠ ComfyUI Error:** ' . $e->getMessage(),
                "**SD Prompt:** {$sdPrompt}",
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // System prompt specifically crafted for SD prompt generation.
    // Never uses the agent's output_language — SD needs English always.
    // ──────────────────────────────────────────────────────────────────────
    private function buildSdSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a professional Stable Diffusion prompt engineer specializing in photorealistic commercial photography.

Your ONLY job: convert the given topic into one single high-quality English image generation prompt.

STRICT RULES:
1. Output ONLY the prompt — no explanations, no "Here is the prompt:", no intro sentences
2. The prompt MUST be entirely in ENGLISH — Stable Diffusion requires English
3. Default aesthetic: photorealistic, professional photography, 4K, sharp focus, well-lit
4. Be specific about: environment, people (if any), lighting, mood, camera angle
5. Use comma-separated descriptive phrases
6. Avoid artistic styles (impressionist, oil painting, watercolor) unless explicitly mentioned
7. Keep it commercial/marketing quality — suitable for social media ads

Good format example:
modern fitness gym interior, people exercising with weights, bright natural lighting, dynamic action, energetic atmosphere, professional sports photography, 4K, vibrant colors, wide angle lens

Bad examples (DO NOT do these):
- "Here is the Stable Diffusion prompt: ..."
- Writing in Bulgarian, Romanian, or any non-English language
- Starting with "The image shows..." or similar explanations
PROMPT;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Detect a model safety refusal in the generated prompt.
    // ──────────────────────────────────────────────────────────────────────
    private function isRefusal(string $text): bool
    {
        $lower = strtolower($text);
        $markers = [
            "i can't", "i cannot", "i'm unable", "i am unable",
            "harmful", "illegal", "inappropriate", "against my",
            "i apologize", "i'm sorry", "i'm not able",
            "не мога", "не можах",   // Bulgarian refusals
        ];
        foreach ($markers as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }
        // Too short to be a real prompt (< 20 chars after trimming)
        return mb_strlen(trim($text)) < 20;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Build a safe fallback SD prompt from the context keywords.
    // ──────────────────────────────────────────────────────────────────────
    private function buildFallbackPrompt(string $contextSummary): string
    {
        // Try to detect the topic from context keywords
        $lower = mb_strtolower($contextSummary);

        if (str_contains($lower, 'фитнес') || str_contains($lower, 'fitness') || str_contains($lower, 'gym') || str_contains($lower, 'спорт')) {
            return 'modern fitness gym interior, athletic people exercising with weights, bright motivational atmosphere, professional photography, 4K, vibrant colors';
        }
        if (str_contains($lower, 'yoga') || str_contains($lower, 'йога')) {
            return 'yoga studio, woman practicing yoga pose, serene natural lighting, zen atmosphere, professional photography, 4K, calm colors';
        }
        if (str_contains($lower, 'ресторант') || str_contains($lower, 'храна') || str_contains($lower, 'food')) {
            return 'elegant restaurant interior, delicious gourmet food, warm lighting, professional food photography, 4K';
        }
        if (str_contains($lower, 'красота') || str_contains($lower, 'beauty') || str_contains($lower, 'salon')) {
            return 'modern beauty salon, professional makeup artist working, elegant interior, soft lighting, 4K professional photography';
        }

        // Generic commercial fallback
        return 'modern business lifestyle, professional environment, bright natural lighting, motivated people, vibrant colors, professional photography, 4K, high quality';
    }

    // ──────────────────────────────────────────────────────────────────────
    // Summarise previous agent outputs into a compact English context.
    // ──────────────────────────────────────────────────────────────────────
    private function buildContextSummary(array $context): string
    {
        if (empty($context)) {
            return 'No context available.';
        }

        $systemKeys = ['company_description', 'company_name', 'company_industry', 'input', 'topic'];
        $parts = [];

        if (! empty($context['company_name'])) {
            $desc  = $context['company_description'] ?? '';
            $parts[] = "[Company]: {$context['company_name']}" . ($desc ? " — {$desc}" : '');
        }

        foreach ($context as $name => $output) {
            if (in_array($name, $systemKeys, true)) {
                continue;
            }
            $parts[] = "[{$name}]: " . mb_substr(strip_tags($output), 0, 300);
        }

        return implode("\n\n", $parts) ?: 'No context available.';
    }

    // ──────────────────────────────────────────────────────────────────────
    // Extract only the clean SD prompt from model output.
    // ──────────────────────────────────────────────────────────────────────
    private function extractSdPrompt(string $raw): string
    {
        // Remove markdown code blocks
        $clean = preg_replace('/```[a-z]*\s*([\s\S]*?)```/i', '$1', $raw);

        // Split into lines, discard empty and explanatory ones
        $lines = array_filter(
            array_map('trim', explode("\n", $clean)),
            fn ($line) => $line !== ''
                && ! preg_match('/^(here|this|below|prompt:|image prompt:|example|note:|bad|good|—)/i', $line)
                && ! str_starts_with($line, '#')
                && ! str_starts_with($line, '*')
                && mb_strlen($line) > 20
        );

        // Prefer the longest line — it's almost always the actual prompt
        usort($lines, fn ($a, $b) => mb_strlen($b) - mb_strlen($a));

        $prompt = reset($lines) ?: trim($raw);

        // SD token limit ~75 tokens ≈ 400 chars
        return mb_substr($prompt, 0, 400);
    }
}
