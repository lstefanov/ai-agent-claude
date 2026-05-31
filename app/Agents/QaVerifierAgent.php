<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

class QaVerifierAgent extends BaseAgent
{
    private const DEFAULT_QA_THRESHOLD = 60;

    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        // Build a clean content summary from all previous agents
        $content = $this->buildContentForReview($context);

        // IMPORTANT: We bypass buildOutputInstructions() completely.
        // The QA verifier must ALWAYS respond in English — score parsing depends on it.
        // Small models (phi3.5, phi3:mini) fail hard with language instructions in Romanian/other.
        $response = $this->ollama->chat(
            model:        $agent->model,
            systemPrompt: $this->buildQaSystemPrompt($agent),
            userMessage:  "Review the following content and provide your quality score:\n\n{$content}",
            options:      array_merge($this->buildOptions($agent), [
                'temperature' => 0.1, // Low temperature for consistent, structured output
            ])
        );

        $score = $this->parseScore($response);

        // Store score in tokens_used field (repurposed for QA score storage)
        $agentRun->update(['tokens_used' => $score]);

        return $response;
    }

    public function extractScore(AgentRun $agentRun): int
    {
        return (int) $agentRun->tokens_used;
    }

    // ──────────────────────────────────────────────────────────────────────
    // System prompt — ENGLISH ONLY, structured JSON output.
    // ──────────────────────────────────────────────────────────────────────
    private function buildQaSystemPrompt(Agent $agent): string
    {
        $threshold = $agent->qa_threshold ?? self::DEFAULT_QA_THRESHOLD;
        $customCriteria = trim($agent->system_prompt ?? '');

        if ($customCriteria !== '') {
            return <<<PROMPT
You are a quality assurance reviewer. Your job is to evaluate the output against specific criteria.

CRITICAL RULES:
1. Respond ONLY in ENGLISH. Never use any other language in your response.
2. The content being reviewed may be in Bulgarian or other languages — that is CORRECT and EXPECTED.
3. Focus ONLY on the criteria below.

EVALUATION CRITERIA:
{$customCriteria}

Passing score threshold: {$threshold}/100

Scoring guide:
- 90-100: Excellent — all criteria fully met
- 75-89:  Good — most criteria met with minor gaps
- 60-74:  Average — some criteria missing, needs improvement
- Below 60: Poor — major criteria not met

Respond with ONLY this JSON object (no other text):
{
  "score": <integer 0-100>,
  "verdict": "<pass|fail>",
  "strengths": ["<1-2 short strengths in English>"],
  "improvements": ["<1-2 specific actionable improvements in English>"]
}
PROMPT;
        }

        return <<<PROMPT
You are a quality assurance reviewer for social media marketing content. Your job is to score posts for engagement, clarity, and effectiveness.

CRITICAL RULES:
1. Respond ONLY in ENGLISH. Never use any other language in your response.
2. The post content may be in Bulgarian, Russian, or any other language — that is CORRECT and EXPECTED. Do NOT penalize non-English content. Bulgarian posts for Bulgarian audiences are perfect.
3. Do NOT suggest translating the post into English. The language of the post is intentional.
4. Focus on: emotional impact, call to action, hashtag quality, structure, engagement potential.

Passing score threshold: {$threshold}/100

Scoring guide:
- 90-100: Excellent — strong hook, clear CTA, great hashtags, motivational tone
- 75-89:  Good — solid content with minor improvements possible
- 60-74:  Average — missing key elements (CTA or hook), needs improvement
- Below 60: Poor — unclear message, no CTA, or missing key social media elements

Respond with ONLY this JSON object (no other text):
{
  "score": <integer 0-100>,
  "verdict": "<pass|fail>",
  "strengths": ["<1-2 short strengths in English>"],
  "improvements": ["<1-2 specific actionable improvements in English>"]
}
PROMPT;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Build a clean text of the content to be reviewed.
    // Focus on the final/caption agent output, fallback to all context.
    // ──────────────────────────────────────────────────────────────────────
    private function buildContentForReview(array $context): string
    {
        if (empty($context)) {
            return 'No content to review.';
        }

        // Strip system/alias keys — only look at actual agent outputs
        $systemKeys = ['company_description', 'company_name', 'company_industry', 'input', 'topic'];
        $agentContext = array_filter(
            $context,
            fn ($k) => ! in_array($k, $systemKeys, true),
            ARRAY_FILTER_USE_KEY
        );

        if (empty($agentContext)) {
            return 'No content to review.';
        }

        // Prefer the last agent's output (most likely the final post)
        $lastKey    = array_key_last($agentContext);
        $lastOutput = $agentContext[$lastKey];

        // If it looks like a social media post (has hashtags or is short enough)
        if (str_contains($lastOutput, '#') || mb_strlen($lastOutput) < 2000) {
            return "Agent: {$lastKey}\n\n{$lastOutput}";
        }

        // Otherwise include all context (truncated)
        $parts = [];
        foreach ($agentContext as $name => $output) {
            $parts[] = "=== {$name} ===\n" . mb_substr($output, 0, 400);
        }

        return implode("\n\n", $parts);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Parse score from model response — try JSON first, then regex fallback.
    // ──────────────────────────────────────────────────────────────────────
    private function parseScore(string $response): int
    {
        // Try to parse JSON block
        if (preg_match('/\{[\s\S]*?"score"\s*:\s*(\d{1,3})[\s\S]*?\}/i', $response, $m)) {
            return min(100, max(0, (int) $m[1]));
        }

        // Try full JSON decode
        $decoded = json_decode(trim($response), true);
        if (is_array($decoded) && isset($decoded['score'])) {
            return min(100, max(0, (int) $decoded['score']));
        }

        // Fallback: find "score": N pattern
        if (preg_match('/"score"\s*:\s*(\d{1,3})/i', $response, $m)) {
            return min(100, max(0, (int) $m[1]));
        }

        // Last resort: first standalone number 0-100
        if (preg_match('/\b(100|\d{1,2})\b/', $response, $m)) {
            return min(100, max(0, (int) $m[1]));
        }

        return 0;
    }
}
