<?php

namespace App\Jobs;

use App\Models\Flow;
use App\Services\GraphFlowExecutor;
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

    public function handle(GraphFlowExecutor $executor): void
    {
        $executor->run($this->flow, $this->triggeredBy);
    }
}
