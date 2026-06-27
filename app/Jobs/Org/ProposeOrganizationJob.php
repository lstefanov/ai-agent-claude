<?php

namespace App\Jobs\Org;

use App\Models\Company;
use App\Services\Org\Billing\CreditMeterService;
use App\Services\Org\Billing\InsufficientCreditsException;
use App\Services\Org\BusinessProfilerService;
use App\Services\Org\OrgPlannerService;
use App\Support\BillableUnit;
use App\Support\LlmContext;
use App\Support\ModelLevel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Дизайн на екипа (§2.5) — `org` queue (дълъг три-фазен дизайн). Token-poll:
 * кешира финализираното предложение под `org_design_{token}` за ревю преди одобрение.
 * Best-effort билинг (org_planning) — онбордингът е безплатен при липса на кредити.
 */
class ProposeOrganizationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public int $companyId, public string $token) {}

    public function handle(OrgPlannerService $planner, CreditMeterService $meter, BusinessProfilerService $profiler): void
    {
        $key = "org_design_{$this->token}";
        $company = Company::find($this->companyId);
        if (! $company) {
            Cache::put($key, ['status' => 'failed', 'error' => 'Фирмата не е намерена.', 'updated_at' => now()->timestamp], now()->addMinutes(15));

            return;
        }

        $reservation = null;
        try {
            $reservation = $meter->reserve(
                $company->id, 'org_planning', $company,
                BillableUnit::estimateFor('org_planning', ModelLevel::fromRequest(config('organization.manager.level'))),
            );
        } catch (InsufficientCreditsException) {
            Log::info('[OrgDesign] best-effort (no credits) company '.$company->id);
        }

        if ($reservation) {
            LlmContext::set([
                'purpose' => 'org_planning',
                'company_id' => $company->id,
                'context_type' => 'org_planning',
                'subject_type' => $company->getMorphClass(),
                'subject_id' => $company->id,
                'reservation_id' => $reservation->id,
            ]);
        }

        try {
            $onStage = fn (string $s) => Cache::put($key, ['status' => 'pending', 'stage' => $s, 'updated_at' => now()->timestamp], now()->addMinutes(20));

            // Застраховка: ако анализ екранът е прескочен, синтезът се прави тук (идемпотентно),
            // за да има проблеми/нужди за композицията и възможности за приоритетите.
            if ($profile = $company->businessProfile) {
                $profiler->synthesizeFeedback($profile, $onStage);
            }

            $proposed = $planner->proposeOrganization($company, $onStage);
            $finalized = $planner->finalizeOrganization($proposed, $company->activeOrgVersion);

            Cache::put($key, ['status' => 'completed', 'design' => $finalized, 'updated_at' => now()->timestamp], now()->addMinutes(30));
        } catch (\Throwable $e) {
            Log::error('[OrgDesign] failed: '.$e->getMessage());
            Cache::put($key, ['status' => 'failed', 'error' => 'Дизайнът се провали. Опитай пак.', 'updated_at' => now()->timestamp], now()->addMinutes(20));
        } finally {
            if ($reservation) {
                LlmContext::clear();
                $meter->settle($reservation, $meter->actualFor($reservation));
            }
        }
    }
}
