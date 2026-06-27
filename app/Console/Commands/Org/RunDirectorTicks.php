<?php

namespace App\Console\Commands\Org;

use App\Jobs\Org\DirectorTickJob;
use App\Jobs\Org\ScheduledTaskJob;
use App\Models\AssistantTask;
use App\Models\Director;
use Cron\CronExpression;
use Illuminate\Console\Command;

/**
 * Планировчик на org работата (§4.2) — образец RunScheduledFlows. Без флаг: диспечира
 * due scheduled задачите (cron). С --ticks: диспечира директорски ревюта (по-рядко).
 */
class RunDirectorTicks extends Command
{
    protected $signature = 'org:director-ticks {--ticks : Dispatch director review ticks instead of scheduled tasks}';

    protected $description = 'Dispatch scheduled org tasks (by cron) and/or director review ticks';

    public function handle(): int
    {
        return $this->option('ticks') ? $this->dispatchDirectorTicks() : $this->dispatchScheduledTasks();
    }

    /** Scheduled задачи, чийто cron е due сега → ScheduledTaskJob (`org` queue). */
    private function dispatchScheduledTasks(): int
    {
        // Само одобрени (ready) scheduled задачи се пускат по cron. Предложените чакат
        // човешко одобрение преди да станат runnable.
        $tasks = AssistantTask::where('trigger', 'scheduled')
            ->whereNotNull('schedule')
            ->where('status', 'ready')
            ->get();

        $n = 0;
        foreach ($tasks as $task) {
            if ($this->cronDue((string) $task->schedule)) {
                ScheduledTaskJob::dispatch($task->id)->onQueue('org');
                $n++;
            }
        }
        $this->info("Scheduled org tasks dispatched: {$n}");

        return self::SUCCESS;
    }

    /** Активните директори → DirectorTickJob (`org` queue). */
    private function dispatchDirectorTicks(): int
    {
        $directors = Director::where('status', 'active')
            ->whereHas('orgVersion.company', fn ($q) => $q->whereColumn('companies.active_org_version_id', 'directors.org_version_id'))
            ->get();

        foreach ($directors as $director) {
            DirectorTickJob::dispatch($director->id, 'scheduled')->onQueue('org');
        }
        $this->info('Director ticks dispatched: '.$directors->count());

        return self::SUCCESS;
    }

    /** Cron израз due ли е сега (като RunScheduledFlows). */
    private function cronDue(string $cron): bool
    {
        if ($cron === '') {
            return false;
        }
        try {
            return (new CronExpression($cron))->isDue(new \DateTime('now'));
        } catch (\Throwable) {
            return false;
        }
    }
}
