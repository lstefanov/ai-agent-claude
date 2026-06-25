<?php

namespace App\Jobs\Org;

use App\Models\AssistantTask;
use App\Services\Org\Billing\InsufficientCreditsException;
use App\Services\Org\TaskRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Изпълнение на scheduled задача по cron (§4.2) — `org` queue. Wallet гейтът е в
 * TaskRunService (недостиг → пропуска + известие, не фейлва тика). `act` задачите са под
 * act hard gate (§B2): в preview само „чернова на действието" (реалният act е Фаза 5).
 */
class ScheduledTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public int $taskId) {}

    public function handle(TaskRunService $runner): void
    {
        $task = AssistantTask::find($this->taskId);
        if (! $task || $task->status === 'disabled') {
            return;
        }

        // act hard gate (§B2): без реален auth, write действията не се изпълняват реално.
        if ($task->isWriteAct() && ! config('organization.act.enabled')) {
            $task->orgMember?->company?->orgEvents()->create([
                'type' => 'review',
                'org_member_id' => $task->org_member_id,
                'summary' => "act задача под гейт (preview, само чернова): {$task->title}",
                'actor' => 'director',
            ]);

            return;
        }

        try {
            $runner->requestRun($task, runAfterGenerate: true);
        } catch (InsufficientCreditsException) {
            $task->orgMember?->company?->orgEvents()->create([
                'type' => 'review',
                'org_member_id' => $task->org_member_id,
                'summary' => "Пропуснато (недостатъчно кредити): {$task->title}",
                'actor' => 'director',
            ]);
        } catch (\Throwable $e) {
            Log::warning('[ScheduledTask] '.$this->taskId.' failed: '.$e->getMessage());
        }
    }
}
