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

        $userMessage = "Review the following content and provide your quality score:\n\n{$content}";
        $userMessage .= $this->buildSourceSection($context);

        $systemPrompt = $this->buildQaSystemPrompt($agent);
        $options = array_merge($this->buildOptions($agent), [
            'temperature' => 0.1, // Low temperature for consistent, structured output
        ]);
        // Direct ollama->chat bypasses BaseAgent::chat's silent-truncation guard —
        // size num_ctx to the prompt or a local model quietly cuts the source block.
        if (! isset($options['num_ctx'])) {
            $options['num_ctx'] = $this->estimateNumCtx($systemPrompt, $userMessage, $options['num_predict'] ?? null);
        }

        // IMPORTANT: We bypass buildOutputInstructions() completely.
        // The QA verifier must ALWAYS respond in English — score parsing depends on it.
        // Small models (phi3.5, phi3:mini) fail hard with language instructions in Romanian/other.
        $response = $this->ollama->chat(
            model: $agent->model,
            systemPrompt: $systemPrompt,
            userMessage: $userMessage,
            options: $options
        );

        $score = $this->parseScore($response);

        // Hand the score back via the transient AgentRun DTO (in-memory only —
        // NodeExecutorService reads it through extractScore()).
        $agentRun->tokens_used = $score;

        return $response;
    }

    public function extractScore(AgentRun $agentRun): int
    {
        return (int) $agentRun->tokens_used;
    }

    /**
     * Pull the verifier's actionable feedback ("improvements"/"weaknesses") out of
     * the JSON response, so a failed QA retry can be told WHAT to fix instead of
     * blindly re-running the identical prompt. Returns '' if nothing usable.
     */
    public function extractFeedback(string $response): string
    {
        $items = [];

        $decoded = json_decode(trim($response), true);
        if (is_array($decoded)) {
            foreach (['improvements', 'weaknesses'] as $key) {
                foreach ((array) ($decoded[$key] ?? []) as $item) {
                    if (($item = trim((string) $item)) !== '') {
                        $items[] = $item;
                    }
                }
            }
        }

        // Regex fallback for non-strict JSON: grab the "improvements" array body.
        if ($items === [] && preg_match('/"improvements"\s*:\s*\[(.*?)\]/s', $response, $m)) {
            foreach (preg_split('/"\s*,\s*"/', trim($m[1])) as $item) {
                if (($item = trim($item, " \t\n\r\"")) !== '') {
                    $items[] = $item;
                }
            }
        }

        return implode('; ', array_slice($items, 0, 4));
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
    // The content under review always arrives as context['input']: the gated
    // node's output for the inline step-QA gate (StepQaGate::verify), or the
    // union of upstream outputs for a dedicated verifier node (agentContext).
    // ──────────────────────────────────────────────────────────────────────
    private function buildContentForReview(array $context): string
    {
        $content = trim((string) ($context['input'] ?? ''));

        if ($content === '') {
            return 'No content to review.';
        }

        // Cap so the local QA model's context window isn't blown on huge reports.
        return mb_substr($content, 0, 12000);
    }

    // ──────────────────────────────────────────────────────────────────────
    // The inline step-QA gate (StepQaGate::verify) also passes the material the
    // gated node sent to its model. Without it, completeness is unjudgeable —
    // a 85-char "no errors found" verdict over a 26K report scored 90/100.
    // ──────────────────────────────────────────────────────────────────────
    private function buildSourceSection(array $context): string
    {
        $source = trim((string) ($context['source_input'] ?? ''));

        if ($source === '') {
            return '';
        }

        $sourceLen = (int) ($context['source_len'] ?? mb_strlen($source));
        $outputLen = mb_strlen(trim((string) ($context['input'] ?? '')));

        return "\n\n--- SOURCE INPUT the node was asked to work from (may be truncated) ---\n"
            .$source
            ."\n--- END SOURCE INPUT ---\n"
            ."\nSource input length: {$sourceLen} chars. Output length: {$outputLen} chars."
            ."\nIf the task was to transform, correct or compose the source material but the output drops or ignores most of it (e.g. a short meta-verdict instead of the full text), score it below the passing threshold.";
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
