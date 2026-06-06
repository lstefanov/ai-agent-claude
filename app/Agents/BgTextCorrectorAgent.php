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
     * Pick the body text to correct from the upstream context: the last
     * substantial text that is not an image output, hashtag list or QA score.
     * This prevents correcting appendix content (hashtags, image prompts, etc.)
     * which would cause duplication in the UI's finalOutput display.
     */
    private function findBodyContent(Agent $agent, array $context): string
    {
        $candidates = [];
        foreach ($context as $key => $value) {
            if (in_array($key, self::SYSTEM_KEYS, true)) {
                continue;
            }
            if (! is_string($value) || mb_strlen($value) < 50) {
                continue;
            }
            if ($this->isImageOutput($value)) {
                continue;
            }
            if ($this->isHashtagList($value)) {
                continue;
            }
            if ($this->isQaOutput($value)) {
                continue;
            }

            $candidates[] = $value;
        }

        return end($candidates) ?: '';
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
