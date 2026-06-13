<?php

namespace App\Jobs;

use App\Models\FlowEvalCase;
use App\Models\FlowVersion;
use App\Services\EvalRunnerService;
use App\Support\ModelLevel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Подготвя и диспечва един eval FlowRun (case × version × level). НЕ чака —
 * самото judge-ване става след finalize() през JudgeEvalRunJob. На `default`
 * опашката, за да не се състезава с продукционните runs (нодовете им вървят
 * на `flows`).
 */
class RunFlowEvalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        public readonly int $evalCaseId,
        public readonly int $versionId,
        public readonly string $level,
        public readonly ?string $sessionToken = null,
    ) {
        // default опашка — никога не се състезава с продукционните runs (flows).
        $this->onQueue('default');
    }

    public function handle(EvalRunnerService $runner): void
    {
        $case = FlowEvalCase::find($this->evalCaseId);
        $version = FlowVersion::find($this->versionId);

        if (! $case || ! $version) {
            Log::warning("[Eval] Липсва case/version ({$this->evalCaseId}/{$this->versionId}) — eval пропуснат.");

            return;
        }

        $runner->runCase($case, $version, ModelLevel::from($this->level), $this->sessionToken);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('[Eval] RunFlowEvalJob се провали: '.($e?->getMessage() ?? 'неизвестна грешка'));
    }
}
