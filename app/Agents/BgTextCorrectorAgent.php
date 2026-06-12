<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentRun;

class BgTextCorrectorAgent extends BaseAgent
{
    private const SYSTEM_KEYS = [
        'company_description', 'company_name', 'company_industry',
        'input', 'topic', 'flow_topic',
    ];

    public function run(Agent $agent, AgentRun $agentRun, array $context): string
    {
        $textToCorrect = $this->findBodyContent($agent, $context);

        if ($textToCorrect === '') {
            return $this->chat($agent, $agentRun->input);
        }

        $prompt = 'Прегледай следния текст и поправи САМО правописните грешки на кирилица. '
            .'НЕ преструктурирай, НЕ пренаписвай, НЕ добавяй информация. '
            ."Върни точно същия текст с поправен правопис:\n\n"
            .$textToCorrect;

        $corrected = $this->chat($agent, $prompt);

        return $this->guardCorrection($textToCorrect, $corrected);
    }

    /**
     * A spell-corrector must reproduce roughly the same text. If the output
     * deviates wildly in length or introduces placeholder boilerplate, the model
     * rewrote/hallucinated instead of correcting (run 71: "Благо" → a fabricated
     * "отдел Маркетинг 2023" report). In that case keep the original untouched.
     */
    private function guardCorrection(string $original, string $corrected): string
    {
        $corrected = $this->stripPreamble($corrected);

        $origLen = mb_strlen(trim($original));
        $newLen = mb_strlen(trim($corrected));

        if ($origLen === 0 || $newLen === 0) {
            return $original;
        }

        $ratio = $newLen / $origLen;

        if ($ratio < 0.5 || $ratio > 1.6 || $this->looksLikePlaceholder($corrected)) {
            return $original;
        }

        return $corrected;
    }

    /** Strip a leading meta-preamble like "Разбира се, ето коригирания текст:" + separators. */
    private function stripPreamble(string $text): string
    {
        $text = preg_replace('/^\s*(разбира се[^\n]*|ето[^\n]*коригиран[^\n]*|коригиран[^\n:]*:)\s*/iu', '', $text) ?? $text;

        return trim(preg_replace('/^\s*-{3,}\s*/u', '', $text) ?? $text);
    }

    /**
     * Pick the body text to correct from the upstream context.
     *
     * Primary signal is the GRAPH, not the content: a predecessor tagged
     * output_role='body' is the body to correct — even if it embeds an
     * SD-prompt/image section (a composite final post). Sniffing the content
     * instead (run 106) wrongly discarded the whole body because it contained
     * "**SD Prompt:**", leaving the model an empty document. Content filters
     * stay only as a defensive fallback when no body role is available, and a
     * safety net guarantees we never return '' when a substantial body exists.
     */
    private function findBodyContent(Agent $agent, array $context): string
    {
        $roles = (array) ($agent->config['predecessor_roles'] ?? []);

        // Substantial, non-system candidates keyed by label (so roles can be looked up).
        $candidates = [];
        foreach ($context as $key => $value) {
            if (in_array($key, self::SYSTEM_KEYS, true)) {
                continue;
            }
            if (! is_string($value) || mb_strlen($value) < 50) {
                continue;
            }
            $candidates[$key] = $value;
        }

        if ($candidates === []) {
            return '';
        }

        // Primary: correct the predecessor the graph marks as body. Role is
        // authoritative — no content-sniffing on this branch.
        $body = array_filter(
            $candidates,
            fn ($label) => ($roles[$label] ?? null) === 'body',
            ARRAY_FILTER_USE_KEY
        );
        if ($body !== []) {
            return (string) end($body);
        }

        // Secondary (no body role — unknown/legacy graph): defensively skip
        // appendix-looking content (image prompts, hashtag lists, QA JSON).
        $filtered = array_filter(
            $candidates,
            fn ($value) => ! $this->isImageOutput($value)
                && ! $this->isHashtagList($value)
                && ! $this->isQaOutput($value)
        );
        if ($filtered !== []) {
            return (string) end($filtered);
        }

        // Safety net: never hand the model an empty document when a body exists.
        return $this->longest($candidates);
    }

    /** @param  array<string,string>  $values */
    private function longest(array $values): string
    {
        $longest = '';
        foreach ($values as $value) {
            if (mb_strlen($value) > mb_strlen($longest)) {
                $longest = $value;
            }
        }

        return $longest;
    }

    private function isImageOutput(string $content): bool
    {
        return str_contains($content, '![')
            || str_contains($content, '**SD Prompt:**')
            || str_contains($content, 'Stable Diffusion');
    }

    private function isHashtagList(string $content): bool
    {
        $words = preg_split('/\s+/', trim($content), -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < 3) {
            return false;
        }
        $hashtagCount = count(array_filter($words, fn ($w) => str_starts_with($w, '#')));

        return ($hashtagCount / count($words)) > 0.4;
    }

    private function isQaOutput(string $content): bool
    {
        return (bool) preg_match('/^\s*\{.*"score"\s*:/s', $content);
    }
}
