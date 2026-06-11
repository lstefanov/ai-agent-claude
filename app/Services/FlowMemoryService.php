<?php

namespace App\Services;

use App\Models\Flow;
use App\Models\FlowMemory;
use App\Models\FlowNode;
use App\Models\FlowRun;
use App\Support\LlmUsage;
use Illuminate\Support\Facades\Log;

/**
 * Памет на flow-а — what the flow already produced + per-node lessons.
 *
 * Lifecycle (mirrors PlanLibraryService):
 *  1. recordRun() — after a successful run (queued DistillFlowMemoryJob) the
 *     content nodes' outputs are distilled DETERMINISTICALLY (title + excerpt,
 *     no LLM) and embedded; QA/replan events become per-node lessons.
 *  2. outputMemoryBlock()/lessonsBlock() — injected into node prompts on the
 *     next run ("вече създадено — не повтаряй" / "поуки от предишни run-ове").
 *  3. similarityCheck() — post-generation gate: a fresh output too close
 *     (cosine ≥ threshold) to remembered content triggers a retry with
 *     concrete feedback. Never fails a run — duplication is a soft failure.
 */
class FlowMemoryService
{
    /**
     * Body-role types that TRANSFORM upstream content (correct/translate) —
     * "avoid repeating" prompts would corrupt their job, so they are never
     * treated as content producers.
     */
    private const TRANSFORMER_TYPES = ['bg_text_corrector', 'translator'];

    private const PROMPT_BLOCK_MAX_CHARS = 2000;

    public function __construct(private EmbeddingService $embeddings) {}

    public static function enabled(Flow $flow): bool
    {
        return (bool) config('services.memory.enabled', true)
            && (bool) ($flow->settings['memory']['enabled'] ?? true);
    }

    /** Content producer = body-role output that isn't a transformer. */
    public function isContentNode(FlowNode $node): bool
    {
        return $node->effectiveOutputRole() === 'body'
            && ! in_array($node->type, self::TRANSFORMER_TYPES, true);
    }

    /**
     * "Вече създадено" block for a content node's prompt — newest digests
     * first, hard-capped so small local models don't drown in history.
     */
    public function outputMemoryBlock(Flow $flow, FlowNode $node): string
    {
        if (! $this->isContentNode($node)) {
            return '';
        }

        $entries = $flow->memories()
            ->where('kind', 'output')
            ->latest('id')
            ->take((int) config('services.memory.prompt_entries', 10))
            ->get(['title', 'summary']);

        if ($entries->isEmpty()) {
            return '';
        }

        $header = "--- ПАМЕТ: вече създадено съдържание от предишни изпълнения ---\n"
            .'НЕ повтаряй темите, заглавията и формулировките по-долу. Създай съществено '
            ."различно съдържание (допустимо припокриване: под 30-40%).\n";

        $block = $header;
        foreach ($entries as $i => $entry) {
            $line = ($i + 1).'. „'.($entry->title ?: 'Без заглавие').'“ — '.$entry->summary."\n";
            if (mb_strlen($block.$line) > self::PROMPT_BLOCK_MAX_CHARS) {
                break;
            }
            $block .= $line;
        }

        return rtrim($block);
    }

    /** Per-node lessons distilled from past QA/replan events. */
    public function lessonsBlock(Flow $flow, FlowNode $node): string
    {
        // Transformers treat their whole input as text to process — an
        // injected block would be corrected/translated instead of obeyed.
        if (in_array($node->type, self::TRANSFORMER_TYPES, true)) {
            return '';
        }

        $lessons = $flow->memories()
            ->where('kind', 'lesson')
            ->where('node_key', $node->node_key)
            ->latest('id')
            ->take((int) config('services.memory.max_lessons_per_node', 5))
            ->pluck('summary');

        if ($lessons->isEmpty()) {
            return '';
        }

        return "--- ПОУКИ от предишни изпълнения на този агент ---\n"
            .$lessons->map(fn ($l) => '- '.$l)->implode("\n");
    }

    /**
     * Post-generation dedup check: cosine similarity of the fresh output
     * against remembered outputs (same embedding provider only).
     *
     * @param  array<string, mixed>  $llmContext  attribution for the embed call
     * @return array{similarity: float, title: string, summary: string, memory_id: int}|null
     *                                                                                       null = pass (not similar enough, memory empty, or embedding failed)
     */
    public function similarityCheck(Flow $flow, string $output, array $llmContext = []): ?array
    {
        $tag = $this->embeddings->providerTag();

        $entries = $flow->memories()
            ->where('kind', 'output')
            ->where('embedding_provider', $tag)
            ->whereNotNull('embedding')
            ->latest('id')
            ->take((int) config('services.memory.max_output_entries', 200))
            ->get(['id', 'title', 'summary', 'embedding']);

        if ($entries->isEmpty()) {
            return null;
        }

        $vector = $this->embeddings->embed($output, $llmContext);

        if ($vector === null) {
            return null; // non-fatal — gate skipped, warning already logged
        }

        $worst = null;
        $worstSim = 0.0;
        foreach ($entries as $entry) {
            $sim = EmbeddingService::cosine($vector, $entry->embedding);
            if ($sim > $worstSim) {
                $worstSim = $sim;
                $worst = $entry;
            }
        }

        $threshold = (float) config('services.memory.similarity_threshold', 0.80);

        if ($worst === null || $worstSim < $threshold) {
            return null;
        }

        return [
            'similarity' => round($worstSim, 3),
            'title' => (string) ($worst->title ?? ''),
            'summary' => (string) $worst->summary,
            'memory_id' => (int) $worst->id,
        ];
    }

