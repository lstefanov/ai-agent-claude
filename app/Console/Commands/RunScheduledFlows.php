<?php

namespace App\Console\Commands;

use App\Jobs\ExecuteFlowJob;
use App\Models\Flow;
use Illuminate\Console\Command;

class RunScheduledFlows extends Command
{
    protected $signature   = 'flows:run-scheduled';
    protected $description = 'Dispatch execution for all flows whose cron schedule matches now';

    public function handle(): int
    {
        $flows = Flow::where('status', 'active')
            ->whereNotNull('schedule_cron')
            ->get();

        $dispatched = 0;

        foreach ($flows as $flow) {
            if ($this->cronMatches($flow->schedule_cron)) {
                ExecuteFlowJob::dispatch($flow, 'scheduler');
                $dispatched++;
                $this->line("Dispatched: {$flow->name}");
            }
        }

        $this->info("Scheduled flows dispatched: {$dispatched}");
        return Command::SUCCESS;
    }

    private function cronMatches(string $cron): bool
    {
        // Use Laravel's CronExpression via the scheduler
        $expression = new \Cron\CronExpression($cron);
        return $expression->isDue(new \DateTime('now'));
    }
}
