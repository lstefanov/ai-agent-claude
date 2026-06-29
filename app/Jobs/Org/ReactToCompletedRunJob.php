<?php

namespace App\Jobs\Org;

use App\Models\Assistant;
use App\Models\AssistantTask;
use App\Models\FlowRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Event-driven follow-up (§живост): завършена org задача → директорът на отдела обмисля
 * следваща стъпка (DirectorTickJob с trigger='event'). Под СЪЩИЯ cooldown + дневен таван като
 * cron-тика → не спами (макс. едно предложение на отдел за propose_cooldown_hours). Така
 * веригите стават реална екипна работа (research → съдържание → outreach), не изолирани задачи.
 */
class ReactToCompletedRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public int $flowRunId) {}

    public function handle(): void
    {
        $run = FlowRun::with('flow.company')->find($this->flowRunId);
        $taskId = $run?->context['assistant_task_id'] ?? null;
        $company = $run?->flow?->company;
        if (! $taskId || ! $company || ! ($version = $company->activeOrgVersion)) {
            return;
        }

        $member = AssistantTask::with('orgMember')->find($taskId)?->orgMember;
        if (! $member) {
            return;
        }

        $director = Assistant::where('org_version_id', $version->id)
            ->where('org_member_id', $member->id)->first()?->director;
        if ($director && $director->status === 'active') {
            DirectorTickJob::dispatch($director->id, 'event')->onQueue('org');
        }
    }
}
