<?php

namespace App\Jobs\Org;

use App\Models\Company;
use App\Services\Org\Billing\AutonomousBudgetService;
use App\Services\Org\Billing\CreditMeterService;
use App\Services\Org\Billing\InsufficientCreditsException;
use App\Services\Org\OrgReviewService;
use App\Support\BillableUnit;
use App\Support\LlmContext;
use App\Support\ModelLevel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Периодично ревю на Управителя (§7.1) — `org` queue. Best-effort org_planning билинг.
 */
class OrgReviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public int $companyId) {}

    public function handle(OrgReviewService $review, CreditMeterService $meter, AutonomousBudgetService $budget): void
    {
        $company = Company::find($this->companyId);
        if (! $company || ! $company->active_org_version_id) {
            OrgReviewLock::release($this->companyId);

            return;
        }

        // Дневен автономен таван: ревюто е автономно → спира при достигнат лимит.
        if (! $budget->allows($company, 'org_review')) {
            Log::info('[OrgReview] пропуснато (дневен автономен лимит), company '.$company->id);
            OrgReviewLock::release($this->companyId);

            return;
        }

        $reservation = null;
        try {
            $reservation = $meter->reserve(
                $company->id, 'org_planning', $company,
                BillableUnit::estimateFor('org_planning', ModelLevel::fromRequest(config('organization.manager.level'))),
                'autonomous',
            );
        } catch (InsufficientCreditsException) {
            Log::info('[OrgReview] best-effort (no credits) company '.$company->id);
        }

        if ($reservation) {
            LlmContext::set([
                'purpose' => 'org_review',
                'company_id' => $company->id,
                'context_type' => 'org_planning',
                'subject_type' => $company->getMorphClass(),
                'subject_id' => $company->id,
                'reservation_id' => $reservation->id,
            ]);
        }

        try {
            $review->review($company);
        } catch (\Throwable $e) {
            Log::error('[OrgReview] failed: '.$e->getMessage());
        } finally {
            OrgReviewLock::release($this->companyId);
            if ($reservation) {
                LlmContext::clear();
                $meter->settle($reservation, $meter->actualFor($reservation));
            }
        }
    }
}
