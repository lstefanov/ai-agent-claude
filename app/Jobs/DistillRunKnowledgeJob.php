<?php

namespace App\Jobs;

use App\Models\FlowRun;
use App\Models\KnowledgeChunk;
use App\Services\EmbeddingService;
use App\Services\FlowMemoryService;
use App\Services\Knowledge\TextChunker;
use App\Services\KnowledgeService;
use App\Support\LlmUsage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Distill a successful run into the company's HISTORY collection
 * (source_type=run): the final output + the content nodes' outputs, with
 * content COPIED — node_runs cascade-delete when a flow version is rewritten,
 * flow_runs.final_output is the only survivor. History is searchable via
 * knowledge_search (collection=history), never injected into prompts.
 * Best-effort on the DEFAULT queue — a failure here never matters.
 */
class DistillRunKnowledgeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $flowRunId) {}

    public function handle(
        EmbeddingService $embeddings,
        TextChunker $chunker,
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

        $company = $flowRun->flow->company;
        $contentHash = hash('sha256', trim((string) $flowRun->final_output));

        // Идентичен финал на вече запомнен run → нищо ново за помнене.
        if ($company->knowledgeDocuments()
            ->where('source_type', 'run')
            ->where('content_hash', $contentHash)
            ->exists()) {
            return;
        }

        $embeddings = $embeddings->withProvider(config('services.knowledge.embedding_provider'));

        $document = $company->knowledgeDocuments()->create([
            'source_type' => 'run',
            'flow_run_id' => $flowRun->id,
            'title' => mb_substr("{$flowRun->flow->name} — изпълнение #{$flowRun->id} — ".now()->format('d.m.Y'), 0, 300),
            'mime' => 'text/markdown',
            'status' => 'processing',
        ]);

        try {
            $sections = [];

            foreach ($chunker->chunk(trim((string) $flowRun->final_output)) as $chunk) {
                $sections[] = ['content' => $chunk['content'], 'meta' => $chunk['meta'] + ['section' => 'final']];
            }

            // Изходите на съдържателните агенти — детайлът, който финалната
            // композиция често дедуплицира/съкращава.
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
                foreach ($chunker->chunk(trim((string) $nodeRun->output)) as $chunk) {
                    $sections[] = [
                        'content' => $chunk['content'],
                        'meta' => $chunk['meta'] + [
                            'section' => 'node',
                            'node_key' => $nodeRun->node_key,
                            'node_name' => $nodeRun->flowNode->name,
                        ],
                    ];
                }
            }

            $tag = $embeddings->providerTag();
            $rows = [];
            foreach ($sections as $seq => $section) {
                $vector = $embeddings->embed($section['content'], [
                    'company_id' => $company->id,
                    'flow_id' => $flowRun->flow_id,
                    'flow_run_id' => $flowRun->id,
                    'knowledge_document_id' => $document->id,
                ]);
                $rows[] = [
                    'knowledge_document_id' => $document->id,
                    'company_id' => $company->id,
                    'seq' => $seq,
                    'content' => $section['content'],
                    'embedding' => $vector !== null ? json_encode($vector) : null,
                    'embedding_provider' => $vector !== null ? $tag : null,
                    'meta' => json_encode($section['meta']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            foreach (array_chunk($rows, 100) as $batch) {
                KnowledgeChunk::insert($batch);
            }

            $usage = LlmUsage::take();
            $document->update([
                'status' => 'ready',
                'content_hash' => $contentHash,
                'chunk_count' => count($rows),
                'cost_usd' => round((float) ($usage['cost_usd'] ?? 0), 6),
                'ingested_at' => now(),
            ]);
        } catch (Throwable $e) {
            LlmUsage::take();
            $document->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            report($e);
        }
    }
}
