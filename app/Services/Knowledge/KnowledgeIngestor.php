<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeEvent;
use App\Models\KnowledgeFact;
use App\Models\KnowledgePage;
use App\Models\KnowledgeResource;
use App\Services\CrawlService;
use App\Services\EmbeddingService;
use App\Support\LlmUsage;
use App\Support\PageContent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ingest на РЕСУРС в базата знания — извикван само от ingest job-овете с
 * вече claim-нат ресурс (status=processing). Throws → job-ът превежда в
 * status=failed.
 *
 * - note/upload/image: текст → синтез (digest + факти) → чанкове/embeddings;
 * - url: BFS обхождане (CrawlService) → per-page digest (с глобален reuse по
 *   content_hash — непроменена страница не струва LLM call) → чанкове per
 *   страница → факти → events → авто-резолюция на пропуски.
 */
class KnowledgeIngestor
{
    /** Бюджет за BFS фазата — останалото от timeout-а отива за синтез/embeddings. */
    private const CRAWL_BUDGET_SECONDS = 660;

    /**
     * Версия на embedding входа (контекст + съдържание). Bump-ва се при
     * промяна на това КАКВО се embed-ва — старите чанкове спират да минават
     * "unchanged" проверките и следващият re-ingest ги преизчислява (digest
     * кешът остава валиден → струва само локални bge-m3 calls, не LLM).
     */
    private const EMBED_VERSION = 2;

    private const MAX_CONTENT_CHUNKS_PER_PAGE = 30;

    private EmbeddingService $embeddings;

    public function __construct(
        EmbeddingService $embeddings,
        private TextChunker $chunker,
        private DocumentTextExtractor $extractor,
        private KnowledgeSynthesizer $synthesizer,
        private KnowledgeFactService $facts,
        private KnowledgeGapService $gaps,
        private CrawlService $crawl,
    ) {
        $this->embeddings = $embeddings->withProvider(
            config('services.knowledge.embedding_provider'),
            config('services.knowledge.embedding_model'),
        );
    }

    public function providerTag(): string
    {
        return $this->embeddings->providerTag();
    }

    // ──────────────────────────────────────────────────────────────────────
    // note / upload / image
    // ──────────────────────────────────────────────────────────────────────

