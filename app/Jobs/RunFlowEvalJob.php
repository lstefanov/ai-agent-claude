<?php

namespace App\Jobs;

use App\Models\FlowEvalCase;
use App\Models\FlowRun;
use App\Models\FlowVersion;
use App\Services\EvalRunnerService;
use App\Support\ModelLevel;
use DateTimeInterface;
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

    /** Реална грешка проваля веднага; release-ите от дросела не се броят тук. */
    public int $maxExceptions = 1;

    public function __construct(
        public readonly int $evalCaseId,
        public readonly int $versionId,
        public readonly string $level,
        public readonly ?string $sessionToken = null,
    ) {
        // default опашка — никога не се състезава с продукционните runs (flows).
        $this->onQueue('default');
    }

    /** Позволява многото release-и от дросела (retryUntil > maxTries проверката). */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(EvalRunnerService $runner): void
    {
        // Дросел: локалният GPU е 1 слот — твърде много паралелни eval flow-ове
        // гладуват и удрят node timeout-а. Изчакай, ако вече текат достатъчно.
        $max = (int) config('services.eval.max_concurrent', 3);
        $inflight = FlowRun::where('triggered_by', 'eval')
            ->whereIn('status', ['pending', 'running'])
            ->count();
        if ($inflight >= $max) {
            $this->release(20);

            return;
        }

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
