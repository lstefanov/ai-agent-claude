<?php

namespace App\Jobs;

use App\Models\KnowledgeResource;
use App\Services\Knowledge\KnowledgeIngestor;
use App\Services\Org\Billing\BillableOperationService;
use App\Support\LlmUsage;
use App\Support\ModelLevel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
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

    public string $opToken;

    public function __construct(public int $resourceId, public bool $force = false, ?string $opToken = null)
    {
        $this->opToken = $opToken ?: (string) Str::uuid();
    }

    public function handle(KnowledgeIngestor $ingestor, BillableOperationService $billable): void
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
            $billable->run(
                $resource->company_id,
                'knowledge_ingest',
                $resource,
                fn () => $ingestor->ingestUrlResource($resource, $this->force),
                opKey: "knowledge_ingest:{$this->opToken}",
                level: ModelLevel::fromRequest((string) config('billing.context_levels.knowledge_ingest', 'medium')),
                origin: 'manual',
                hardGate: false,
            );
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
