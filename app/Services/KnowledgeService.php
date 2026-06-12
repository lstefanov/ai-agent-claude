<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Flow;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeEvent;
use App\Models\KnowledgeFact;
use App\Models\KnowledgePage;
use App\Models\KnowledgeResource;
use App\Services\Knowledge\KnowledgeGapService;
use Illuminate\Support\Facades\Storage;

/**
 * База знания на фирмата (per-Company RAG v2) — the durable counterpart of
 * FlowMemoryService: memory remembers what a flow PRODUCED, knowledge holds
 * what is TRUE about the company (ресурси: url/файл/снимка/бележка + ФАКТИ —
 * натрупващият се профил от ingest и от изходите на агентите).
 *
 * Търсенето е ХИБРИДНО: embedding cosine + keyword (FULLTEXT) кандидати,
 * слети с Reciprocal Rank Fusion — точни низове (цени, имена на процедури)
 * се хващат и когато векторите ги изпускат. Фактите имат приоритет.
 *
 * Consumption is dual-channel:
 *  - knowledge_search agent tool (cloud моделите сами си избират заявки);
 *  - "ЗНАНИЕ" prompt block injected by NodeExecutorService for content nodes
 *    (works for local models that cannot tool-call).
 */
class KnowledgeService
{
    /** RRF константа (стандартното k=60 от литературата). */
    private const RRF_K = 60;

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

    public static function enabled(Company $company): bool
    {
        return (bool) config('services.knowledge.enabled', true)
            && (bool) ($company->settings['knowledge']['enabled'] ?? true);
    }

    public static function enabledForFlow(Flow $flow): bool
    {
        return $flow->company !== null
            && self::enabled($flow->company)
            && (bool) ($flow->settings['knowledge']['enabled'] ?? true);
    }

    public function isEmpty(Company $company): bool
    {
        return ! $company->knowledgeResources()->ready()->exists()
            && ! $company->knowledgeFacts()->active()->exists();
    }

    public function providerTag(): string
    {
        return $this->embeddings->providerTag();
    }

    /**
     * Hybrid top-K търсене върху чанкове + факти.
     *
     * @param  array<int, string>|null  $types  Филтър по тип ресурс за
     *                                          чанковете (url|upload|image|note); null = всички. Фактите участват
     *                                          винаги, освен ако $includeFacts е false.
     * @return array<int, array{kind: string, title: string, content: string,
     *     url: ?string, source_type: ?string, resource_id: ?int, page_id: ?int,
     *     fact_id: ?int, category: ?string, location: ?string, score: float, match: string}>
     */
    public function search(
        Company $company,
        string $query,
        ?array $types = null,
        ?int $topK = null,
        array $llmContext = [],
        ?int $flowRunId = null,
        ?string $nodeKey = null,
        bool $logGaps = true,
        bool $includeFacts = true,
    ): array {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $topK = max(1, $topK ?? (int) config('services.knowledge.top_k', 5));

        $vector = $this->embeddings->embed($query, array_merge(['company_id' => $company->id], $llmContext));

        $vectorHits = $vector !== null ? $this->vectorCandidates($company, $vector, $types, $includeFacts, $topK * 4) : [];
        $keywordHits = $this->keywordCandidates($company, $query, $types, $includeFacts, $topK * 4);

        $merged = $this->rrfMerge($vectorHits, $keywordHits, $topK);

        // "Пропуск": търсене без добро покритие — сигнал какво да се качи.
        // Никога не проваля търсенето.
        if ($logGaps) {
            $bestCosine = 0.0;
            foreach ($vectorHits as $hit) {
                $bestCosine = max($bestCosine, $hit['score']);
            }
            if ($merged === [] || $bestCosine < (float) config('services.knowledge.gap_threshold', 0.55)) {
                $this->gaps->log($company, $query, $vectorHits === [] ? null : $bestCosine, $flowRunId, $nodeKey);
            }
        }

        return $merged;
    }

