<?php

namespace App\Jobs\Org;

use App\Models\Company;
use App\Services\Org\Billing\BillableOperationService;
use App\Services\Org\BusinessProfilerService;
use App\Support\LlmUsage;
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

    public function handle(BusinessProfilerService $profiler, BillableOperationService $billable): void
    {
        $key = "org_research_{$this->token}";
        $company = Company::find($this->companyId);
        if (! $company) {
            Cache::put($key, ['status' => 'failed', 'error' => 'Фирмата не е намерена.', 'updated_at' => now()->timestamp], now()->addMinutes(15));

            return;
        }

        // opKey: uuid на job-а (стабилен при retry); резервен — token уникален за онбординг сесия.
        // origin не е подаден → 'manual' (default) → soft gate → best-effort при липса на кредити.
        $opKey = $this->job?->uuid() ?? "research:{$this->token}";

        try {
            $billable->run(
                $company->id,
                'research',
                $company,
                function () use ($profiler, $company, $key) {
                    $onStage = fn (string $s) => Cache::put($key, ['status' => 'pending', 'stage' => $s, 'updated_at' => now()->timestamp], now()->addMinutes(20));

                    $onStage('Проучвам сайта и източниците…');
                    $profiler->research($company, $onStage);
                    $profiler->queueKnowledgeIngest($company, $onStage);

                    $onStage('Правя ситуационен анализ…');
                    $analysis = $profiler->analyze($company);
                    $research = (array) $company->businessProfile()->first()?->research;

                    Cache::put($key, ['status' => 'completed', 'stage' => 'Готово', 'analysis' => $analysis, 'research' => $research, 'updated_at' => now()->timestamp], now()->addMinutes(20));
                },
                opKey: $opKey,
                level: ModelLevel::fromRequest(config('organization.manager.level')),
                origin: 'manual',
                hardGate: false,
            );
        } catch (\Throwable $e) {
            Log::error('[Research] failed: '.$e->getMessage());
            Cache::put($key, ['status' => 'failed', 'error' => 'Проучването се провали. Опитай пак.', 'updated_at' => now()->timestamp], now()->addMinutes(20));
        } finally {
            LlmUsage::take();
        }
    }
}
