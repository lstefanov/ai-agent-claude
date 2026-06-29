<?php

namespace App\Jobs\Org;

use App\Models\Company;
use App\Services\Org\Billing\AutonomousBudgetService;
use App\Services\Org\Billing\CreditMeterService;
use App\Services\Org\Billing\InsufficientCreditsException;
use App\Services\Org\OrgDigestService;
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
 * Дневен „standup" дайджест (слой живост §3) — `org` queue. Един евтин метриран LLM разказ
 * на ден за фирма с активна организация: какво се случи, какво чака, кой се отличи.
 * Моделиран по OrgReviewJob (reserve/LlmContext/settle), но по-лек: org_digest контекст,
 * Medium ниво. Идемпотентен (един дайджест на ден) → повторен dispatch не харчи втори път.
 */
class OrgDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $companyId) {}

    public function handle(OrgDigestService $digest, CreditMeterService $meter, AutonomousBudgetService $budget): void
    {
        $company = Company::find($this->companyId);
        if (! $company || ! $company->active_org_version_id) {
            return;
        }

        // Идемпотентност: един дайджест на ден — повторен dispatch е тих no-op.
        if ($digest->hasDigestToday($company)) {
            return;
        }

        // Дневен автономен таван: дайджестът е автономен → спира при достигнат лимит.
        if (! $budget->allows($company, 'org_review')) {
            Log::info('[OrgDigest] пропуснато (дневен автономен лимит), company '.$company->id);

            return;
        }

        $reservation = null;
        try {
            $reservation = $meter->reserve(
                $company->id, 'org_digest', $company,
                BillableUnit::estimateFor('org_digest', ModelLevel::Medium),
                'autonomous',
            );
        } catch (InsufficientCreditsException) {
            Log::info('[OrgDigest] best-effort (no credits) company '.$company->id);
        }

        if ($reservation) {
            LlmContext::set([
                'purpose' => 'org_digest',
                'company_id' => $company->id,
                'context_type' => 'org_digest',
                'subject_type' => $company->getMorphClass(),
                'subject_id' => $company->id,
                'reservation_id' => $reservation->id,
            ]);
        }

        try {
            $digest->generate($company);
        } catch (\Throwable $e) {
            Log::error('[OrgDigest] failed: '.$e->getMessage());
        } finally {
            if ($reservation) {
                LlmContext::clear();
                $meter->settle($reservation, $meter->actualFor($reservation));
            }
        }
    }
}