    /**
     * "ЗНАНИЕ" prompt block for a content node — фактите (актуалният профил)
     * първи, после най-релевантните откъси с източник. Empty string when
     * nothing relevant. Never throws.
     */
    public function knowledgeBlock(Company $company, string $renderedInput, array $llmContext = []): string
    {
        if ($this->isEmpty($company)) {
            return '';
        }

        $hits = $this->search(
            $company,
            mb_substr($renderedInput, 0, 2000),
            llmContext: $llmContext,
            flowRunId: $llmContext['flow_run_id'] ?? null,
            logGaps: false,
        );

        $minScore = (float) config('services.knowledge.min_score', 0.25);
        $hits = array_values(array_filter(
            $hits,
            fn ($hit) => $hit['match'] !== 'vector' || $hit['score'] >= $minScore,
        ));
        if ($hits === []) {
            return '';
        }

        $facts = array_values(array_filter($hits, fn ($h) => $h['kind'] === 'fact'));
        $chunks = array_values(array_filter($hits, fn ($h) => $h['kind'] === 'chunk'));

        $block = "--- ЗНАНИЕ: факти от базата знания на фирмата ---\n"
            ."Използвай тези откъси като ДОСТОВЕРЕН източник за фирмата (цени, продукти, условия).\n"
            ."Не си измисляй фирмени факти, които ги няма тук.\n";

        $cap = (int) config('services.knowledge.block_max_chars', 2500);
        $append = function (string $line) use (&$block, $cap): bool {
            if (mb_strlen($block) + mb_strlen($line) > $cap) {
                return false;
            }
            $block .= $line;

            return true;
        };

        foreach ($facts as $fact) {
            $location = $fact['location'] ? ' ('.$fact['location'].')' : '';
            if (! $append('• '.$fact['title'].$location.': '.trim($fact['content'])."\n")) {
                break;
            }
        }

        foreach ($chunks as $i => $chunk) {
            $source = $chunk['url'] ?: ($chunk['source_type'] ?? 'документ');
            if (! $append(($i + 1).'. «'.$chunk['title'].'» ('.$source.'): '.trim($chunk['content'])."\n")) {
                break;
            }
        }

        return rtrim($block);
    }

    /**
     * Compact KB profile for the planner catalog and the builder chip.
     *
     * @return array{documents: int, pages: int, chunks: int, facts: int, folders: array<int, string>, titles: array<int, string>, by_type: array<string, int>}
     */
    public function summary(Company $company): array
    {
        $ready = $company->knowledgeResources()->ready();

        return [
            'documents' => (clone $ready)->count(),
            'pages' => $company->knowledgePages()->where('status', 'ready')->count(),
            'chunks' => $company->knowledgeChunks()->whereNotNull('embedding')->count(),
            'facts' => $company->knowledgeFacts()->active()->count(),
            'folders' => $company->knowledgeFolders()->orderBy('name')->limit(10)->pluck('name')->all(),
            'titles' => (clone $ready)->latest('ingested_at')->limit(10)->pluck('title')->all(),
            'by_type' => $company->knowledgeResources()
                ->selectRaw('type, count(*) as cnt')
                ->groupBy('type')
                ->pluck('cnt', 'type')
                ->map(fn ($cnt) => (int) $cnt)
                ->all(),
        ];
    }

    /** Chunks indexed with a DIFFERENT provider than the current one (UI banner). */
    public function foreignProviderChunks(Company $company): int
    {
        return $company->knowledgeChunks()
            ->whereNotNull('embedding_provider')
            ->where('embedding_provider', '!=', $this->embeddings->providerTag())
            ->count();
    }

    /** Изтриване на ресурс = "забравяне": файл + страници/чанкове + фактите му. */
    public function deleteResource(KnowledgeResource $resource): void
    {
        if ($resource->storage_path && Storage::disk('local')->exists($resource->storage_path)) {
            Storage::disk('local')->delete($resource->storage_path);
        }

        $pageIds = $resource->pages()->pluck('id');

        KnowledgeFact::where('company_id', $resource->company_id)
            ->where(function ($q) use ($resource, $pageIds) {
                $q->where(fn ($qq) => $qq->where('source_type', 'resource')->where('source_id', $resource->id));
                if ($pageIds->isNotEmpty()) {
                    $q->orWhere(fn ($qq) => $qq->where('source_type', 'page')->whereIn('source_id', $pageIds));
                }
            })
            ->delete();

        KnowledgeEvent::log(
            $resource->company_id, 'deleted', 'resource', $resource->id,
            $resource->title, mb_substr((string) ($resource->meta['digest'] ?? ''), 0, 2000),
            'изтрит ресурс ('.$resource->type.')',
        );

        $resource->delete(); // pages + chunks cascade via FK
    }

    /** Изтриване на една страница от url ресурс. */
    public function deletePage(KnowledgePage $page): void
    {
        KnowledgeFact::where('company_id', $page->company_id)
            ->where('source_type', 'page')
            ->where('source_id', $page->id)
            ->delete();

        KnowledgeEvent::log(
            $page->company_id, 'deleted', 'page', $page->id,
            $page->title ?: $page->url, mb_substr((string) $page->digest, 0, 2000),
            'изтрита страница',
            ['url' => $page->url],
        );

        $page->delete(); // chunks cascade
    }

