<?php

namespace App\Jobs\Org;

use App\Models\Company;
use App\Services\Org\Billing\AutonomousBudgetService;
use App\Services\Org\Billing\BillableOperationService;
use App\Services\Org\Billing\InsufficientCreditsException;
use App\Services\Org\OrgDigestService;
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

    public function handle(OrgDigestService $digest, BillableOperationService $billable, AutonomousBudgetService $budget): void
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

        // opKey: uuid на job-а (стабилен при retry); резервен — детерминистичен от company id + дата.
        // Датата влиза за да не reuse-ва вчерашния settled opKey (нов ден = нова операция).
        $opKey = $this->job?->uuid() ?? 'org_digest:'.$this->companyId.':'.now()->toDateString();

        try {
            $billable->run(
                $company->id,
                'org_digest',
                $company,
                function () use ($digest, $company) {
                    $digest->generate($company);
                },
                opKey: $opKey,
                level: ModelLevel::Medium,
                origin: 'autonomous',
            );
        } catch (InsufficientCreditsException) {
            // Автономен контекст е hard-gated: log и тих skip при недостатъчно кредити.
            Log::info('[OrgDigest] пропуснато (hard-gate, недостатъчно кредити), company '.$company->id);
        } catch (\Throwable $e) {
            Log::error('[OrgDigest] failed: '.$e->getMessage());
        }
    }
}
