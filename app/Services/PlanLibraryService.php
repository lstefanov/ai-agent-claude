<?php

namespace App\Services;

use App\Models\FlowRun;
use App\Models\FlowVersion;
use App\Models\PlanLibraryEntry;
use App\Support\LlmContext;
use App\Support\PaidModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The planner's long-term memory (Фаза 2 — учене от успешни планове).
 *
 * Lifecycle:
 *  1. captureApprovedPlan() — saving/activating the ACTIVE version IS the
 *     approval: its nodes/edges + stored intent are snapshotted as a
 *     'candidate' entry (one per flow, refreshed on every save).
 *  2. recordRunOutcome() — after a successful run of the active version the
 *     entry becomes 'proven' and accumulates run count + average step-QA score.
 *  3. fewShotBlock() — at planning time the 1-2 most similar proven plans are
 *     injected into the pipeline-design prompt as worked examples.
 */
class PlanLibraryService
{
    /**
     * Snapshot the flow's approved plan — the ACTIVE version's graph + intent.
     * Called from FlowVersionService when the active version is saved/created
     * or another version is activated.
     */
    public function captureApprovedPlan(FlowVersion $version): void
    {
        $intent = $version->plan_intent;

        // No planner intent (e.g. hand-built graph) → nothing to learn from yet.
        if (! is_array($intent) || $intent === []) {
            return;
        }

        $agents = $this->compactAgentsFromGraph($version);

        if (count($agents) < 3) {
            return;
        }

        PlanLibraryEntry::updateOrCreate(
            ['flow_id' => $version->flow_id],
            [
                'company_id' => $version->flow->company_id,
                'intent' => $intent,
                'agents' => $agents,
                'embedding' => $this->embedIntent($intent),
                'deliverable' => (string) ($intent['deliverable'] ?? 'other'),
                'language' => (string) ($intent['language'] ?? 'bg'),
                'complexity' => $intent['complexity'] ?? null,
                'information_sources' => array_values((array) ($intent['information_sources'] ?? [])),
                'needs_image' => (bool) ($intent['needs_image'] ?? false),
                'needs_hashtags' => (bool) ($intent['needs_hashtags'] ?? false),
                'competitor_focus' => (bool) ($intent['competitor_focus'] ?? false),
                'improvement_suggestions' => (bool) ($intent['improvement_suggestions'] ?? false),
                // Re-approving an edited graph resets the proof — the new
                // topology must earn its 'proven' badge with a successful run.
                'status' => 'candidate',
            ],
        );
    }

    /**
     * Promote/penalize the flow's entry after a run. Called from
     * GraphFlowExecutor::finalize (success) — failures don't demote, they
     * simply never promote.
     */
    public function recordRunOutcome(FlowRun $flowRun): void
    {
        // The library snapshots the ACTIVE version's plan — a successful run of
        // another template must not "prove" it.
        if (! $flowRun->flowVersion?->is_active) {
            return;
        }

        $entry = PlanLibraryEntry::where('flow_id', $flowRun->flow_id)->first();

        if (! $entry) {
            return;
        }

        $qaScores = collect($flowRun->context['step_qa_results'] ?? [])
            ->pluck('score')
            ->filter(fn ($s) => is_numeric($s));

        $runQa = $qaScores->isNotEmpty() ? (int) round($qaScores->avg()) : null;

        // Running average over successful runs.
        $newCount = $entry->runs_count + 1;
        $newAvg = $runQa === null
            ? $entry->avg_qa_score
            : (int) round((($entry->avg_qa_score ?? $runQa) * $entry->runs_count + $runQa) / $newCount);

        $entry->update([
            'status' => 'proven',
            'runs_count' => $newCount,
            'avg_qa_score' => $newAvg,
            'last_run_at' => now(),
        ]);

        Log::info("[PlanLibrary] Flow {$flowRun->flow_id} plan proven (runs={$newCount}, avg_qa=".($newAvg ?? '—').')');
    }

