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
        // ── 1. Ask Ollama to write a Stable Diffusion text prompt ─────────
        $userMessage = $this->buildPrompt($agent, $context);
        $rawText     = $this->chat($agent, $userMessage);
        $sdPrompt    = $this->extractSdPrompt($rawText);

        Log::info("[ImagePromptAgent] SD prompt: {$sdPrompt}");

        // ── 2. If ComfyUI is not running — return the text prompt only ────
        if (!$this->comfyui->isAvailable()) {
            return implode("\n\n", [
                '**🎨 Image Prompt (ComfyUI не е стартиран)**',
                '```',
                $sdPrompt,
                '```',
                '_Стартирай ComfyUI и стартирай flow отново за реална генерация._',
            ]);
        }

        // ── 3. Build workflow and submit ──────────────────────────────────
        try {
            $workflow = $this->comfyui->buildWorkflow($sdPrompt);
            $promptId = $this->comfyui->generate($workflow);

            Log::info("[ImagePromptAgent] ComfyUI prompt_id: {$promptId}");

            // ── 4. Wait for the image (up to 3 minutes) ───────────────────
            $imageUrl = $this->comfyui->getResult($promptId, 180);

            if ($imageUrl) {
                return implode("\n\n", [
                    "![Generated Image]({$imageUrl})",
                    "**Prompt:** {$sdPrompt}",
                    "**Path:** storage/app/public/generated/{$promptId}.png",
                ]);
            }

            return "**Image generation timed out.**\n\nPrompt ID: `{$promptId}`\n\nPrompt used:\n```\n{$sdPrompt}\n```";

        } catch (\Throwable $e) {
            Log::error("[ImagePromptAgent] ComfyUI error: " . $e->getMessage());

            return implode("\n\n", [
                '**⚠ ComfyUI Error:** ' . $e->getMessage(),
                "**Prompt:** {$sdPrompt}",
            ]);
        }
    }

    /**
     * Strip any preamble/explanation the model adds and return only
     * the clean comma-separated Stable Diffusion prompt.
     */
    private function extractSdPrompt(string $raw): string
    {
        // Remove markdown code blocks
        $clean = preg_replace('/```[a-z]*\s*([\s\S]*?)```/i', '$1', $raw);

        // Remove lines that look like explanations ("Here is the prompt:", etc.)
        $lines = array_filter(
            array_map('trim', explode("\n", $clean)),
            fn($line) => $line !== ''
                && !preg_match('/^(here|this|below|prompt:|image prompt:)/i', $line)
                && !str_starts_with($line, '#')
        );

        // Prefer the longest line (most likely the actual prompt)
        usort($lines, fn($a, $b) => strlen($b) - strlen($a));

        $prompt = reset($lines) ?: trim($raw);

        // Trim to 400 chars (SD has a token limit)
        return mb_substr($prompt, 0, 400);
    }
}
