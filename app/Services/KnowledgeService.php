<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Flow;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeGap;
use App\Services\Knowledge\DocumentTextExtractor;
use App\Services\Knowledge\TextChunker;
use App\Support\LlmUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * База знания на фирмата (per-Company RAG) — the durable counterpart of
 * FlowMemoryService: memory remembers what a flow PRODUCED, knowledge holds
 * what is TRUE about the company (uploaded documents, its website, distilled
 * run history).
 *
 * Consumption is dual-channel:
 *  - knowledge_search agent tool (paid models pick queries themselves);
 *  - "ЗНАНИЕ" prompt block injected by NodeExecutorService for content nodes
 *    (works for local models that cannot tool-call).
 * Both only ever ground on GROUNDING_TYPES — run history is tool-only.
 */
class KnowledgeService
{
    private EmbeddingService $embeddings;

    public function __construct(
        EmbeddingService $embeddings,
        private TextChunker $chunker,
        private DocumentTextExtractor $extractor,
    ) {
        $this->embeddings = $embeddings->withProvider(config('services.knowledge.embedding_provider'));
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
        return ! $company->knowledgeDocuments()->ready()->grounding()->exists();
    }

    public function providerTag(): string
    {
        return $this->embeddings->providerTag();
    }

    /**
     * Extract → chunk → embed → store. Called only from the ingest job with
     * the document already claimed (status=processing). Throws on extraction
     * failure — the job translates that into status=failed.
     */
    public function ingest(KnowledgeDocument $document): void
    {
        $text = $this->extractor->extract($document);
        $hash = hash('sha256', $text);
        $tag = $this->embeddings->providerTag();

        // Unchanged content already embedded with the CURRENT provider → the
        // expensive pass is a no-op (this is what makes site re-crawl cheap).
        $unchanged = $hash === $document->content_hash
            && $document->chunk_count > 0
            && $document->chunks()->where('embedding_provider', $tag)->exists();

        if ($unchanged) {
            LlmUsage::take();
            $document->update(['status' => 'ready', 'error' => null, 'ingested_at' => now()]);

            return;
        }

        $llmContext = [
            'company_id' => $document->company_id,
            'knowledge_document_id' => $document->id,
        ];

        $rows = [];
        $embedded = 0;
        foreach ($this->chunker->chunk($text) as $seq => $chunk) {
            $vector = $this->embeddings->embed($chunk['content'], $llmContext);
            if ($vector !== null) {
                $embedded++;
            }

            $rows[] = [
                'knowledge_document_id' => $document->id,
                'company_id' => $document->company_id,
                'seq' => $seq,
                'content' => $chunk['content'],
                'embedding' => $vector !== null ? json_encode($vector) : null,
                'embedding_provider' => $vector !== null ? $tag : null,
                'meta' => $chunk['meta'] !== [] ? json_encode($chunk['meta']) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::transaction(function () use ($document, $rows) {
            KnowledgeChunk::where('knowledge_document_id', $document->id)->delete();
            foreach (array_chunk($rows, 100) as $batch) {
                KnowledgeChunk::insert($batch);
            }
        });

        $usage = LlmUsage::take();
        $failedEmbeddings = $rows !== [] && $embedded === 0;

        $document->update([
            'status' => $failedEmbeddings ? 'failed' : 'ready',
            'error' => $failedEmbeddings
                ? 'Embedding провайдърът ('.$tag.') не върна вектори — документът е видим, но не се търси.'
                : null,
            'content_hash' => $hash,
            'chunk_count' => count($rows),
            'cost_usd' => round((float) $document->cost_usd + (float) ($usage['cost_usd'] ?? 0), 6),
            'ingested_at' => now(),
            'meta' => array_merge((array) $document->meta, ['chars' => mb_strlen($text)]),
        ]);
    }

    /**
     * Cosine top-K over the company's chunks (current provider tag only).
     *
     * @param  array<int, string>  $sourceTypes
     * @param  array<string, mixed>  $llmContext
     * @return array<int, array{document_id: int, title: string, source_type: string, source_url: ?string, seq: int, content: string, score: float}>
     */
    public function search(
        Company $company,
        string $query,
        array $sourceTypes = KnowledgeDocument::GROUNDING_TYPES,
        ?int $topK = null,
        array $llmContext = [],
        ?int $flowRunId = null,
        ?string $nodeKey = null,
        bool $logGaps = true,
    ): array {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $vector = $this->embeddings->embed($query, array_merge(['company_id' => $company->id], $llmContext));
        if ($vector === null) {
            return [];
        }

        $topK = max(1, $topK ?? (int) config('services.knowledge.top_k', 5));
        $tag = $this->embeddings->providerTag();

        $chunks = KnowledgeChunk::query()
            ->join('knowledge_documents as kd', 'kd.id', '=', 'knowledge_chunks.knowledge_document_id')
            ->where('knowledge_chunks.company_id', $company->id)
            ->where('knowledge_chunks.embedding_provider', $tag)
            ->whereNotNull('knowledge_chunks.embedding')
            ->where('kd.status', 'ready')
            ->whereIn('kd.source_type', $sourceTypes)
            ->select([
                'knowledge_chunks.seq', 'knowledge_chunks.content', 'knowledge_chunks.embedding',
                'kd.id as document_id', 'kd.title', 'kd.source_type', 'kd.source_url',
            ])
            ->limit((int) config('services.knowledge.max_scan_chunks', 8000))
            ->cursor();

        $top = [];
        foreach ($chunks as $chunk) {
            $score = EmbeddingService::cosine($vector, (array) $chunk->embedding);
            $top[] = [
                'document_id' => (int) $chunk->document_id,
                'title' => (string) $chunk->title,
                'source_type' => (string) $chunk->source_type,
                'source_url' => $chunk->source_url,
                'seq' => (int) $chunk->seq,
                'content' => (string) $chunk->content,
                'score' => round($score, 3),
            ];
            // Keep the working set small without a heap dependency.
            if (count($top) > $topK * 4) {
                usort($top, fn ($a, $b) => $b['score'] <=> $a['score']);
                $top = array_slice($top, 0, $topK);
            }
        }

        usort($top, fn ($a, $b) => $b['score'] <=> $a['score']);
        $top = array_slice($top, 0, $topK);

        // "Пропуск": grounding търсене без добро покритие — сигнал какво да се
        // качи. Никога не проваля търсенето.
        if ($logGaps && $sourceTypes === KnowledgeDocument::GROUNDING_TYPES) {
            $best = $top[0]['score'] ?? null;
            if ($best === null || $best < (float) config('services.knowledge.gap_threshold', 0.55)) {
                try {
                    KnowledgeGap::create([
                        'company_id' => $company->id,
                        'flow_run_id' => $flowRunId,
                        'node_key' => $nodeKey,
                        'query' => mb_substr($query, 0, 500),
                        'best_score' => $best,
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
        }

        return $top;
    }

    /**
     * "ЗНАНИЕ" prompt block for a content node — grounding collections only,
     * empty string when there is nothing relevant. Never throws.
     *
     * @param  array<string, mixed>  $llmContext
     */
    public function knowledgeBlock(Company $company, string $renderedInput, array $llmContext = []): string
    {
        if ($this->isEmpty($company)) {
            return '';
        }

        $hits = $this->search(
            $company,
            mb_substr($renderedInput, 0, 2000),
            KnowledgeDocument::GROUNDING_TYPES,
            llmContext: $llmContext,
            flowRunId: $llmContext['flow_run_id'] ?? null,
            logGaps: false,
        );

        $minScore = (float) config('services.knowledge.min_score', 0.25);
        $hits = array_values(array_filter($hits, fn ($hit) => $hit['score'] >= $minScore));
        if ($hits === []) {
            return '';
        }

        $block = "--- ЗНАНИЕ: факти от базата знания на фирмата ---\n"
            ."Използвай тези откъси като ДОСТОВЕРЕН източник за фирмата (цени, продукти, условия).\n"
            ."Не си измисляй фирмени факти, които ги няма тук.\n";

        $cap = (int) config('services.knowledge.block_max_chars', 2500);
        foreach ($hits as $i => $hit) {
            $source = $hit['source_type'] === 'site' ? 'сайт' : 'документ';
            $line = ($i + 1).'. «'.$hit['title'].'» ('.$source.'): '.trim($hit['content'])."\n";
            if (mb_strlen($block) + mb_strlen($line) > $cap) {
                break;
            }
            $block .= $line;
        }

        return rtrim($block);
    }

    /**
     * Compact KB profile for the planner catalog and the builder chip.
     *
     * @return array{documents: int, chunks: int, folders: array<int, string>, titles: array<int, string>, by_source: array<string, int>}
     */
    public function summary(Company $company): array
    {
        $grounding = $company->knowledgeDocuments()->ready()->grounding();

        return [
            'documents' => (clone $grounding)->count(),
            'chunks' => $company->knowledgeChunks()->whereNotNull('embedding')->count(),
            'folders' => $company->knowledgeFolders()->orderBy('name')->limit(10)->pluck('name')->all(),
            'titles' => (clone $grounding)->latest('ingested_at')->limit(10)->pluck('title')->all(),
            'by_source' => $company->knowledgeDocuments()
                ->selectRaw('source_type, count(*) as cnt')
                ->groupBy('source_type')
                ->pluck('cnt', 'source_type')
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

    public function deleteDocument(KnowledgeDocument $document): void
    {
        if ($document->storage_path && Storage::disk('local')->exists($document->storage_path)) {
            Storage::disk('local')->delete($document->storage_path);
        }

        $document->delete(); // chunks cascade via FK
    }
}
