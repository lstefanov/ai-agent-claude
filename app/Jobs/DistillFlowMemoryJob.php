<?php

namespace App\Jobs;

use App\Models\FlowRun;
use App\Services\FlowMemoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Post-run memory distillation. Runs on the DEFAULT queue (composer dev has a
 * dedicated worker for it) so it never competes with node jobs for the
 * `flows` workers. Best-effort — a distillation failure never matters.
 */
class DistillFlowMemoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(public int $flowRunId) {}

    public function handle(FlowMemoryService $memory): void
    {
        $flowRun = FlowRun::find($this->flowRunId);

        if (! $flowRun) {
            return;
        }

        try {
            $memory->recordRun($flowRun);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
