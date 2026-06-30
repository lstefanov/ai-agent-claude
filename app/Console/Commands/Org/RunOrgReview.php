<?php

namespace App\Console\Commands\Org;

use App\Jobs\Org\OrgReviewJob;
use App\Models\Company;
use App\Support\OrgReviewLock;
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

        $dispatched = 0;
        foreach ($companies as $companyId) {
            if (! OrgReviewLock::acquire($companyId)) {
                continue;
            }
            OrgReviewJob::dispatch($companyId)->onQueue('org');
            $dispatched++;
        }

        $this->info('Org reviews dispatched: '.$dispatched.' / '.$companies->count());

        return self::SUCCESS;
    }
}
