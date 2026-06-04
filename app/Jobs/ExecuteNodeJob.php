<?php

namespace App\Jobs;

use App\Services\NodeExecutorService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Unit of work: executes one graph node.
 *
 * Nodes within a topological wave are dispatched together in a Bus::batch, but a
 * per-flow Cache lock serializes them at runtime so AT MOST ONE agent is in
 * flight for a given FlowRun at a time. Different FlowRuns may run in parallel.
 *
 * Fan-in correctness is unaffected: the wave chain (->then()) still guarantees
 * a node never starts before all its predecessors finished.
 */
class ExecuteNodeJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(
        public int $flowRunId,
        public int $flowNodeId,
    ) {}

    public function handle(NodeExecutorService $exec): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        // Per-flow serial gate — wait up to 20 min for a sibling node in the
        // same wave to finish before running this one. The 30-min TTL is the
        // ceiling for a single node (matches the timeout above with headroom).
        Cache::lock("flow-run:{$this->flowRunId}:agent", 1800)
            ->block(1200, function () use ($exec) {
                $exec->executeNode($this->flowRunId, $this->flowNodeId);
            });
    }
}