    public function ingestResource(KnowledgeResource $resource): void
    {
        $company = $resource->company;
        $isFirst = $resource->ingested_at === null;

        $text = in_array($resource->type, ['note', 'chat'], true)
            ? trim((string) $resource->content)
            : trim($this->extractor->extract($resource));

        if ($text === '') {
            throw new RuntimeException('От ресурса не беше извлечен никакъв текст.');
        }

        $hash = hash('sha256', $text);
        $tag = $this->embeddings->providerTag();

        // Непроменено съдържание, вече embed-нато с ТЕКУЩИЯ провайдър и със
        // синтезиран digest → скъпият pass е no-op (re-ingest бутонът е евтин).
        $unchanged = $hash === ($resource->meta['content_hash'] ?? null)
            && $resource->chunk_count > 0
            && ! empty($resource->meta['digest'])
            && $resource->chunks()
                ->where('embedding_provider', $tag)
                ->where('meta->embed_v', self::EMBED_VERSION)
                ->exists();

        if ($unchanged) {
            LlmUsage::take();
            $resource->update(['status' => 'ready', 'error' => null, 'ingested_at' => now()]);

            return;
        }

        $llmContext = ['company_id' => $company->id, 'knowledge_resource_id' => $resource->id];

        $synth = $this->synthesizer->synthesizeDocument($company, $resource->title, $text, $llmContext);

        $sections = [];
        foreach ($this->chunker->chunk($synth['digest']) as $chunk) {
            $sections[] = ['content' => $chunk['content'], 'meta' => $chunk['meta'] + ['section' => 'digest']];
        }
        foreach ($this->chunker->chunk($text) as $chunk) {
            $sections[] = ['content' => $chunk['content'], 'meta' => $chunk['meta'] + ['section' => 'content']];
        }

        [$rows, $vectors] = $this->embedSections($sections, $resource, null, $llmContext);

        DB::transaction(function () use ($resource, $rows) {
            KnowledgeChunk::where('knowledge_resource_id', $resource->id)->delete();
            foreach (array_chunk($rows, 100) as $batch) {
                KnowledgeChunk::insert($batch);
            }
        });

        $sourceLabel = match ($resource->type) {
            'note' => 'бележка „'.$resource->title.'“',
            'chat' => 'чат „'.$resource->title.'“',
            'image' => 'снимка „'.($resource->original_name ?: $resource->title).'“',
            default => 'качен файл „'.($resource->original_name ?: $resource->title).'“',
        };

        $factStats = $this->facts->upsertMany(
            $company, $synth['facts'], 'resource', $resource->id, null, $sourceLabel, $llmContext,
        );

        $this->gaps->resolveAgainstVectors($company, array_slice($vectors, 0, 500), "resource:{$resource->id}");

        $usage = LlmUsage::take();
        $failedEmbeddings = $rows !== [] && $vectors === [];

        $resource->update([
            'status' => $failedEmbeddings ? 'failed' : 'ready',
            'error' => $failedEmbeddings
                ? 'Embedding провайдърът ('.$tag.') не върна вектори — ресурсът е видим, но не се търси.'
                : null,
            'chunk_count' => count($rows),
            'cost_usd' => round((float) $resource->cost_usd + (float) ($usage['cost_usd'] ?? 0), 6),
            'ingested_at' => now(),
            'meta' => array_merge((array) $resource->meta, [
                'content_hash' => $hash,
                'chars' => mb_strlen($text),
                'digest' => $synth['digest'],
                'facts' => $factStats,
            ]),
        ]);

        KnowledgeEvent::log(
            $company->id, $isFirst ? 'added' : 'updated', 'resource', $resource->id,
            $resource->title, mb_substr($synth['digest'], 0, 2000), $sourceLabel,
            ['type' => $resource->type, 'chunks' => count($rows), 'facts' => $factStats],
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // url (BFS crawl)
    // ──────────────────────────────────────────────────────────────────────

    public function ingestUrlResource(KnowledgeResource $resource, bool $force = false): void
    {
        $company = $resource->company;
        $isFirst = $resource->ingested_at === null;
        $tag = $this->embeddings->providerTag();
        $llmContext = ['company_id' => $company->id, 'knowledge_resource_id' => $resource->id];

        $deadline = microtime(true) + self::CRAWL_BUDGET_SECONDS;
        $progressTick = 0;

        $pages = $this->crawl->crawlSiteBfs(
            (string) $resource->url,
            (int) config('services.knowledge.site_max_pages', 200),
            bypassCache: $force,
            deadlineTs: $deadline,
            onProgress: function (int $parsed, int $discovered) use ($resource, &$progressTick) {
                if (++$progressTick % 5 === 0) {
                    $resource->update(['meta' => array_merge((array) $resource->meta, [
                        'progress' => ['parsed' => $parsed, 'discovered' => $discovered],
                    ])]);
                }
            },
        );

        if ($pages === []) {
            throw new RuntimeException(
                'Не успях да обходя нито една страница от '.$resource->url
                .' — провери дали crawl service-ът работи (scripts/start-services.sh status).'
            );
        }

        $partial = microtime(true) >= $deadline;

        $existingByHash = $resource->pages()->get()->keyBy('url_hash');
        $seenHashes = [];
        $allVectors = [];
        $parsed = 0;
        $reusedDigests = 0;

        foreach ($pages as $page) {
            $urlHash = hash('sha256', $page['url']);
            $seenHashes[$urlHash] = true;

            /** @var KnowledgePage|null $existing */
            $existing = $existingByHash[$urlHash] ?? null;

            // Непроменена страница с готов digest и актуални embeddings → нула работа.
            if (! $force
                && $existing
                && $existing->content_hash === $page['content_hash']
                && trim((string) $existing->digest) !== ''
                && $existing->chunks()
                    ->where('embedding_provider', $tag)
                    ->where('meta->embed_v', self::EMBED_VERSION)
                    ->exists()) {
                continue;
            }

            $synth = $this->synthesizer->synthesizePage(
                $company, $page['url'], $page['title'], $page['meta_description'], $page['markdown'], $llmContext,
            );
            $reusedDigests += $synth['reused'] ? 1 : 0;

            $pageModel = $resource->pages()->updateOrCreate(
                ['url_hash' => $urlHash],
                [
                    'company_id' => $company->id,
                    'url' => mb_substr($page['url'], 0, 2048),
                    'title' => $page['title'] !== null ? mb_substr($page['title'], 0, 500) : null,
                    'meta_description' => $page['meta_description'],
                    'content_hash' => $page['content_hash'],
                    'digest' => $synth['digest'],
                    'status' => 'ready',
                    'error' => null,
                    'parsed_at' => now(),
                    'meta' => [
                        'links_count' => count($page['links']),
                        'digest_reused' => $synth['reused'],
                    ],
                ],
            );

            $sections = [];
            foreach ($this->chunker->chunk($synth['digest']) as $chunk) {
                $sections[] = ['content' => $chunk['content'], 'meta' => $chunk['meta'] + [
                    'section' => 'digest', 'url' => $page['url'], 'title' => $page['title'],
                ]];
            }
            $contentChunks = $this->chunker->chunk(PageContent::stripBoilerplate($page['markdown']));
            foreach (array_slice($contentChunks, 0, self::MAX_CONTENT_CHUNKS_PER_PAGE) as $chunk) {
                $sections[] = ['content' => $chunk['content'], 'meta' => $chunk['meta'] + [
                    'section' => 'content', 'url' => $page['url'], 'title' => $page['title'],
                ]];
            }

            [$rows, $vectors] = $this->embedSections($sections, $resource, $pageModel->id, $llmContext);
            $allVectors = array_merge($allVectors, array_slice($vectors, 0, 3));

            DB::transaction(function () use ($pageModel, $rows) {
                KnowledgeChunk::where('knowledge_page_id', $pageModel->id)->delete();
                foreach (array_chunk($rows, 100) as $batch) {
                    KnowledgeChunk::insert($batch);
                }
            });

            $this->facts->upsertMany(
                $company, $synth['facts'], 'page', $pageModel->id, null,
                'обхождане: '.$page['url'], $llmContext,
            );

            KnowledgeEvent::log(
                $company->id, $existing ? 'updated' : 'added', 'page', $pageModel->id,
                $page['title'] ?: $page['url'], mb_substr($synth['digest'], 0, 2000),
                'crawl на '.$resource->url,
                ['url' => $page['url'], 'facts' => count($synth['facts'])],
            );

            $parsed++;
        }

        // Изчезнали от сайта страници → "забравяне" (само при ПЪЛНО обхождане —
        // частичен crawl не бива да трие истински страници).
        if (! $partial && ! $force) {
            foreach ($existingByHash as $urlHash => $existing) {
                if (isset($seenHashes[$urlHash])) {
                    continue;
                }
                KnowledgeEvent::log(
                    $company->id, 'deleted', 'page', $existing->id,
                    $existing->title ?: $existing->url, mb_substr((string) $existing->digest, 0, 2000),
                    'страницата вече не съществува на сайта',
                    ['url' => $existing->url],
                );
                KnowledgeFact::where('company_id', $company->id)
                    ->where('source_type', 'page')
                    ->where('source_id', $existing->id)
                    ->delete();
                $existing->delete(); // чанковете падат по cascade FK
            }
        }

        $this->gaps->resolveAgainstVectors($company, array_slice($allVectors, 0, 500), "resource:{$resource->id}");

        $usage = LlmUsage::take();
        $meta = array_merge((array) $resource->meta, [
            'pages_total' => $resource->pages()->count(),
            'pages_parsed' => $parsed,
            'digests_reused' => $reusedDigests,
            'partial' => $partial,
        ]);
        unset($meta['progress']);

        $resource->update([
            'status' => 'ready',
            'error' => null,
            'chunk_count' => $resource->chunks()->count(),
            'cost_usd' => round((float) $resource->cost_usd + (float) ($usage['cost_usd'] ?? 0), 6),
            'ingested_at' => now(),
            'meta' => $meta,
        ]);

        KnowledgeEvent::log(
            $company->id, $isFirst ? 'added' : 'updated', 'resource', $resource->id,
            $resource->title,
            $meta['pages_total'].' страници ('.$parsed.' обработени'.($partial ? ', частично — пусни отново' : '').')',
            'обхождане на '.$resource->url,
            ['pages_total' => $meta['pages_total'], 'pages_parsed' => $parsed, 'partial' => $partial],
        );
    }

    // ──────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array{content: string, meta: array<string, mixed>}>  $sections
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<int, float>>}
     */
    private function embedSections(array $sections, KnowledgeResource $resource, ?int $pageId, array $llmContext): array
    {
        $tag = $this->embeddings->providerTag();
        $rows = [];
        $vectors = [];

        foreach ($sections as $seq => $section) {
            // Контекстуален embedding: векторът кодира и заглавието на
            // страницата/ресурса, не само текста на чанка — иначе чанк като
            // "Възрастово ограничение: 18+" от страница "Лазерна епилация
            // подмишници" е семантично откачен от услугата си и заявка за
            // услугата не го намира. Съхраненото съдържание остава чисто.
            $context = trim((string) ((($section['meta']['title'] ?? null) ?: $resource->title)));
            $embedInput = $context !== '' && ! str_starts_with($section['content'], $context)
                ? $context."\n".$section['content']
                : $section['content'];

            $vector = $this->embeddings->embed($embedInput, $llmContext);
            if ($vector !== null) {
                $vectors[] = $vector;
            }

            $rows[] = [
                'company_id' => $resource->company_id,
                'knowledge_resource_id' => $resource->id,
                'knowledge_page_id' => $pageId,
                'seq' => $seq,
                'content' => $section['content'],
                'embedding' => $vector !== null ? json_encode($vector) : null,
                'embedding_provider' => $vector !== null ? $tag : null,
                'meta' => json_encode($section['meta'] + ['embed_v' => self::EMBED_VERSION], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return [$rows, $vectors];
    }
}
