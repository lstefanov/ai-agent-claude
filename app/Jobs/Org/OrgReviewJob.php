<?php

namespace App\Jobs\Org;

use App\Models\Company;
use App\Services\Org\Billing\AutonomousBudgetService;
use App\Services\Org\Billing\BillableOperationService;
use App\Services\Org\Billing\InsufficientCreditsException;
use App\Services\Org\OrgReviewService;
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

    public function handle(OrgReviewService $review, BillableOperationService $billable, AutonomousBudgetService $budget): void
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

        // opKey: uuid на job-а (стабилен при retry); резервен — детерминистичен от company id.
        $opKey = $this->job?->uuid() ?? "org_review:{$this->companyId}";

        try {
            $billable->run(
                $company->id,
                'org_planning',
                $company,
                function () use ($review, $company) {
                    $review->review($company);
                },
                opKey: $opKey,
                level: ModelLevel::fromRequest(config('organization.manager.level')),
                origin: 'autonomous',
            );
        } catch (InsufficientCreditsException) {
            // Автономен контекст е hard-gated: log и тих skip при недостатъчно кредити.
            Log::info('[OrgReview] пропуснато (hard-gate, недостатъчно кредити), company '.$company->id);
        } catch (\Throwable $e) {
            Log::error('[OrgReview] failed: '.$e->getMessage());
        } finally {
            OrgReviewLock::release($this->companyId);
        }
    }
}
