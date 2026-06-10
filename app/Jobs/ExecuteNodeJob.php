<?php

namespace App\Jobs;

use App\Models\FlowNode;
use App\Services\NodeExecutorService;
use App\Support\OllamaSemaphore;
use App\Support\PaidModel;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Unit of work: executes one graph node.
 *
 * Nodes within a topological wave are dispatched together in a Bus::batch and
 * genuinely run in parallel across queue workers:
 *  - cloud-pinned nodes (openai/*, anthropic/*, deepseek/*, xai/*, qwen/*)
 *    execute immediately — they don't touch local VRAM;
 *  - local Ollama nodes share the global OllamaSemaphore slots
 *    (OLLAMA_MAX_CONCURRENT) so the GPU never thrashes between models.
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

        $model = (string) FlowNode::whereKey($this->flowNodeId)->value('model');

        if (PaidModel::isPaid($model)) {
            $exec->executeNode($this->flowRunId, $this->flowNodeId);

            return;
        }

        OllamaSemaphore::run(fn () => $exec->executeNode($this->flowRunId, $this->flowNodeId));
    }
}
