<?php

namespace App\Services;

use App\Models\FlowRun;
use App\Support\ReasoningStripper;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Assembles the final, user-facing result of a flow run from the individual
 * agent outputs (e.g. FB posts + titles + hashtags).
 *
 * Strategy: the content is assembled DETERMINISTICALLY and verbatim — so no
 * agent output can ever be lost or reworded. A strong LLM is then used ONLY to
 * reformat that assembly into a nicer, sectioned layout. A verbatim guard
 * checks the LLM kept every part word-for-word; if it deviated (paraphrased,
 * dropped or invented), we discard the LLM result and keep the deterministic
 * assembly. This gives nice formatting when possible and guaranteed-complete
 * content always.
 */
class FinalComposerService
{
    public function __construct(private OllamaService $ollama) {}

    /**
     * @return array{output: string, model: ?string}
     */
    public function compose(FlowRun $flowRun): array
    {
        [$bodyParts, $appendixParts] = $this->collectParts($flowRun);

        // Deduped deliverables: near-identical parts collapse, parts already
        // contained in a larger part (assembler ingredients) are dropped.
        $effectiveParts = $this->effectiveParts($bodyParts, $appendixParts);

        // Nothing to compose.
        if (count($effectiveParts) === 0) {
            return ['output' => '', 'model' => null];
        }

        // Single deliverable — no assembly needed, return it verbatim.
        if (count($effectiveParts) === 1) {
            return ['output' => $effectiveParts[0], 'model' => null];
        }

        // Deterministic, verbatim assembly is the source of truth — the robust
        // safety net when the LLM is unavailable or deviates.
        $deterministic = implode("\n\n---\n\n", $effectiveParts);

        // LLM formatting pass — cosmetic only, guarded by a verbatim check.
        $model = (string) config('services.ollama.composer_model');

        try {
            $formatted = $this->formatWithLlm($model, $deterministic);
            $formatted = ReasoningStripper::strip($formatted);

            // Accept the formatted version only if it (1) kept every part verbatim
            // (no dropping), (2) did not balloon with invented content (no adding),
            // and (3) introduced no placeholder boilerplate. The verbatim guard alone
            // misses ADDED hallucinations — run 71's final result was a fabricated
            // "отдел Маркетинг" report with example.com contacts.
            if (trim($formatted) !== ''
                && $this->allPartsPresentVerbatim($formatted, $effectiveParts)
                && $this->withinLengthBudget($formatted, $deterministic)
                && ! $this->containsPlaceholder($formatted)) {
                return ['output' => $formatted, 'model' => $model];
            }
        } catch (Throwable) {
            // fall through to the deterministic assembly
        }

        // Safety net — guaranteed complete, verbatim content.
        return ['output' => $deterministic, 'model' => null];
    }

    // Every part must keep at least this fraction of its sampled verbatim
    // fragments in the formatted output, otherwise the LLM is rejected.
    private const VERBATIM_COVERAGE_THRESHOLD = 0.8;

    // The format pass may only add section headings / whitespace — so the
    // normalized text must not grow beyond this ratio, or the LLM invented content.
    private const MAX_GROWTH_RATIO = 1.25;

    /** Reject a format pass that grew the content well beyond cosmetic headings. */
    private function withinLengthBudget(string $formatted, string $deterministic): bool
    {
        $base = mb_strlen($this->normalize($deterministic));
        if ($base === 0) {
            return true;
        }

        return mb_strlen($this->normalize($formatted)) / $base <= self::MAX_GROWTH_RATIO;
    }

    /** Detect hallucinated placeholder boilerplate (checked on raw, un-normalized text). */
    private function containsPlaceholder(string $text): bool
    {
        return (bool) preg_match(
            '/example\.com|@example|lorem ipsum|отдел\s+„?\s*Маркетинг|Иван\s+Вазов["“”]?\s*123|0?2\s*123\s*45\s*67/iu',
            $text
        );
    }

