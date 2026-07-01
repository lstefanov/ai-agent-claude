<?php

namespace App\Jobs\Org;

use App\Models\Company;
use App\Services\Org\Billing\BillableOperationService;
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
 * Ревю екранът: AI-генерира ЕДИН нов асистент или цял отдел на `org` queue. LLM call-ът е
 * твърде бавен за синхронна уеб заявка (уеб PHP умира на max_execution_time=30s), затова
 * минава през Horizon worker-а. Token-poll: кешира резултата под `org_add_{token}`;
 * фронтендът поллва и го слива в дизайна (`this.design`).
 */
class GenerateOrgAdditionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    /** @param  array<string,mixed>  $payload */
    public function __construct(
        public int $companyId,
        public string $kind,   // assistant | department
        public array $payload,
        public string $token,
    ) {}

    public function handle(OrgPlannerService $planner, BillableOperationService $billable): void
    {
        $key = "org_add_{$this->token}";

        $company = Company::find($this->companyId);
        if (! $company) {
            Cache::put($key, ['status' => 'failed', 'error' => 'Фирмата не е намерена.', 'updated_at' => now()->timestamp], now()->addMinutes(15));

            return;
        }

        try {
            // Ръчно разширяване на екипа е best-effort (origin=manual); LLM call-ът върви
            // през centralния wrapper за пълна company/context атрибуция и settle.
            if ($this->kind === 'department') {
                $department = $billable->run(
                    $this->companyId,
                    'org_planning',
                    $company,
                    fn () => $planner->designSingleDepartment(
                        $company,
                        null,
                        (array) ($this->payload['existing_domains'] ?? []),
                        (array) ($this->payload['custom'] ?? []),
                    ),
                    opKey: $this->job?->uuid(),
                    level: ModelLevel::fromRequest((string) config('organization.manager.level', 'ultra')),
                    origin: 'manual',
                );
                Cache::put($key, ['status' => 'completed', 'department' => $department, 'updated_at' => now()->timestamp], now()->addMinutes(20));
            } else {
                $assistant = $billable->run(
                    $this->companyId,
                    'org_planning',
                    $company,
                    fn () => $planner->designSingleAssistant(
                        $company,
                        (array) ($this->payload['department'] ?? []),
                        (array) ($this->payload['existing'] ?? []),
                    ),
                    opKey: $this->job?->uuid(),
                    level: ModelLevel::fromRequest((string) config('organization.manager.level', 'ultra')),
                    origin: 'manual',
                );
                Cache::put($key, ['status' => 'completed', 'assistant' => $assistant, 'updated_at' => now()->timestamp], now()->addMinutes(20));
            }
        } catch (\Throwable $e) {
            Log::error('[OrgAddition] '.$this->kind.' failed: '.$e->getMessage());
            Cache::put($key, ['status' => 'failed', 'error' => 'Генерирането се провали. Опитай пак.', 'updated_at' => now()->timestamp], now()->addMinutes(15));
        }
    }
}
