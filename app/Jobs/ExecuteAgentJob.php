<?php

namespace App\Jobs;

use App\Agents\AgentFactory;
use App\Models\Agent;
use App\Models\AgentRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ExecuteAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(
        public Agent $agent,
        public AgentRun $agentRun,
        public array $context = []
    ) {}

    public function handle(AgentFactory $factory): void
    {
        try {
            $instance = $factory->make($this->agent);
            $output = $instance->run($this->agent, $this->agentRun, $this->context);

            $this->agentRun->update([
                'status'       => 'completed',
                'output'       => $output,
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $this->agentRun->update([
                'status'       => 'failed',
                'error'        => $e->getMessage(),
                'completed_at' => now(),
            ]);
        }
    }
}