    /**
     * Distill a completed run into memory. Deterministic — no LLM calls except
     * the embeddings. Called from DistillFlowMemoryJob (never inline).
     */
    public function recordRun(FlowRun $flowRun): void
    {
        $flow = $flowRun->flow;

        if (! $flow || ! self::enabled($flow)) {
            return;
        }

        $llmContext = [
            'company_id' => $flow->company_id,
            'flow_id' => $flow->id,
            'flow_run_id' => $flowRun->id,
        ];

        $this->recordOutputs($flowRun, $llmContext);
        $this->recordLessons($flowRun);
        $this->prune($flow);

        // The embed calls above accumulated into the global LlmUsage counter;
        // discard so a worker's next unit of work isn't misattributed (each
        // call is already persisted individually in llm_requests).
        LlmUsage::take();

        Log::info("[FlowMemory] Run {$flowRun->id} distilled into memory for flow {$flow->id}");
    }

    /** Outputs of content nodes → 'output' digests with embeddings. */
    private function recordOutputs(FlowRun $flowRun, array $llmContext): void
    {
        $nodeRuns = $flowRun->nodeRuns()
            ->with('flowNode')
            ->where('status', 'completed')
            ->whereNotNull('output')
            ->where('output', '!=', '')
            ->get();

        foreach ($nodeRuns as $nodeRun) {
            $node = $nodeRun->flowNode;

            if (! $node || ! $this->isContentNode($node)) {
                continue;
            }

            $title = $this->titleFrom($nodeRun->output);
            $summary = $this->summaryFrom($nodeRun->output);

            // Identical content already remembered (e.g. the model repeated
            // itself despite the gate) → don't store the duplicate twice.
            $exists = $flowRun->flow->memories()
                ->where('kind', 'output')
                ->where('summary', $summary)
                ->exists();

            if ($exists) {
                continue;
            }

            $embedding = $this->embeddings->embed($nodeRun->output, $llmContext);

            FlowMemory::create([
                'flow_id' => $flowRun->flow_id,
                'flow_run_id' => $flowRun->id,
                'node_key' => $nodeRun->node_key,
                'kind' => 'output',
                'title' => $title,
                'summary' => $summary,
                'embedding' => $embedding,
                'embedding_provider' => $embedding !== null ? $this->embeddings->providerTag() : null,
                'meta' => ['node_name' => $node->name, 'node_type' => $node->type],
            ]);
        }
    }

    /**
     * Replan audit entries (QA fail revisions + degenerate-output watchdog)
     * → per-node 'lesson' rows. The planner_reason is already LLM-written
     * natural language — free, high-quality lesson text.
     */
    private function recordLessons(FlowRun $flowRun): void
    {
        $replan = $flowRun->context['replan'] ?? [];

        foreach ($replan as $nodeKey => $entries) {
            foreach ((array) $entries as $entry) {
                $trigger = trim((string) ($entry['trigger'] ?? ''));
                $reason = trim((string) ($entry['planner_reason'] ?? ''));

                if ($reason === '') {
                    continue;
                }

                $lesson = mb_substr(($trigger !== '' ? $trigger.' → ' : '').'Ревизия: '.$reason, 0, 250);

                $exists = $flowRun->flow->memories()
                    ->where('kind', 'lesson')
                    ->where('node_key', $nodeKey)
                    ->where('summary', $lesson)
                    ->exists();

                if ($exists) {
                    continue;
                }

                FlowMemory::create([
                    'flow_id' => $flowRun->flow_id,
                    'flow_run_id' => $flowRun->id,
                    'node_key' => $nodeKey,
                    'kind' => 'lesson',
                    'summary' => $lesson,
                    'meta' => [
                        'trigger' => mb_substr($trigger, 0, 200),
                        'succeeded' => (bool) ($entry['succeeded'] ?? false),
                    ],
                ]);
            }
        }
    }

    /** Keep memory bounded: newest N outputs per flow, M lessons per node. */
    private function prune(Flow $flow): void
    {
        $maxOutputs = (int) config('services.memory.max_output_entries', 200);
        $keepOutputIds = $flow->memories()
            ->where('kind', 'output')
            ->latest('id')
            ->take($maxOutputs)
            ->pluck('id');

        $flow->memories()
            ->where('kind', 'output')
            ->whereNotIn('id', $keepOutputIds)
            ->delete();

        $maxLessons = (int) config('services.memory.max_lessons_per_node', 5);
        $nodeKeys = $flow->memories()
            ->where('kind', 'lesson')
            ->distinct()
            ->pluck('node_key');

        foreach ($nodeKeys as $nodeKey) {
            $keepLessonIds = $flow->memories()
                ->where('kind', 'lesson')
                ->where('node_key', $nodeKey)
                ->latest('id')
                ->take($maxLessons)
                ->pluck('id');

            $flow->memories()
                ->where('kind', 'lesson')
                ->where('node_key', $nodeKey)
                ->whereNotIn('id', $keepLessonIds)
                ->delete();
        }
    }

    /** @return int deleted rows */
    public function clear(Flow $flow, ?string $kind = null): int
    {
        return $flow->memories()
            ->when(in_array($kind, ['output', 'lesson'], true), fn ($q) => $q->where('kind', $kind))
            ->delete();
    }

    /** First non-empty line, stripped of markdown decoration. */
    private function titleFrom(string $output): string
    {
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $clean = trim(preg_replace('/^[#>*\-\s]+|[*_`]+/u', '', $line) ?? '');
            if ($clean !== '') {
                return mb_substr($clean, 0, 150);
            }
        }

        return '';
    }

    /** Whitespace-normalized excerpt of the output. */
    private function summaryFrom(string $output): string
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $output));

        return mb_substr($normalized, 0, 300);
    }
}
