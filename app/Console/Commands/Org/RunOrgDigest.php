<?php

namespace App\Console\Commands\Org;

use App\Jobs\Org\OrgDigestJob;
use App\Models\Company;
use Illuminate\Console\Command;

/**
 * Дневен „standup" дайджест (слой живост §3) — образец RunOrgReview. Диспечира OrgDigestJob
 * за всяка фирма с активна организация (планиран всеки ден в routes/console.php).
 */
class RunOrgDigest extends Command
{
    protected $signature = 'org:digest';

    protected $description = 'Dispatch the daily standup digest for each company with an active organization';

    public function handle(): int
    {
        $companies = Company::whereNotNull('active_org_version_id')->pluck('id');

        foreach ($companies as $companyId) {
            OrgDigestJob::dispatch($companyId)->onQueue('org');
        }

        $this->info('Org digests dispatched: '.$companies->count());

        return self::SUCCESS;
    }
}
