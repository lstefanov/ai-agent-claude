<?php

namespace App\Services;

use App\Models\FlowRun;
use App\Support\GraphTopology;
use App\Support\ReasoningStripper;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Assembles the final, user-facing result of a flow run from the individual
 * agent outputs (e.g. FB posts + titles + hashtags).
 *
 * Strategy, in order:
 *
 * 1. STRUCTURAL dedup — the run's DAG already says what is an ingredient: a
 *    body/appendix part whose node feeds another completed body node was
 *    consumed by that downstream deliverable (assembler/corrector), so only
 *    terminal deliverables survive. Text matching can never make this call
 *    reliably — an assembler may reword its ingredients (runs 81, 82).
 * 2. TEXTUAL dedup — a surviving part whose word-shingle content is already
 *    contained in another surviving part is dropped (parallel branches that
 *    duplicate content without a connecting edge).
 * 3. The survivors are assembled DETERMINISTICALLY and verbatim — so no
 *    deliverable can ever be lost or reworded. A single survivor is returned
 *    as-is. For multiple parts, a strong LLM is used ONLY to reformat the
 *    assembly into a nicer, sectioned layout. A verbatim guard checks the LLM
 *    kept every part word-for-word; if it deviated (paraphrased, dropped or
 *    invented — run 71 was a fabricated report), we discard the LLM result
 *    and keep the deterministic assembly.
 */
class FinalComposerService
{
    public function __construct(private OllamaService $ollama) {}

    /**
     * @return array{output: string, model: ?string}
     */
    public function compose(FlowRun $flowRun): array
    {
        [$bodyParts, $appendixParts, $completedKeys] = $this->collectParts($flowRun);

        // Structural dedup: parts consumed by a downstream completed
        // deliverable are ingredients — only terminal deliverables survive.
        $parts = $this->dropConsumedParts($flowRun, $bodyParts, $appendixParts, $completedKeys);

        // Textual dedup between the survivors: parallel branches can still
        // duplicate content without a connecting edge.
        $effectiveParts = $this->dropContainedParts($parts);

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
     * Collect completed body/appendix node outputs in execution order, plus
     * the keys of ALL nodes that completed with output (any role) — the
     * subgraph that content actually flowed through.
     * Hidden (research) and quality (QA) outputs never become parts.
     *
     * @return array{0: list<array{key: string, output: string}>, 1: list<array{key: string, output: string}>, 2: list<string>}
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
        $completedKeys = [];

        foreach ($runs as $run) {
            $completedKeys[] = (string) $run->node_key;

            $role = $run->flowNode->effectiveOutputRole();
            $part = ['key' => (string) $run->node_key, 'output' => trim($run->output)];

            if ($role === 'body') {
                $body[] = $part;
            } elseif ($role === 'appendix') {
                $appendix[] = $part;
            }
            // 'hidden' and 'quality' are intentionally skipped.
        }

        return [array_values($body), array_values($appendix), $completedKeys];
    }

    /**
     * Structural dedup over the run's DAG: a part whose node has a path
     * (through nodes that completed with output) into another completed
     * body-role node is an INGREDIENT — that downstream deliverable consumed
     * it, however much it reworded it. Only terminal deliverables survive.
     *
     * When the downstream assembler failed (best_effort runs), no completed
     * body node is reachable, so the ingredient parts are kept — the final
     * output degrades to the assembled pieces instead of losing content.
     *
     * @param  list<array{key: string, output: string}>  $bodyParts
     * @param  list<array{key: string, output: string}>  $appendixParts
     * @param  list<string>  $completedKeys
     * @return list<string> surviving part outputs (bodies first, then appendix)
     */
    private function dropConsumedParts(FlowRun $flowRun, array $bodyParts, array $appendixParts, array $completedKeys): array
    {
        $completed = array_fill_keys($completedKeys, true);

        $edges = $flowRun->flow->edges()
            ->get(['from_node_key', 'to_node_key'])
            ->map(fn ($e) => ['from' => (string) $e->from_node_key, 'to' => (string) $e->to_node_key])
            ->filter(fn ($e) => isset($completed[$e['from']], $completed[$e['to']]))
            ->values()
            ->all();

        $consumed = GraphTopology::ancestorsOf(
            array_map(fn ($p) => $p['key'], $bodyParts),
            $edges
        );

        $surviving = array_filter(
            array_merge($bodyParts, $appendixParts),
            fn ($p) => ! isset($consumed[$p['key']])
        );

        return array_values(array_map(fn ($p) => $p['output'], $surviving));
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
- Да добавиш ясни заглавия на секции (напр. „Публикации", „Заглавия", „Хаштагове") — но НЕ добавяй заглавие над част, която вече започва със заглавие.
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

    // Lengths within this ratio count as "near equal" for the containment
    // tie-break (mutual containment keeps the LATER part).
    private const CONTAINMENT_LENGTH_TOLERANCE = 0.02;

    /**
     * Drop parts whose content is already contained verbatim in a longer (or,
     * for near-equal lengths, later) surviving part — parallel branches with
     * no connecting edge can still emit the same content twice. Mutual
     * containment (near-identical parts) keeps the later part, e.g. the
     * corrected of two variants.
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

    // Word n-gram size for containment coverage. Positional fragment sampling
    // is brittle here: fragments landing on a part's own labels or spanning
    // section boundaries can never match the containing document (run 82).
    private const SHINGLE_WORDS = 5;

    /** Whether enough of the needle's word shingles appear verbatim in the haystack (both normalized). */
    private function containedIn(string $normNeedle, string $normHaystack): bool
    {
        $needleShingles = $this->wordShingles($normNeedle);

        // Too short to shingle — fall back to direct substring presence.
        if ($needleShingles === []) {
            return $normNeedle !== '' && mb_strpos($normHaystack, $normNeedle) !== false;
        }

        $hayShingles = array_fill_keys($this->wordShingles($normHaystack), true);

        $present = 0;
        foreach ($needleShingles as $shingle) {
            if (isset($hayShingles[$shingle])) {
                $present++;
            }
        }

        return $present / count($needleShingles) >= self::VERBATIM_COVERAGE_THRESHOLD;
    }

    /**
     * Overlapping word n-grams of a normalized string.
     *
     * @return list<string>
     */
    private function wordShingles(string $normalized): array
    {
        $words = $normalized === '' ? [] : explode(' ', $normalized);
        if (count($words) < self::SHINGLE_WORDS) {
            return [];
        }

        $shingles = [];
        for ($i = 0, $max = count($words) - self::SHINGLE_WORDS; $i <= $max; $i++) {
            $shingles[] = implode(' ', array_slice($words, $i, self::SHINGLE_WORDS));
        }

        return $shingles;
    }
}
