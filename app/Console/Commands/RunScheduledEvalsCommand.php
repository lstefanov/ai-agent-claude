<?php

namespace App\Console\Commands;

use App\Jobs\RunFlowEvalJob;
use App\Models\FlowEvalRun;
use App\Models\FlowVersion;
use App\Support\ModelLevel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Nightly eval — пуска всички активни версии с поне 1 активен тест на
 * СОБСТВЕНОТО им ниво. Резултатите се записват → ModelRouterService ги вижда.
 * След dispatch-а докладва регресии върху ВЕЧЕ завършилата история (днешните
 * резултати влизат в историята за следващата нощ — затова докладът изостава с
 * един цикъл, което е очаквано).
 */
class RunScheduledEvalsCommand extends Command
{
    protected $signature = 'flows:run-evals {--flow= : ограничи до един flow id}';

    protected $description = 'Пуска eval на всички активни версии с поне 1 активен тест, на собственото им ниво';

    public function handle(): int
    {
        $token = 'nightly-'.now()->format('Ymd-His');

        $versions = FlowVersion::query()
            ->where('is_active', true)
            ->when($this->option('flow'), fn ($q) => $q->where('flow_id', (int) $this->option('flow')))
            ->whereHas('flow.evalCases', fn ($q) => $q->where('is_active', true))
            ->with('flow')
            ->get();

        $dispatched = 0;
        foreach ($versions as $version) {
            $level = ModelLevel::fromRequest($version->model_level); // custom/null → medium
            $caseIds = $version->flow->evalCases()->where('is_active', true)->pluck('id');
            foreach ($caseIds as $caseId) {
                RunFlowEvalJob::dispatch((int) $caseId, $version->id, $level->value, $token);
                $dispatched++;
            }
        }

        $this->info("Nightly eval dispatched: {$dispatched} (token {$token})");

        $this->reportRegressions();

        return self::SUCCESS;
    }

    /** Лог-предупреждение, ако последният completed score е паднал >10 т. спрямо предишния. */
    private function reportRegressions(): void
    {
        $byKey = [];
        FlowEvalRun::where('status', 'completed')
            ->whereNotNull('score')
            ->orderByDesc('id')
            ->get(['flow_version_id', 'eval_case_id', 'model_level', 'score'])
            ->each(function ($run) use (&$byKey) {
                $byKey["{$run->flow_version_id}|{$run->eval_case_id}|{$run->model_level}"][] = $run;
            });

        foreach ($byKey as $list) {
            if (count($list) < 2) {
                continue;
            }
            $drop = (float) $list[1]->score - (float) $list[0]->score;
            if ($drop > 10) {
                $msg = "Спад в качеството: версия #{$list[0]->flow_version_id} тест #{$list[0]->eval_case_id} "
                    ."ниво {$list[0]->model_level}: {$list[1]->score} → {$list[0]->score} (−".round($drop, 1).')';
                Log::warning("[Eval] {$msg}");
                $this->warn("⚠️ {$msg}");
            }
        }
    }
}
