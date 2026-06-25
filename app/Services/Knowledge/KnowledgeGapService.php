<?php

namespace App\Services\Knowledge;

use App\Models\Company;
use App\Models\KnowledgeEvent;
use App\Models\KnowledgeGap;
use App\Services\EmbeddingService;

/**
 * "Пропуски в знанието": grounding търсене без покритие се логва като open
 * gap (какво да качиш). Когато по-късно пристигне покриващо знание — нов
 * чанк/факт от ресурс или от run — пропускът става resolved ("готов") с
 * резолюция кой го е запълнил. Никога не проваля търсене или ingest.
 */
class KnowledgeGapService
{
    private EmbeddingService $embeddings;

    public function __construct(EmbeddingService $embeddings)
    {
        $this->embeddings = $embeddings->withProvider(
            config('services.knowledge.embedding_provider'),
            config('services.knowledge.embedding_model'),
        );
    }

    /** Логва пропуск (с embedding за бъдеща авто-резолюция) + cap per company. */
    public function log(Company $company, string $query, ?float $bestScore, ?int $flowRunId = null, ?string $nodeKey = null): void
    {
        try {
            $query = mb_substr(trim($query), 0, 500);
            if ($query === '') {
                return;
            }

            // Дубликат на вече отворен пропуск → само опресняваме.
            $existing = KnowledgeGap::where('company_id', $company->id)
                ->open()
                ->where('query', $query)
                ->first();

            if ($existing) {
                $existing->update(['best_score' => $bestScore]);

                return;
            }

            $vector = $this->embeddings->embed($query, ['company_id' => $company->id]);

            KnowledgeGap::create([
                'company_id' => $company->id,
                'flow_run_id' => $flowRunId,
                'node_key' => $nodeKey,
                'query' => $query,
                'best_score' => $bestScore,
                'embedding' => $vector,
                'embedding_provider' => $vector !== null ? $this->embeddings->providerTag() : null,
                'status' => 'open',
            ]);

            $keep = KnowledgeGap::where('company_id', $company->id)
                ->latest('id')
                ->take((int) config('services.knowledge.max_gaps_per_company', 200))
                ->pluck('id');
            KnowledgeGap::where('company_id', $company->id)->whereNotIn('id', $keep)->delete();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Пробва да запълни отворените пропуски с прясно embed-нато знание
     * (вектори на нови чанкове/факти). Връща броя resolved.
     *
     * @param  array<int, array<int, float>>  $vectors
     */
    public function resolveAgainstVectors(Company $company, array $vectors, string $resolvedBy): int
    {
        if ($vectors === []) {
            return 0;
        }

        try {
            $threshold = (float) config('services.knowledge.gap_resolve_threshold', 0.62);
            $tag = $this->embeddings->providerTag();
            $resolved = 0;

            $gaps = KnowledgeGap::where('company_id', $company->id)
                ->open()
                ->whereNotNull('embedding')
                ->where('embedding_provider', $tag)
                ->get();

            foreach ($gaps as $gap) {
                foreach ($vectors as $vector) {
                    if (EmbeddingService::cosine((array) $gap->embedding, $vector) >= $threshold) {
                        $gap->update([
                            'status' => 'resolved',
                            'resolved_by' => mb_substr($resolvedBy, 0, 100),
                            'resolved_at' => now(),
                        ]);

                        KnowledgeEvent::log(
                            $company->id, 'updated', 'fact', null,
                            'Запълнен пропуск: '.$gap->query,
                            null, $resolvedBy, ['gap_id' => $gap->id],
                        );

                        $resolved++;
                        break;
                    }
                }
            }

            return $resolved;
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }
}