    /**
     * Compact, prompt-ready few-shot block with the most similar proven plans.
     * Returns '' when the library has nothing relevant yet.
     */
    public function fewShotBlock(array $intent, int $limit = 2): string
    {
        $examples = $this->findSimilar($intent, $limit);

        if ($examples->isEmpty()) {
            return '';
        }

        $blocks = $examples->map(function (PlanLibraryEntry $e, $i) {
            $summary = sprintf(
                '%s | източници: %s | image:%s hashtags:%s competitors:%s | %d успешни run-а%s',
                $e->deliverable,
                implode(',', $e->information_sources ?: ['-']),
                $e->needs_image ? 'да' : 'не',
                $e->needs_hashtags ? 'да' : 'не',
                $e->competitor_focus ? 'да' : 'не',
                $e->runs_count,
                $e->avg_qa_score !== null ? ", QA {$e->avg_qa_score}/100" : '',
            );

            return 'ПРИМЕР '.($i + 1)." ({$summary}):\n"
                .json_encode($e->agents, JSON_UNESCAPED_UNICODE);
        });

        return "\n\nДОКАЗАНИ ПЛАНОВЕ ОТ БИБЛИОТЕКАТА (подобни задания, изпълнени успешно — използвай ги като еталон за топология и стил на промптите, БЕЗ да копираш слепешком темите им):\n"
            .$blocks->implode("\n\n");
    }

    /**
     * Retrieval with two regimes:
     *  - small library → cheap structural similarity over the denormalized
     *    intent fingerprint (deliverable dominates, then shared sources/flags);
     *  - once proven entries exceed services.planner.vector_threshold →
     *    cosine similarity over intent embeddings (entries without an
     *    embedding fall back to their structural score).
     *
     * @return Collection<int, PlanLibraryEntry>
     */
    public function findSimilar(array $intent, int $limit = 2)
    {
        $entries = PlanLibraryEntry::whereIn('status', ['proven', 'candidate'])
            ->get()
            // Proven plans are always usable; an UNPROVEN plan is only a good
            // benchmark if it is high quality (many agents or a paid-provider
            // origin, e.g. an Anthropic plan) — this keeps weak local candidates
            // out of the few-shot while letting strong templates in.
            ->filter(fn (PlanLibraryEntry $e) => $e->status === 'proven' || $this->planQuality($e) >= 8)
            ->values();

        if ($entries->isEmpty()) {
            return collect();
        }

        $useVectors = $entries->count() >= (int) config('services.planner.vector_threshold', 100)
            && ! empty(config('services.openai.api_key'));

        $queryVector = null;
        if ($useVectors) {
            LlmContext::push(['purpose' => 'embedding']);
            try {
                $queryVector = app(OpenAiChatService::class)->embed($this->intentText($intent));
            } catch (\Throwable $e) {
                Log::warning('[PlanLibrary] Query embedding failed, structural scoring used: '.$e->getMessage());
            } finally {
                LlmContext::pop();
            }
        }

        $sources = array_values((array) ($intent['information_sources'] ?? []));

        $scored = $entries->map(function (PlanLibraryEntry $e) use ($intent, $sources, $queryVector) {
            // Structural score: 0–10-ish.
            $structural = 0;
            $structural += $this->deliverableAffinity($e->deliverable, $intent['deliverable'] ?? null);
            $structural += count(array_intersect($e->information_sources ?? [], $sources));
            foreach (['needs_image', 'needs_hashtags', 'competitor_focus', 'improvement_suggestions'] as $flag) {
                $structural += $e->{$flag} === (bool) ($intent[$flag] ?? false) ? 1 : 0;
            }
            $structural += $e->language === ($intent['language'] ?? 'bg') ? 1 : 0;

            // Vector regime: cosine (0–1) scaled to the same magnitude so
            // embedding-less entries (legacy rows) still compete structurally.
            if ($queryVector !== null && is_array($e->embedding) && $e->embedding !== []) {
                $e->setAttribute('similarity', round(self::cosine($queryVector, $e->embedding) * 10, 3));
                $e->setAttribute('similarity_kind', 'vector');
            } else {
                $e->setAttribute('similarity', $structural);
                $e->setAttribute('similarity_kind', 'structural');
            }

            return $e;
        });

        // Relevance floor: ≥5 structural (deliverable match + overlap) or
        // cosine ≥ 0.55 (×10) — random examples hurt more than they help.
        return $scored
            ->filter(fn ($e) => $e->getAttribute('similarity') >= ($e->getAttribute('similarity_kind') === 'vector' ? 5.5 : 5))
            // Quality FIRST: the few-shot is a BENCHMARK, so among relevant plans
            // prefer the strongest (most agents / paid-provider) over a weak local
            // one that merely matches the deliverable label.
            ->sortByDesc(fn ($e) => [
                $this->planQuality($e),
                $e->getAttribute('similarity'),
                $e->avg_qa_score ?? 0,
                $e->runs_count,
            ])
            ->take($limit)
            ->values();
    }

