<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\CrawlService;
use App\Services\KnowledgeService;
use App\Services\WebPageCacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Sync the company's website into the knowledge base (source_type=site):
 * discover pages → scrape (through the web cache) → per page, an unchanged
 * content hash skips the expensive re-chunk/re-embed entirely; changed/new
 * pages go through the единствения ingest path (IngestKnowledgeDocumentJob).
 * Pages that disappeared from the site are removed — no stale fallbacks.
 */
class IngestCompanySiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $companyId, public bool $force = false) {}

    public function handle(CrawlService $crawl, WebPageCacheService $urls, KnowledgeService $knowledge): void
    {
        $lock = Cache::lock("kb-site-ingest-{$this->companyId}", 900);
        if (! $lock->get()) {
            return; // вече тече синхронизация за тази фирма
        }

        try {
            $company = Company::find($this->companyId);
            if (! $company || empty($company->website_url) || ! KnowledgeService::enabled($company)) {
                return;
            }

            $maxPages = (int) config('services.knowledge.site_max_pages', 30);
            $discovered = $crawl->discoverUrls($company->website_url, $maxPages);
            if ($discovered === []) {
                Log::warning("[KnowledgeSite] Няма открити страници за {$company->website_url}");

                return;
            }

            $pages = $crawl->scrapeMany($discovered, 4, bypassCache: $this->force);

            $seenHashes = [];
            foreach ($pages as $url => $markdown) {
                $srcHash = $urls->urlHash($url);
                if ($srcHash === null || trim($markdown) === '') {
                    continue;
                }
                $seenHashes[] = $srcHash;
                // Същата канонизация като KnowledgeService::ingest (extract-ът
                // trim-ва) — иначе hash-skip-ът никога не улучва.
                $contentHash = hash('sha256', trim($markdown));

                $document = $company->knowledgeDocuments()
                    ->where('source_type', 'site')
                    ->where('source_url_hash', $srcHash)
                    ->first();

                // Непроменена страница → нищо за правене (евтиното на кеша).
                if ($document && $document->status === 'ready' && $document->content_hash === $contentHash) {
                    continue;
                }

                $path = "knowledge/{$company->id}/site/{$srcHash}.md";
                Storage::disk('local')->put($path, $markdown);

                $attributes = [
                    'title' => $this->titleFrom($markdown, $url),
                    'source_url' => mb_substr($url, 0, 2048),
                    'mime' => 'text/markdown',
                    'size_bytes' => strlen($markdown),
                    'storage_path' => $path,
                    'status' => 'pending',
                    'error' => null,
                ];

                if ($document) {
                    $document->update($attributes);
                } else {
                    $document = $company->knowledgeDocuments()->create($attributes + [
                        'source_type' => 'site',
                        'source_url_hash' => $srcHash,
                    ]);
                }

                IngestKnowledgeDocumentJob::dispatch($document->id);
            }

            // Страници, които вече ги няма на сайта → вън (вкл. файловете им).
            if ($seenHashes !== []) {
                $company->knowledgeDocuments()
                    ->where('source_type', 'site')
                    ->whereNotIn('source_url_hash', $seenHashes)
                    ->get()
                    ->each(fn ($doc) => $knowledge->deleteDocument($doc));
            }

            $settings = (array) ($company->settings ?? []);
            $settings['knowledge'] = array_merge((array) ($settings['knowledge'] ?? []), [
                'site_synced_at' => now()->toISOString(),
            ]);
            $company->update(['settings' => $settings]);
        } finally {
            $lock->release();
        }
    }

    private function titleFrom(string $markdown, string $url): string
    {
        // Пътят е по-надежден от първия heading — много сайтове повтарят
        // header/адрес като първо заглавие на ВСЯКА страница.
        $path = trim(urldecode((string) parse_url($url, PHP_URL_PATH)), '/');
        if ($path !== '') {
            return mb_substr(str_replace(['-', '_', '/'], [' ', ' ', ' › '], $path), 0, 300);
        }

        foreach (preg_split('/\r\n|\r|\n/', $markdown) ?: [] as $line) {
            if (preg_match('/^#{1,3}\s+(.+)/u', trim($line), $m)) {
                return mb_substr('Начало — '.trim($m[1]), 0, 300);
            }
        }

        return mb_substr(parse_url($url, PHP_URL_HOST) ?: $url, 0, 300);
    }
}
