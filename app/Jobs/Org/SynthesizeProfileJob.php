<?php

namespace App\Jobs\Org;

use App\Models\BusinessProfile;
use App\Services\Org\BusinessProfilerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Задължителният синтез след интервюто (§3-part understanding) — `org` queue. Token-poll:
 * извежда проблеми/нужди/възможности и ги кешира под `org_synthesis_{token}` за анализ екрана.
 * Огледало на ProposeOrganizationJob.
 */
class SynthesizeProfileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $profileId, public string $token) {}

    public function handle(BusinessProfilerService $profiler): void
    {
        $key = "org_synthesis_{$this->token}";
        $profile = BusinessProfile::find($this->profileId);
        if (! $profile) {
            Cache::put($key, ['status' => 'failed', 'error' => 'Профилът не е намерен.', 'updated_at' => now()->timestamp], now()->addMinutes(15));

            return;
        }

        try {
            $onStage = fn (string $s) => Cache::put($key, ['status' => 'pending', 'stage' => $s, 'updated_at' => now()->timestamp], now()->addMinutes(15));

            $profiler->synthesizeFeedback($profile, $onStage);
            $profile->refresh();

            // synthesizeFeedback гълта LLM грешките тихо (маркерът остава null) → третирай го като провал.
            if (! $profile->synthesis_completed_at) {
                Cache::put($key, ['status' => 'failed', 'error' => 'Анализът се провали. Опитай пак.', 'updated_at' => now()->timestamp], now()->addMinutes(15));

                return;
            }

            Cache::put($key, [
                'status' => 'completed',
                'problems' => (array) $profile->problems,
                'needs' => (array) $profile->needs,
                'opportunities' => (array) $profile->opportunities,
                'updated_at' => now()->timestamp,
            ], now()->addMinutes(15));
        } catch (\Throwable $e) {
            Log::error('[OrgSynthesis] failed: '.$e->getMessage());
            Cache::put($key, ['status' => 'failed', 'error' => 'Анализът се провали. Опитай пак.', 'updated_at' => now()->timestamp], now()->addMinutes(15));
        }
    }
}
