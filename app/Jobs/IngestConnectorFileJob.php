<?php

namespace App\Jobs;

use App\Models\CompanyConnector;
use App\Models\KnowledgeResource;
use App\Services\Knowledge\ConnectorKnowledgeFetcher;
use App\Services\Knowledge\KnowledgeIngestor;
use App\Services\Org\Billing\BillableOperationService;
use App\Support\LlmUsage;
use App\Support\ModelLevel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Пълно сваляне на файл от свързана интеграция (Drive/Sheets/Docs/Gmail/
 * Calendar) → задава реалния тип на ресурса (note за native текст, upload за
 * бинарен) → минава по нормалния ingest. Тежкото сваляне/OCR не блокира HTTP
 * заявката. DEFAULT queue; billable за паритет с URL ingest-а.
 */
class IngestConnectorFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public string $opToken;

    public function __construct(public int $resourceId, ?string $opToken = null)
    {
        $this->opToken = $opToken ?: (string) Str::uuid();
    }

    public function handle(
        ConnectorKnowledgeFetcher $fetcher,
        KnowledgeIngestor $ingestor,
        BillableOperationService $billable,
    ): void {
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
            $ref = (array) ($resource->meta['connector'] ?? []);
            $connector = CompanyConnector::where('id', (int) ($ref['connector_id'] ?? 0))
                ->where('company_id', $resource->company_id)
                ->first();

            if (! $connector) {
                throw new RuntimeException('Интеграцията не е намерена или не е на тази фирма.');
            }

            $billable->run(
                $resource->company_id,
                'knowledge_ingest',
                $resource,
                function () use ($fetcher, $ingestor, $resource, $connector, $ref) {
                    $payload = $fetcher->fetch($connector, $ref);
                    if (! $this->applyPayload($resource, $payload)) {
                        return; // дедуп → shell-ресурсът е премахнат, няма какво да ingest-ваме
                    }
                    $ingestor->ingestResource($resource->fresh());
                },
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

    /**
     * Превръща свалените данни в готов за ingest ресурс: бинарен → upload/image
     * със storage_path; текст → note със content. Запазва provenance meta.
     *
     * @param  array{kind: string, title?: string, content?: string, name?: string, mime?: string, bytes?: string}  $payload
     * @return bool false → дубликат: shell-ресурсът е изтрит и ingest се пропуска
     */
    private function applyPayload(KnowledgeResource $resource, array $payload): bool
    {
        if (($payload['kind'] ?? '') === 'file') {
            $bytes = (string) ($payload['bytes'] ?? '');
            if ($bytes === '') {
                throw new RuntimeException('Празен файл от интеграцията.');
            }

            $hash = hash('sha256', $bytes);
            $duplicate = KnowledgeResource::where('company_id', $resource->company_id)
                ->where('status', 'ready')
                ->where('meta->file_sha256', $hash)
                ->whereKeyNot($resource->id)
                ->exists();

            if ($duplicate) {
                $resource->delete(); // същият файл вече е в базата → без дубликат
                LlmUsage::take();

                return false;
            }

            $mime = (string) ($payload['mime'] ?? 'application/octet-stream');
            $path = 'knowledge/'.$resource->company_id.'/'.Str::random(40);
            Storage::disk('local')->put($path, $bytes);

            $resource->update([
                'type' => str_starts_with($mime, 'image/') ? 'image' : 'upload',
                'title' => (string) ($payload['title'] ?? $resource->title),
                'original_name' => (string) ($payload['name'] ?? 'file'),
                'mime' => $mime,
                'size_bytes' => strlen($bytes),
                'storage_path' => $path,
                'content' => null,
                'meta' => array_merge((array) $resource->meta, ['file_sha256' => $hash]),
            ]);

            return true;
        }

        $content = trim((string) ($payload['content'] ?? ''));
        if ($content === '') {
            throw new RuntimeException('Интеграцията не върна съдържание.');
        }

        $resource->update([
            'type' => 'note',
            'title' => (string) ($payload['title'] ?? $resource->title),
            'content' => $content,
        ]);

        return true;
    }
}