    /**
     * Benchmark quality of a library plan: agent count, boosted when the plan
     * uses a paid (OpenAI/Anthropic) provider — those tend to be the richest,
     * most granular templates.
     */
    private function planQuality(PlanLibraryEntry $e): int
    {
        $agents = is_array($e->agents) ? $e->agents : [];
        $paid = collect($agents)->contains(
            fn ($a) => in_array($a['provider'] ?? 'ollama', ['openai', 'anthropic'], true)
        );

        return count($agents) + ($paid ? 5 : 0);
    }

    /**
     * Deliverable similarity: exact match, else partial credit for related
     * long-form-synthesis deliverables, so a strong "report" template still
     * surfaces for an "analysis" intent (the labels are noisy across models).
     */
    private function deliverableAffinity(?string $a, ?string $b): int
    {
        if ($a === null || $b === null) {
            return 0;
        }
        if ($a === $b) {
            return 4;
        }
        $synthesis = ['report', 'analysis', 'seo_content', 'blog_article', 'newsletter', 'email'];

        return in_array($a, $synthesis, true) && in_array($b, $synthesis, true) ? 3 : 0;
    }

    /** Compact natural-language rendering of an intent for embedding. */
    private function intentText(array $intent): string
    {
        return implode(' | ', array_filter([
            $intent['deliverable'] ?? '',
            $intent['deliverable_description'] ?? '',
            implode(', ', (array) ($intent['information_sources'] ?? [])),
            implode('; ', (array) ($intent['key_tasks'] ?? [])),
            $intent['region'] ?? '',
        ]));
    }

    /** Best-effort intent embedding at capture time — never blocks the save. */
    private function embedIntent(array $intent): ?array
    {
        if (empty(config('services.openai.api_key'))) {
            return null;
        }

        LlmContext::push(['purpose' => 'embedding']);

        try {
            return app(OpenAiChatService::class)->embed($this->intentText($intent));
        } catch (\Throwable $e) {
            Log::warning('[PlanLibrary] Intent embedding failed: '.$e->getMessage());

            return null;
        } finally {
            LlmContext::pop();
        }
    }

    /** @param array<int, float|int> $a @param array<int, float|int> $b */
    private static function cosine(array $a, array $b): float
    {
        $dot = $normA = $normB = 0.0;
        $n = min(count($a), count($b));

        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] ** 2;
            $normB += $b[$i] ** 2;
        }

        return ($normA > 0 && $normB > 0) ? $dot / (sqrt($normA) * sqrt($normB)) : 0.0;
    }

    /**
     * Rebuild a compact planner-spec view of the approved graph from the
     * version's flow_nodes + flow_edges (uid = node_key, depends_on from edges).
     * Prompts are trimmed — examples teach structure and style, not content.
     *
     * @return array<int, array<string, mixed>>
     */
    private function compactAgentsFromGraph(FlowVersion $version): array
    {
        $nodes = $version->nodes()->where('is_active', true)->get();
        $edges = $version->edges()->get(['from_node_key', 'to_node_key']);

        $dependsOn = [];
        foreach ($edges as $edge) {
            $dependsOn[$edge->to_node_key][] = $edge->from_node_key;
        }

        // Boundary (Старт/Край) nodes never reach flow_nodes — no filtering needed.
        return $nodes
            ->map(fn ($n) => [
                'uid' => $n->node_key,
                'name' => $n->name,
                'type' => $n->type,
                'depends_on' => array_values($dependsOn[$n->node_key] ?? []),
                'role' => mb_substr((string) $n->role, 0, 200),
                'prompt_excerpt' => mb_substr((string) $n->prompt_template, 0, 240),
                'tools' => array_values((array) ($n->config['tools'] ?? [])),
                'provider' => PaidModel::provider($n->model) ?? 'ollama',
            ])
            ->values()
            ->all();
    }
}
