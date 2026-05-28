<?php

namespace App\Jobs;

use App\Models\Flow;
use App\Services\FlowExecutorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteFlowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public Flow $flow,
        public string $triggeredBy = 'manual'
    ) {}

    public function handle(FlowExecutorService $executor): void
    {
        $executor->run($this->flow, $this->triggeredBy);
    }
}
