<?php

namespace App\Console\Commands;

use App\Models\WebPageCache;
use App\Models\WebPageDigest;
use Illuminate\Console\Command;

/**
 * Daily маинтенанс на глобалния scrape кеш: записи, които никой не е
 * докосвал от cache_retention_days, излизат заедно с дайджестите им.
 */
class PruneWebCache extends Command
{
    protected $signature = 'knowledge:prune-web-cache';

    protected $description = 'Delete web page cache entries (and their digests) unused beyond the retention window';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('services.crawl.cache_retention_days', 90));

        $staleHashes = WebPageCache::where('last_checked_at', '<', $cutoff)->pluck('url_hash');

        $digests = $staleHashes->isEmpty()
            ? 0
            : WebPageDigest::whereIn('url_hash', $staleHashes)->delete();
        $pages = WebPageCache::where('last_checked_at', '<', $cutoff)->delete();

        // Дайджести, чиято кеширана страница е сменила съдържанието си,
        // остаряват сами (lookup-ът е по content_hash) — чистим и тях.
        $orphans = WebPageDigest::where('updated_at', '<', $cutoff)->delete();

        $this->info("Pruned {$pages} cached page(s), {$digests} digest(s), {$orphans} stale digest(s).");

        return self::SUCCESS;
    }
}
