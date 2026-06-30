<?php

namespace App\Jobs\Org;

use App\Models\Company;
use App\Models\Director;
use App\Services\Org\Billing\AutonomousBudgetService;
use App\Services\Org\Billing\CreditMeterService;
use App\Services\Org\Billing\InsufficientCreditsException;
use App\Services\Org\DirectorAgentService;
use App\Support\BillableUnit;
use App\Support\LlmContext;
use App\Support\ModelLevel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Директорски tick (§4.2) — `org` queue (НЕ flows). Билнат: обвива DirectorAgentService::tick
 * в `director_tick` резервация (преди това небилнат — §Codex). Автономният tick (scheduled)
 * спазва дневния автономен таван и брои origin=autonomous; ръчният (manual, от картата) — не.
 */
class DirectorTickJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public int $directorId, public string $trigger = 'scheduled') {}

    public function handle(DirectorAgentService $directors, CreditMeterService $meter, AutonomousBudgetService $budget): void
    {
        $director = Director::with('orgVersion.company', 'orgMember')->find($this->directorId);
        $company = $director?->orgVersion?->company;
        if (! $director || ! $company) {
            return;
        }

        $autonomous = $this->trigger !== 'manual';
        $origin = $autonomous ? 'autonomous' : 'manual';
        $ignition = $this->trigger === 'ignition';

        // Автономният tick: cooldown pre-check + дневен таван — за да не резервира/мисли всеки
        // час напразно (мисленето е веднъж на propose_cooldown_hours). Ръчният (човек) и
        // ignition (веднага след одобрение на екип) минават cooldown-а.
        if ($autonomous && ! $ignition) {
            $cooldownH = (int) config('organization.autonomous.director.propose_cooldown_hours', 24);
            if ($director->last_proposed_at && $director->last_proposed_at->gt(now()->subHours($cooldownH))) {
                return;
            }
        }
        if ($autonomous) {
            if (! $budget->allows($company, $ignition ? 'ignition' : 'director_tick')) {
                return;
            }
        }

        try {
            $reservation = $meter->reserve(
                $company->id, 'director_tick', $director->orgMember,
                BillableUnit::estimateFor('director_tick', ModelLevel::fromRequest(config('organization.manager.level'))),
                $origin,
            );
        } catch (InsufficientCreditsException) {
            Log::info('[DirectorTick] пропуснат (недостатъчно кредити), директор '.$director->id);

            return;
        }

        LlmContext::set([
            'purpose' => 'director_tick',
            'company_id' => $company->id,
            'context_type' => 'director_tick',
            'subject_type' => $director->orgMember?->getMorphClass(),
            'subject_id' => $director->orgMember?->id,
            'reservation_id' => $reservation->id,
        ]);

        try {
            $directors->tick($director, $this->trigger);
        } catch (\Throwable $e) {
            Log::error('[DirectorTick] failed: '.$e->getMessage());
            $this->recordFailureEvent($company, $director, $e);
        } finally {
            LlmContext::clear();
            $meter->settle($reservation, $meter->actualFor($reservation));
        }
    }

    /**
     * Job-level провал (вкл. DI/resolution грешки ПРЕДИ handle() — напр. липсващ import на
     * service) — НЕ зависи от счупения service: зарежда директора по id и записва видимо
     * събитие. Така мълчаливите фонови провали стигат до хрониката, не само до failed_jobs.
     */
    public function failed(?\Throwable $e): void
    {
        $director = Director::with('orgVersion.company', 'orgMember')->find($this->directorId);
        $company = $director?->orgVersion?->company;
        if ($director && $company) {
            $this->recordFailureEvent($company, $director, $e);
        }
    }

    /** Видимо (деduplicate-нато) събитие за провален директорски анализ — без спам по cron. */
    private function recordFailureEvent(Company $company, Director $director, ?\Throwable $e): void
    {
        // Ръчният tick не пише в хрониката тих провал — UI-ят го показва директно.
        if ($this->trigger === 'manual') {
            return;
        }

        $reason = $e ? Str::limit($e->getMessage(), 140) : 'неизвестна грешка';
        $summary = 'Директорският анализ се провали — опитай пак. ('.$reason.')';

        $last = $company->orgEvents()->where('type', 'review')
            ->where('org_member_id', $director->orgMember?->id)
            ->latest('id')->value('summary');
        if (trim((string) $last) === trim($summary)) {
            return; // dedupe — не спами хрониката всеки час
        }

        $company->orgEvents()->create([
            'type' => 'review',
            'org_version_id' => $director->org_version_id,
            'org_member_id' => $director->orgMember?->id,
            'summary' => $summary,
            'actor' => 'manager',
        ]);
    }
}
