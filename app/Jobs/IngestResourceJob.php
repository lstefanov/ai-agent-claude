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
 * Ingest на note/upload/image ресурс: extract → синтез → chunk → embed.
 * Runs on the DEFAULT queue so it never competes with node jobs for the
 * `flows` workers. The atomic pending→processing claim makes concurrent
 * dispatches a no-op.
 */
class IngestResourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** OCR can take ~2 min and a large document means hundreds of embed calls. */
    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $resourceId) {}

    public function handle(KnowledgeIngestor $ingestor): void
    {
        $claimed = KnowledgeResource::whereKey($this->resourceId)
            ->where('status', 'pending')
            ->update(['status' => 'processing']);

        if ($claimed === 0) {
            return; // already processed / in flight / deleted
        }

        $resource = KnowledgeResource::find($this->resourceId);
        if (! $resource) {
            return;
        }

        try {
            $ingestor->ingestResource($resource);
        } catch (Throwable $e) {
            // Drain accumulated usage so the worker's next job isn't misattributed.
            LlmUsage::take();
            $resource->update([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ]);
            report($e);
        }
    }
}
