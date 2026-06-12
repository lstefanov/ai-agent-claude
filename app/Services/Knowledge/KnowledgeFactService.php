<?php

namespace App\Services\Knowledge;

use App\Models\Company;
use App\Models\KnowledgeEvent;
use App\Models\KnowledgeFact;
use App\Services\EmbeddingService;

/**
 * Upsert на факти в фирмения профил. Дедупликация: embedding на
 * "име (категория, локация)" — БЕЗ стойността, така промяна на цена пак
 * match-ва стария факт и го supersede-ва (старият остава за историята).
 * Всяка промяна се логва в knowledge_events; нов факт пробва да запълни
 * отворени "пропуски".
 */
class KnowledgeFactService
{
    private EmbeddingService $embeddings;

    public function __construct(
        EmbeddingService $embeddings,
        private KnowledgeGapService $gaps,
    ) {
        $this->embeddings = $embeddings->withProvider(
            config('services.knowledge.embedding_provider'),
            config('services.knowledge.embedding_model'),
        );
    }

    /**
     * @param  array<int, array{category: string, location: ?string, name: string, value: string, confidence: float}>  $facts
     * @return array{added: int, updated: int, skipped: int}
     */
    public function upsertMany(
        Company $company,
        array $facts,
        string $sourceType,
        ?int $sourceId,
        ?int $flowRunId,
        string $sourceLabel,
        array $llmContext = [],
    ): array {
        $stats = ['added' => 0, 'updated' => 0, 'skipped' => 0];
        $minConfidence = (float) config('services.knowledge.fact_min_confidence', 0.5);

        foreach ($facts as $fact) {
            if ((float) ($fact['confidence'] ?? 0) < $minConfidence) {
                $stats['skipped']++;

                continue;
            }

            $result = $this->upsert($company, $fact, $sourceType, $sourceId, $flowRunId, $sourceLabel, $llmContext);
            $stats[$result]++;
        }

        return $stats;
    }

    /**
     * @param  array{category: string, location: ?string, name: string, value: string, confidence: float}  $fact
     * @return 'added'|'updated'|'skipped'
     */
    public function upsert(
        Company $company,
        array $fact,
        string $sourceType,
        ?int $sourceId,
        ?int $flowRunId,
        string $sourceLabel,
        array $llmContext = [],
    ): string {
        $identity = $fact['name'].' ('.$fact['category'].($fact['location'] ? ', '.$fact['location'] : '').')';
        $vector = $this->embeddings->embed($identity, array_merge(['company_id' => $company->id], $llmContext));
        $tag = $this->embeddings->providerTag();

        $existing = $this->findMatch($company, $fact, $vector, $tag);

        // Същата стойност → нищо ново (само опресняваме "кога последно видяно").
        if ($existing !== null && $this->sameValue($existing->value, $fact['value'])) {
            $existing->touch();

            return 'skipped';
        }

        $new = $company->knowledgeFacts()->create([
            'category' => $fact['category'],
            'location' => $fact['location'],
            'name' => $fact['name'],
            'value' => $fact['value'],
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'flow_run_id' => $flowRunId,
            'confidence' => $fact['confidence'],
            'status' => 'active',
            'embedding' => $vector,
            'embedding_provider' => $vector !== null ? $tag : null,
        ]);

        if ($existing !== null) {
            $existing->update(['status' => 'superseded']);

            KnowledgeEvent::log(
                $company->id, 'updated', 'fact', $new->id, $fact['name'],
                "Старо: {$existing->value}\nНово: {$fact['value']}",
                $sourceLabel,
                ['category' => $fact['category'], 'location' => $fact['location'], 'superseded_fact_id' => $existing->id],
            );

            return 'updated';
        }

        KnowledgeEvent::log(
            $company->id, 'added', 'fact', $new->id, $fact['name'],
            $fact['value'], $sourceLabel,
            ['category' => $fact['category'], 'location' => $fact['location']],
        );

        // Нов факт може да е отговорът на въпрос, за който агент е ударил
        // на камък — пробва да запълни отворените пропуски.
        if ($vector !== null) {
            $this->gaps->resolveAgainstVectors($company, [$vector], "fact:{$new->id}");
        }

        return 'added';
    }

    /**
     * Най-близкият активен факт от същата категория/локация: embedding cosine
     * ≥ прага, с exact-name fallback за случаите без работещ embedding.
     */
    private function findMatch(Company $company, array $fact, ?array $vector, string $tag): ?KnowledgeFact
    {
        $candidates = $company->knowledgeFacts()
            ->active()
            ->where('category', $fact['category'])
            ->when(
                $fact['location'] !== null,
                fn ($q) => $q->where('location', $fact['location']),
                fn ($q) => $q->whereNull('location'),
            )
            ->latest('id')
            ->limit(2000)
            ->get();

        // Exact-name match хваща и факти без embedding (провайдърът е бил долу).
        $nameLower = mb_strtolower(trim($fact['name']));
        foreach ($candidates as $candidate) {
            if (mb_strtolower(trim($candidate->name)) === $nameLower) {
                return $candidate;
            }
        }

        if ($vector === null) {
            return null;
        }

        $threshold = (float) config('services.knowledge.fact_similarity_threshold', 0.86);
        $best = null;
        $bestScore = 0.0;

        foreach ($candidates as $candidate) {
            if ($candidate->embedding === null || $candidate->embedding_provider !== $tag) {
                continue;
            }
            $score = EmbeddingService::cosine($vector, (array) $candidate->embedding);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $bestScore >= $threshold ? $best : null;
    }

    private function sameValue(string $a, string $b): bool
    {
        $normalize = fn (string $v) => preg_replace('/\s+/u', ' ', mb_strtolower(trim($v)));

        return $normalize($a) === $normalize($b);
    }
}
