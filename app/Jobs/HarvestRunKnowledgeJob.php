<?php

namespace App\Jobs;

use App\Models\FlowRun;
use App\Models\KnowledgeFact;
use App\Services\FlowMemoryService;
use App\Services\Knowledge\KnowledgeFactService;
use App\Services\Knowledge\KnowledgeSynthesizer;
use App\Services\KnowledgeService;
use App\Support\LlmUsage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Жътва на знание от успешен run (т.5): изходите на агентите често съдържат
 * нова информация за фирмата (услуги, цени, контакти, конкуренти) — един
 * консервативен LLM pass я извлича като ФАКТИ и я upsert-ва във фирмения
 * профил. Така знанията се трупат с всеки run и са винаги up to date; всичко
 * личи в одит-историята и се трие с клик. Замества старата история-колекция
 * (DistillRunKnowledgeJob) — паметта на flow-а помни "какво произведохме",
 * фактите помнят "какво научихме за фирмата".
 * Best-effort on the DEFAULT queue — a failure here never matters.
 */
class HarvestRunKnowledgeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    private const MAX_NODE_CHARS = 5000;

    private const MAX_TOTAL_CHARS = 13000;

    public function __construct(public int $flowRunId) {}

    public function handle(
        KnowledgeSynthesizer $synthesizer,
        KnowledgeFactService $facts,
        FlowMemoryService $memory,
    ): void {
        $flowRun = FlowRun::find($this->flowRunId);

        if (! $flowRun
            || $flowRun->status !== 'completed'
            || trim((string) $flowRun->final_output) === ''
            || ! $flowRun->flow
            || ! KnowledgeService::enabledForFlow($flowRun->flow)) {
            return;
        }

        // Повторен dispatch за същия run → вече ожънато, нищо ново.
        if (KnowledgeFact::where('flow_run_id', $flowRun->id)->exists()) {
            return;
        }

        $company = $flowRun->flow->company;

        try {
            $content = "=== ФИНАЛЕН РЕЗУЛТАТ ===\n"
                .mb_substr(trim((string) $flowRun->final_output), 0, self::MAX_NODE_CHARS);

            // Изходите на съдържателните агенти — детайлът (цени, контакти),
            // който финалната композиция често съкращава.
            $nodeRuns = $flowRun->nodeRuns()
                ->with('flowNode')
                ->where('status', 'completed')
                ->get();

            foreach ($nodeRuns as $nodeRun) {
                if (! $nodeRun->flowNode
                    || ! $memory->isContentNode($nodeRun->flowNode)
                    || trim((string) $nodeRun->output) === '') {
                    continue;
                }

                $section = "\n\n=== АГЕНТ «{$nodeRun->flowNode->name}» ===\n"
                    .mb_substr(trim((string) $nodeRun->output), 0, self::MAX_NODE_CHARS);

                if (mb_strlen($content) + mb_strlen($section) > self::MAX_TOTAL_CHARS) {
                    break;
                }
                $content .= $section;
            }

            $llmContext = [
                'company_id' => $company->id,
                'flow_id' => $flowRun->flow_id,
                'flow_run_id' => $flowRun->id,
            ];

            $extracted = $synthesizer->extractFacts(
                $company,
                "Изходи от изпълнение #{$flowRun->id} на flow «{$flowRun->flow->name}»",
                $content,
                $llmContext,
            );

            if ($extracted === []) {
                LlmUsage::take();

                return;
            }

            $stats = $facts->upsertMany(
                $company,
                $extracted,
                'run',
                $flowRun->id,
                $flowRun->id,
                "run #{$flowRun->id} — {$flowRun->flow->name}",
                $llmContext,
            );

            LlmUsage::take();

            Log::info("[HarvestRunKnowledge] Run {$flowRun->id}: +{$stats['added']} факта, "
                ."{$stats['updated']} обновени, {$stats['skipped']} пропуснати.");
        } catch (Throwable $e) {
            LlmUsage::take();
            report($e);
        }
    }
}