    /**
     * Verify every part survived the LLM formatting pass word-for-word.
     *
     * Because we feed the LLM the already-complete assembly and ask only for
     * reformatting, any paraphrasing/dropping shows up as missing verbatim
     * fragments. We check exact (normalized) fragments of each part — this does
     * not suffer the shared-vocabulary false-positive, because the fragments are
     * specific multi-word chunks of the actual block, not topic words.
     *
     * @param  list<string>  $parts
     */
    private function allPartsPresentVerbatim(string $output, array $parts): bool
    {
        $haystack = $this->normalize($output);

        foreach ($parts as $part) {
            $fragments = $this->sampleFragments($this->normalize($part));
            if (count($fragments) === 0) {
                continue;
            }

            $present = 0;
            foreach ($fragments as $fragment) {
                if (mb_strpos($haystack, $fragment) !== false) {
                    $present++;
                }
            }

            if ($present / count($fragments) < self::VERBATIM_COVERAGE_THRESHOLD) {
                return false; // the LLM reworded or dropped this part
            }
        }

        return true;
    }

    /**
     * Evenly-spaced ~50-char fragments of a normalized string (up to 5).
     *
     * @return list<string>
     */
    private function sampleFragments(string $normalized): array
    {
        $len = mb_strlen($normalized);
        if ($len === 0) {
            return [];
        }

        $size = 50;
        if ($len <= $size) {
            return [$normalized];
        }

        $count = min(5, intdiv($len, $size));
        $fragments = [];
        $step = intdiv($len - $size, max(1, $count - 1));

        for ($i = 0; $i < $count; $i++) {
            $fragments[] = mb_substr($normalized, $i * $step, $size);
        }

        return $fragments;
    }

    /**
     * Lowercase, strip emojis/punctuation, collapse whitespace — so verbatim
     * presence checks are robust to light formatting differences.
     */
    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        // Keep letters/digits/whitespace (any script), drop everything else.
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Collect completed body/appendix node outputs in execution order.
     * Hidden (research) and quality (QA) outputs are excluded.
     *
     * @return array{0: list<array{name: string, output: string}>, 1: list<array{name: string, output: string}>}
     */
    private function collectParts(FlowRun $flowRun): array
    {
        /** @var Collection $runs */
        $runs = $flowRun->nodeRuns()
            ->with('flowNode')
            ->where('status', 'completed')
            ->get()
            ->filter(fn ($r) => $r->flowNode && is_string($r->output) && trim($r->output) !== '')
            ->sortBy(fn ($r) => $r->id); // execution order (waves create runs in order)

        $body = [];
        $appendix = [];

        foreach ($runs as $run) {
            $role = $run->flowNode->effectiveOutputRole();
            $part = ['name' => $run->flowNode->name, 'output' => trim($run->output)];

            if ($role === 'body') {
                $body[] = $part;
            } elseif ($role === 'appendix') {
                $appendix[] = $part;
            }
            // 'hidden' and 'quality' are intentionally skipped.
        }

        return [array_values($body), array_values($appendix)];
    }

