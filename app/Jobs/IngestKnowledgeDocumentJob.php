<?php

namespace App\Jobs;

use App\Models\KnowledgeDocument;
use App\Services\KnowledgeService;
use App\Support\LlmUsage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Knowledge-base ingestion: extract → chunk → embed one document. Runs on the
 * DEFAULT queue so it never competes with node jobs for the `flows` workers.
 * The atomic pending→processing claim makes concurrent dispatches a no-op.
 */
class IngestKnowledgeDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** OCR can take ~2 min and a large document means hundreds of embed calls. */
    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $documentId) {}

    public function handle(KnowledgeService $knowledge): void
    {
        $claimed = KnowledgeDocument::whereKey($this->documentId)
            ->where('status', 'pending')
            ->update(['status' => 'processing']);

        if ($claimed === 0) {
            return; // already processed / in flight / deleted
        }

        $document = KnowledgeDocument::find($this->documentId);
        if (! $document) {
            return;
        }

        try {
            $knowledge->ingest($document);
        } catch (Throwable $e) {
            // Drain accumulated usage so the worker's next job isn't misattributed.
            LlmUsage::take();
            $document->update([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ]);
            report($e);
        }
    }
}
