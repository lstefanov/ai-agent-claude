<?php

namespace App\Jobs\Org;

use App\Models\Company;
use App\Services\Org\Billing\CreditMeterService;
use App\Services\Org\Billing\InsufficientCreditsException;
use App\Services\Org\BusinessProfilerService;
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
 * Проучване на бизнеса (§1.3) — `org` queue (дълготрайно). Огледало на token-poll
 * патърна: ъпдейтва `org_research_{token}` кеша. Билингът е best-effort за онбординга
 * (§15 решение: проучването е безплатно при липса на кредити, само run-овете са hard-gated).
 */
class ResearchBusinessJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public int $companyId, public string $token) {}

    public function handle(BusinessProfilerService $profiler, CreditMeterService $meter): void
    {
        $key = "org_research_{$this->token}";
        $company = Company::find($this->companyId);
        if (! $company) {
            Cache::put($key, ['status' => 'failed', 'error' => 'Фирмата не е намерена.', 'updated_at' => now()->timestamp], now()->addMinutes(15));

            return;
        }

        // Best-effort резервация на 'research' контекста (онбординг → безплатно при недостиг).
        $reservation = null;
        try {
            $reservation = $meter->reserve(
                $company->id, 'research', $company,
                BillableUnit::estimateFor('research', ModelLevel::fromRequest(config('organization.manager.level'))),
            );
        } catch (InsufficientCreditsException) {
            Log::info('[Research] best-effort (no credits) company '.$company->id);
        }

        if ($reservation) {
            LlmContext::set([
                'purpose' => 'org_research',
                'company_id' => $company->id,
                'context_type' => 'research',
                'subject_type' => $company->getMorphClass(),
                'subject_id' => $company->id,
                'reservation_id' => $reservation->id,
            ]);
        }

        try {
            Cache::put($key, ['status' => 'pending', 'stage' => 'Проучвам сайта и източниците…', 'updated_at' => now()->timestamp], now()->addMinutes(20));
            $profiler->research($company);

            Cache::put($key, ['status' => 'pending', 'stage' => 'Правя ситуационен анализ…', 'updated_at' => now()->timestamp], now()->addMinutes(20));
            $analysis = $profiler->analyze($company);

            Cache::put($key, ['status' => 'completed', 'stage' => 'Готово', 'analysis' => $analysis, 'updated_at' => now()->timestamp], now()->addMinutes(20));
        } catch (\Throwable $e) {
            Log::error('[Research] failed: '.$e->getMessage());
            Cache::put($key, ['status' => 'failed', 'error' => 'Проучването се провали. Опитай пак.', 'updated_at' => now()->timestamp], now()->addMinutes(20));
        } finally {
            if ($reservation) {
                LlmContext::clear();
                $meter->settle($reservation, $meter->actualFor($reservation));
            }
        }
    }
}
