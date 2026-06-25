<?php

namespace App\Jobs;

use App\Models\KnowledgeResource;
use App\Services\Knowledge\KnowledgeIngestor;
use App\Support\LlmUsage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Ingest на url ресурс: BFS обхождане на сайта → per-page digest (с глобален
 * reuse по content_hash) → чанкове/embeddings → факти. Кралът има собствен
 * времеви бюджет (KnowledgeIngestor::CRAWL_BUDGET_SECONDS) — при изтичане
 * ресурсът става ready с partial флаг и повторно пускане продължава евтино
 * (всичко обходено е в глобалния кеш). DEFAULT queue.
 */
class IngestUrlResourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Под supervisor-default timeout (900) и под REDIS_QUEUE_RETRY_AFTER. */
    public int $timeout = 880;

    public int $tries = 1;

    public function __construct(public int $resourceId, public bool $force = false) {}

    public function handle(KnowledgeIngestor $ingestor): void
    {
        $claimed = KnowledgeResource::whereKey($this->resourceId)
            ->where('status', 'pending')
            ->update(['status' => 'processing']);

        if ($claimed === 0) {
            return; // already processed / in flight / deleted
        }

        $resource = KnowledgeResource::find($this->resourceId);
        if (! $resource || $resource->type !== 'url') {
            return;
        }

        try {
            $ingestor->ingestUrlResource($resource, $this->force);
        } catch (Throwable $e) {
            LlmUsage::take();
            $resource->update([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ]);
            report($e);
        }
    }
}