    // ──────────────────────────────────────────────────────────────────────
    // Hybrid internals
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Cosine кандидати: чанкове (cursor scan, един SELECT per company) + факти.
     *
     * @param  array<int, float>  $vector
     * @return array<string, array<string, mixed>> keyed by "chunk:{id}"/"fact:{id}", sorted desc by score
     */
    private function vectorCandidates(Company $company, array $vector, ?array $types, bool $includeFacts, int $limit): array
    {
        $tag = $this->embeddings->providerTag();
        $hits = [];

        $chunks = KnowledgeChunk::query()
            ->join('knowledge_resources as kr', 'kr.id', '=', 'knowledge_chunks.knowledge_resource_id')
            ->where('knowledge_chunks.company_id', $company->id)
            ->where('knowledge_chunks.embedding_provider', $tag)
            ->whereNotNull('knowledge_chunks.embedding')
            ->where('kr.status', 'ready')
            ->when($types !== null, fn ($q) => $q->whereIn('kr.type', $types))
            ->select([
                'knowledge_chunks.id', 'knowledge_chunks.content', 'knowledge_chunks.embedding',
                'knowledge_chunks.meta', 'knowledge_chunks.knowledge_page_id',
                'kr.id as resource_id', 'kr.title as resource_title', 'kr.type as resource_type', 'kr.url as resource_url',
            ])
            ->limit((int) config('services.knowledge.max_scan_chunks', 8000))
            ->cursor();

        foreach ($chunks as $chunk) {
            $score = EmbeddingService::cosine($vector, (array) $chunk->embedding);
            $meta = (array) $chunk->meta;
            $hits['chunk:'.$chunk->id] = [
                'kind' => 'chunk',
                'title' => (string) (($meta['title'] ?? null) ?: $chunk->resource_title),
                'content' => (string) $chunk->content,
                'url' => ($meta['url'] ?? null) ?: $chunk->resource_url,
                'source_type' => (string) $chunk->resource_type,
                'resource_id' => (int) $chunk->resource_id,
                'page_id' => $chunk->knowledge_page_id !== null ? (int) $chunk->knowledge_page_id : null,
                'fact_id' => null,
                'category' => null,
                'location' => null,
                'score' => round($score, 3),
                'match' => 'vector',
            ];
        }

        if ($includeFacts) {
            $facts = $company->knowledgeFacts()
                ->active()
                ->whereNotNull('embedding')
                ->where('embedding_provider', $tag)
                ->latest('id')
                ->limit(4000)
                ->get();

            foreach ($facts as $fact) {
                $score = EmbeddingService::cosine($vector, (array) $fact->embedding);
                $hits['fact:'.$fact->id] = $this->factHit($fact, $score, 'vector');
            }
        }

        uasort($hits, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($hits, 0, $limit, true);
    }

    /**
     * Keyword кандидати през FULLTEXT (LIKE fallback) — точните низове, които
     * embeddings изпускат: цени, кодове, имена на процедури.
     *
     * @return array<string, array<string, mixed>>
     */
    private function keywordCandidates(Company $company, string $query, ?array $types, bool $includeFacts, int $limit): array
    {
        $hits = [];

        try {
            $chunks = KnowledgeChunk::query()
                ->join('knowledge_resources as kr', 'kr.id', '=', 'knowledge_chunks.knowledge_resource_id')
                ->where('knowledge_chunks.company_id', $company->id)
                ->where('kr.status', 'ready')
                ->when($types !== null, fn ($q) => $q->whereIn('kr.type', $types))
                ->whereRaw('MATCH(knowledge_chunks.content) AGAINST (? IN NATURAL LANGUAGE MODE)', [$query])
                ->select([
                    'knowledge_chunks.id', 'knowledge_chunks.content', 'knowledge_chunks.meta',
                    'knowledge_chunks.knowledge_page_id',
                    'kr.id as resource_id', 'kr.title as resource_title', 'kr.type as resource_type', 'kr.url as resource_url',
                ])
                ->selectRaw('MATCH(knowledge_chunks.content) AGAINST (? IN NATURAL LANGUAGE MODE) as ft_score', [$query])
                ->orderByDesc('ft_score')
                ->limit($limit)
                ->get();
        } catch (\Throwable) {
            $chunks = $this->likeFallbackChunks($company, $query, $types, $limit);
        }

        foreach ($chunks as $chunk) {
            $meta = (array) $chunk->meta;
            $hits['chunk:'.$chunk->id] = [
                'kind' => 'chunk',
                'title' => (string) (($meta['title'] ?? null) ?: $chunk->resource_title),
                'content' => (string) $chunk->content,
                'url' => ($meta['url'] ?? null) ?: $chunk->resource_url,
                'source_type' => (string) $chunk->resource_type,
                'resource_id' => (int) $chunk->resource_id,
                'page_id' => $chunk->knowledge_page_id !== null ? (int) $chunk->knowledge_page_id : null,
                'fact_id' => null,
                'category' => null,
                'location' => null,
                'score' => 0.0,
                'match' => 'keyword',
            ];
        }

        if ($includeFacts) {
            try {
                $facts = $company->knowledgeFacts()
                    ->active()
                    ->whereRaw('MATCH(name, value) AGAINST (? IN NATURAL LANGUAGE MODE)', [$query])
                    ->limit($limit)
                    ->get();
            } catch (\Throwable) {
                $needle = mb_substr($query, 0, 80);
                $facts = $company->knowledgeFacts()
                    ->active()
                    ->where(fn ($q) => $q->where('name', 'like', "%{$needle}%")->orWhere('value', 'like', "%{$needle}%"))
                    ->limit($limit)
                    ->get();
            }

            foreach ($facts as $fact) {
                $hits['fact:'.$fact->id] = $this->factHit($fact, 0.0, 'keyword');
            }
        }

        return $hits;
    }

    /** LIKE fallback когато FULLTEXT не е наличен (стара MySQL конфигурация). */
    private function likeFallbackChunks(Company $company, string $query, ?array $types, int $limit)
    {
        $words = array_filter(
            preg_split('/\s+/u', $query) ?: [],
            fn ($w) => mb_strlen($w) >= 4,
        );
        usort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $words = array_slice($words, 0, 3);

        if ($words === []) {
            return collect();
        }

        return KnowledgeChunk::query()
            ->join('knowledge_resources as kr', 'kr.id', '=', 'knowledge_chunks.knowledge_resource_id')
            ->where('knowledge_chunks.company_id', $company->id)
            ->where('kr.status', 'ready')
            ->when($types !== null, fn ($q) => $q->whereIn('kr.type', $types))
            ->where(function ($q) use ($words) {
                foreach ($words as $word) {
                    $q->orWhere('knowledge_chunks.content', 'like', '%'.$word.'%');
                }
            })
            ->select([
                'knowledge_chunks.id', 'knowledge_chunks.content', 'knowledge_chunks.meta',
                'knowledge_chunks.knowledge_page_id',
                'kr.id as resource_id', 'kr.title as resource_title', 'kr.type as resource_type', 'kr.url as resource_url',
            ])
            ->limit($limit)
            ->get();
    }

    /** @return array<string, mixed> */
    private function factHit(KnowledgeFact $fact, float $score, string $match): array
    {
        return [
            'kind' => 'fact',
            'title' => (string) $fact->name,
            'content' => (string) $fact->value,
            'url' => null,
            'source_type' => 'fact',
            'resource_id' => null,
            'page_id' => null,
            'fact_id' => (int) $fact->id,
            'category' => (string) $fact->category,
            'location' => $fact->location,
            'score' => round($score, 3),
            'match' => $match,
        ];
    }

    /**
     * Reciprocal Rank Fusion на двата списъка. Фактите получават лек boost —
     * те са дестилираният, актуален профил на фирмата.
     *
     * @param  array<string, array<string, mixed>>  $vectorHits  sorted desc
     * @param  array<string, array<string, mixed>>  $keywordHits
     * @return array<int, array<string, mixed>>
     */
    private function rrfMerge(array $vectorHits, array $keywordHits, int $topK): array
    {
        $scores = [];
        $items = [];

        $rank = 0;
        foreach ($vectorHits as $key => $hit) {
            $scores[$key] = ($scores[$key] ?? 0) + 1 / (self::RRF_K + ++$rank);
            $items[$key] = $hit;
        }

        $rank = 0;
        foreach ($keywordHits as $key => $hit) {
            $scores[$key] = ($scores[$key] ?? 0) + 1 / (self::RRF_K + ++$rank);
            if (isset($items[$key])) {
                $items[$key]['match'] = 'both';
                if ($hit['score'] > $items[$key]['score']) {
                    $items[$key]['score'] = $hit['score'];
                }
            } else {
                $items[$key] = $hit;
            }
        }

        foreach ($scores as $key => $score) {
            if ($items[$key]['kind'] === 'fact') {
                $scores[$key] = $score * 1.2;
            }
        }

        arsort($scores);

        $out = [];
        foreach (array_keys(array_slice($scores, 0, $topK, true)) as $key) {
            $out[] = $items[$key];
        }

        return $out;
    }
}
