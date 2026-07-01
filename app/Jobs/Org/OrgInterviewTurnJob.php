<?php

namespace App\Jobs\Org;

use App\Models\BusinessProfile;
use App\Services\Org\Billing\BillableOperationService;
use App\Services\Org\OrgInterviewService;
use App\Support\ModelLevel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Интервю ход (§1.3) — `org` queue (LLM работа). Огледало на AssistantTurnJob: чете
 * профил+вход, вика OrgInterviewService::turn (best-effort billable контекст `interview`),
 * ъпдейтва кеша. Натрупването на отговорите става в контролера (като wizard-а).
 */
class OrgInterviewTurnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public string $token, public int $profileId, public string $userInput) {}

    public function handle(OrgInterviewService $interview, BillableOperationService $billable): void
    {
        $key = "org_interview_{$this->token}";
        $profile = BusinessProfile::find($this->profileId);
        if (! $profile) {
            Cache::put($key, ['status' => 'failed', 'error' => 'Профилът не е намерен.', 'updated_at' => now()->timestamp], now()->addMinutes(15));

            return;
        }

        // opKey: uuid на job-а (стабилен при retry); резервен — token + profile уникален ход.
        $opKey = $this->job?->uuid() ?? "interview:{$this->token}:{$this->profileId}";

        try {
            // Best-effort 'interview' резервация (онбординг — best-effort при недостиг).
            // origin не е подаден → 'manual' (default) → soft gate → никога не хвърля InsufficientCreditsException.
            $billable->run(
                $profile->company_id,
                'interview',
                $profile,
                function () use ($interview, $profile, $key) {
                    $onStage = fn (string $s) => Cache::put($key, ['status' => 'pending', 'stage' => $s, 'updated_at' => now()->timestamp], now()->addMinutes(15));

                    $result = $interview->turn($profile, $this->userInput, $onStage);

                    // Управителят не успя да формулира въпрос, а още е рано за „ready" → мек, повторим error.
                    // Не записваме нищо в транскрипта и не вдигаме status — потребителят просто натиска „Изпрати" пак.
                    if ($result['soft_error'] ?? false) {
                        Cache::put($key, ['status' => 'failed', 'error' => 'Управителят се умисли. Натисни „Изпрати" пак.', 'updated_at' => now()->timestamp], now()->addMinutes(15));

                        return;
                    }

                    // Репликата на Управителя (+ въпроса) в транскрипта — за да оцелее при refresh.
                    $profile->appendTranscript(['role' => 'assistant', 'content' => $result['reply'], 'question' => $result['question']]);

                    if ($result['phase'] === 'ready') {
                        BusinessProfile::whereKey($profile->id)->update(['status' => 'ready']);
                    } elseif ($result['phase'] === 'interview') {
                        BusinessProfile::whereKey($profile->id)->update(['status' => 'interviewing']);
                    }

                    Cache::put($key, ['status' => 'completed'] + $result + ['updated_at' => now()->timestamp], now()->addMinutes(15));
                },
                opKey: $opKey,
                level: ModelLevel::fromRequest(config('organization.manager.level')),
                origin: 'manual',
            );
        } catch (\Throwable $e) {
            Log::error('[Interview] failed: '.$e->getMessage());
            Cache::put($key, ['status' => 'failed', 'error' => 'Грешка при интервюто. Опитай пак.', 'updated_at' => now()->timestamp], now()->addMinutes(15));
        }
    }
}
