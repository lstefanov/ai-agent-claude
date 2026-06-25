<?php

namespace App\Jobs;

use App\Models\FlowEvalRun;
use App\Services\EvalRunnerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Оценява изхода на завършил eval FlowRun (правила + LLM-as-judge → score).
 * Диспечва се от GraphFlowExecutor::finalize() когато run-ът е бил eval. На
 * `default` опашката.
 */
class JudgeEvalRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public readonly int $evalRunId)
    {
        $this->onQueue('default');
    }

    public function handle(EvalRunnerService $runner): void
    {
        $evalRun = FlowEvalRun::find($this->evalRunId);
        if (! $evalRun) {
            return;
        }

        $runner->judgeRun($evalRun);
    }

    public function failed(?Throwable $e): void
    {
        FlowEvalRun::whereKey($this->evalRunId)
            ->whereIn('status', ['pending', 'running'])
            ->update(['status' => 'failed', 'error' => $e?->getMessage() ?? 'Judge job се провали.']);
    }
}
