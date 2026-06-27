<?php

namespace App\Console\Commands;

use App\Models\FlowRun;
use App\Services\GraphFlowExecutor;
use Illuminate\Console\Command;
use Throwable;

class ExecuteFlowCommand extends Command
{
    protected $signature = 'flows:execute {flowRunId}';

    protected $description = 'Execute a flow run via the graph DAG executor';

    public function handle(GraphFlowExecutor $executor): int
    {
        $flowRunId = $this->argument('flowRunId');

        $flowRun = FlowRun::with('flow')->find($flowRunId);

        if (! $flowRun) {
            $this->error("FlowRun #{$flowRunId} not found.");

            return 1;
        }

        try {
            $executor->run($flowRun->flow, $flowRun->triggered_by, $flowRun);
        } catch (Throwable $e) {
            $flowRun->update([
                'status' => 'failed',
                'completed_at' => now(),
            ]);
            $this->error($e->getMessage());

            return 1;
        }

        return 0;
    }
}
