<?php

namespace App\Console\Commands\Org;

use App\Jobs\Org\OrgReviewJob;
use App\Models\Company;
use Illuminate\Console\Command;

/**
 * Седмично ревю на Управителя (§7.1) — образец RunScheduledFlows. Диспечира OrgReviewJob
 * за всяка фирма с активна организация.
 */
class RunOrgReview extends Command
{
    protected $signature = 'org:review';

    protected $description = 'Dispatch the manager periodic review for each company with an active organization';

    public function handle(): int
    {
        $companies = Company::whereNotNull('active_org_version_id')->pluck('id');

        foreach ($companies as $companyId) {
            OrgReviewJob::dispatch($companyId)->onQueue('org');
        }

        $this->info('Org reviews dispatched: '.$companies->count());

        return self::SUCCESS;
    }
}
