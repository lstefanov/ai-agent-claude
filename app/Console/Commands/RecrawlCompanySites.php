<?php

namespace App\Console\Commands;

use App\Jobs\IngestCompanySiteJob;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Scheduled re-sync of company websites into their knowledge bases. Runs
 * hourly; each company opts in via settings.knowledge.recrawl (daily|weekly).
 * Thresholds sit slightly under the nominal period so an hourly tick never
 * misses its window (22h ≈ daily, 6.5d ≈ weekly).
 */
class RecrawlCompanySites extends Command
{
    protected $signature = 'knowledge:recrawl-sites';

    protected $description = 'Dispatch site re-crawl jobs for companies with scheduled knowledge refresh';

    public function handle(): int
    {
        $dispatched = 0;

        Company::whereNotNull('website_url')->get()->each(function (Company $company) use (&$dispatched) {
            $knowledge = (array) ($company->settings['knowledge'] ?? []);
            $recrawl = $knowledge['recrawl'] ?? 'off';

            $threshold = match ($recrawl) {
                'daily' => now()->subHours(22),
                'weekly' => now()->subHours(156),
                default => null,
            };

            if ($threshold === null) {
                return;
            }

            $syncedAt = isset($knowledge['site_synced_at'])
                ? Carbon::parse($knowledge['site_synced_at'])
                : null;

            if ($syncedAt === null || $syncedAt->lt($threshold)) {
                IngestCompanySiteJob::dispatch($company->id);
                $dispatched++;
            }
        });

        $this->info("Dispatched {$dispatched} site re-crawl job(s).");

        return self::SUCCESS;
    }
}