    /**
     * Ask the LLM to ONLY reformat the already-complete assembly — never to
     * rewrite, summarise or add content. The output is guarded afterwards.
     */
    private function formatWithLlm(string $model, string $assembly): string
    {
        $system = <<<'SYS'
Ти си редактор по форматиране. Получаваш готов финален текст за социални мрежи и трябва само да подобриш ВИЗУАЛНОТО му оформление.

КРИТИЧНО ВАЖНО:
- КОПИРАЙ целия текст ДОСЛОВНО — всеки пост, заглавие и хаштаг, дума по дума, без нито една промяна в съдържанието.
- НЕ пренаписвай, НЕ перифразирай, НЕ съкращавай, НЕ добавяй и НЕ махай текст.

РАЗРЕШЕНО ти е САМО:
- Да добавиш ясни заглавия на секции (напр. „Публикации", „Заглавия", „Хаштагове").
- Да подредиш секциите и да добавиш разделители/празни редове за по-добра четимост.

Върни САМО оформения текст на български, без обяснения и без think бележки.
SYS;

        return $this->ollama->chat(
            model: $model,
            systemPrompt: $system,
            userMessage: "Оформи следния готов текст:\n\n".$assembly,
            // num_predict -1: the final result must NEVER be cut off by a token cap.
            // num_ctx large enough to SEE the whole assembly (otherwise the model
            // only formats the part it sees and silently drops the rest).
            options: ['temperature' => 0.2, 'num_predict' => -1, 'num_ctx' => 16384, 'http_timeout' => 180],
        );
    }

    /**
     * The parts that actually appear in the deterministic assembly (after
     * near-identical body parts are collapsed and parts contained in a larger
     * part are dropped), used by the verbatim guard.
     *
     * @param  list<array{name: string, output: string}>  $bodyParts
     * @param  list<array{name: string, output: string}>  $appendixParts
     * @return list<string>
     */
    private function effectiveParts(array $bodyParts, array $appendixParts): array
    {
        $effectiveBodies = [];

        foreach ($bodyParts as $part) {
            $output = $part['output'];
            $replaced = false;

            foreach ($effectiveBodies as $i => $existing) {
                if ($this->nearlyIdentical($existing, $output)) {
                    $effectiveBodies[$i] = $output;
                    $replaced = true;
                    break;
                }
            }

            if (! $replaced) {
                $effectiveBodies[] = $output;
            }
        }

        return $this->dropContainedParts(array_merge(
            array_values($effectiveBodies),
            array_map(fn ($p) => $p['output'], $appendixParts)
        ));
    }

    // Lengths within this ratio count as "near equal" for the containment
    // tie-break (mutual containment keeps the LATER part).
    private const CONTAINMENT_LENGTH_TOLERANCE = 0.02;

    /**
     * Drop parts whose content is already contained verbatim in a longer (or,
     * for near-equal lengths, later) surviving part — e.g. hook/copy/headline
     * outputs that a downstream assembler node re-emits inside the compiled
     * post. Without this the final result repeats the same content under
     * different section headings (run 81).
     *
     * Longest parts are decided first, so every dropped part is contained in a
     * part that actually survives — content can never be lost.
     *
     * @param  list<string>  $parts
     * @return list<string>
     */
    private function dropContainedParts(array $parts): array
    {
        $norms = array_map(fn ($p) => $this->normalize($p), $parts);
        $lens = array_map('mb_strlen', $norms);

        $order = array_keys($parts);
        usort($order, fn ($a, $b) => ($lens[$b] <=> $lens[$a]) ?: ($a <=> $b));

        $dropped = [];

        foreach ($order as $i) {
            foreach (array_keys($parts) as $j) {
                if ($j === $i || isset($dropped[$j])) {
                    continue;
                }

                $nearEqual = abs($lens[$j] - $lens[$i]) <= max($lens[$i], $lens[$j]) * self::CONTAINMENT_LENGTH_TOLERANCE;
                if ($lens[$j] <= $lens[$i] && ! ($nearEqual && $j > $i)) {
                    continue;
                }

                if ($this->containedIn($norms[$i], $norms[$j])) {
                    $dropped[$i] = true;
                    break;
                }
            }
        }

        return array_values(array_filter(
            $parts,
            fn ($k) => ! isset($dropped[$k]),
            ARRAY_FILTER_USE_KEY
        ));
    }

    /** Whether enough sampled fragments of the needle appear verbatim in the haystack (both normalized). */
    private function containedIn(string $normNeedle, string $normHaystack): bool
    {
        $fragments = $this->sampleFragments($normNeedle);
        if (count($fragments) === 0) {
            return false;
        }

        $present = 0;
        foreach ($fragments as $fragment) {
            if (mb_strpos($normHaystack, $fragment) !== false) {
                $present++;
            }
        }

        return $present / count($fragments) >= self::VERBATIM_COVERAGE_THRESHOLD;
    }

    /**
     * Two strings are "nearly identical" when they're close in length and
     * share a high character-level similarity (corrected vs raw version).
     */
    private function nearlyIdentical(string $a, string $b): bool
    {
        $la = mb_strlen($a);
        $lb = mb_strlen($b);
        if ($la === 0 || $lb === 0) {
            return false;
        }

        // Only compare when lengths are within ~25% of each other.
        if (min($la, $lb) / max($la, $lb) < 0.75) {
            return false;
        }

        similar_text($a, $b, $percent);

        return $percent >= 75.0;
    }
}
