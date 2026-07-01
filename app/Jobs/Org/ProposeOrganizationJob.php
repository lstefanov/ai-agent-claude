<?php

namespace App\Jobs\Org;

use App\Models\Company;
use App\Services\Org\Billing\BillableOperationService;
use App\Services\Org\BusinessProfilerService;
use App\Services\Org\OrgPlannerService;
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

    public function handle(OrgPlannerService $planner, BillableOperationService $billable, BusinessProfilerService $profiler): void
    {
        $key = "org_design_{$this->token}";
        $company = Company::find($this->companyId);
        if (! $company) {
            Cache::put($key, ['status' => 'failed', 'error' => 'Фирмата не е намерена.', 'updated_at' => now()->timestamp], now()->addMinutes(15));

            return;
        }

        // opKey: uuid на job-а (стабилен при retry); резервен — token уникален за дизайн сесия.
        // origin не е подаден → 'manual' (default) → soft gate → best-effort при липса на кредити.
        $opKey = $this->job?->uuid() ?? "org_design:{$this->token}";

        try {
            $billable->run(
                $company->id,
                'org_planning',
                $company,
                function () use ($planner, $profiler, $company, $key) {
                    $onStage = fn (string $s) => Cache::put($key, ['status' => 'pending', 'stage' => $s, 'updated_at' => now()->timestamp], now()->addMinutes(20));

                    // Застраховка: ако анализ екранът е прескочен, синтезът се прави тук (идемпотентно),
                    // за да има проблеми/нужди за композицията и възможности за приоритетите.
                    if ($profile = $company->businessProfile) {
                        $profiler->synthesizeFeedback($profile, $onStage);
                    }

                    $proposed = $planner->proposeOrganization($company, $onStage);
                    $finalized = $planner->finalizeOrganization($proposed, $company->activeOrgVersion);

                    Cache::put($key, ['status' => 'completed', 'design' => $finalized, 'updated_at' => now()->timestamp], now()->addMinutes(30));
                },
                opKey: $opKey,
                level: ModelLevel::fromRequest(config('organization.manager.level')),
                origin: 'manual',
            );
        } catch (\Throwable $e) {
            Log::error('[OrgDesign] failed: '.$e->getMessage());
            Cache::put($key, ['status' => 'failed', 'error' => 'Дизайнът се провали. Опитай пак.', 'updated_at' => now()->timestamp], now()->addMinutes(20));
        }
    }
}
