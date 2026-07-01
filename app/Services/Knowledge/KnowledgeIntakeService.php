<?php

namespace App\Services\Knowledge;

use App\Jobs\IngestConnectorFileJob;
use App\Jobs\IngestResourceJob;
use App\Jobs\IngestUrlResourceJob;
use App\Models\Company;
use App\Models\CompanyConnector;
use App\Models\KnowledgeResource;
use App\Services\WebPageCacheService;
use Illuminate\Http\UploadedFile;

/**
 * Единна входна точка за добавяне на знание (бележка, файл, URL, конектор,
 * уеб-проучване, линк към съществуващ ресурс). Създава KnowledgeResource,
 * подпечатва provenance в meta и пуска правилния ingest job. Не прави
 * extract/crawl — това остава в jobs/KnowledgeIngestor. И порталната „База
 * знания", и попъпът за задачи минават оттук, за да не се разминават.
 */
class KnowledgeIntakeService
{
    /** Бележка (заглавие + текст) → note ресурс. */
    public function fromText(Company $company, string $title, string $content, array $context = []): KnowledgeResource
    {
        $resource = $company->knowledgeResources()->create([
            'folder_id' => $context['folder_id'] ?? null,
            'type' => 'note',
            'title' => trim($title),
            'content' => $content,
            'status' => 'pending',
            'meta' => $this->meta($context),
        ]);

        IngestResourceJob::dispatch($resource->id);

        return $resource;
    }

    /** Качен файл → upload/image ресурс. Връща null при SHA-256 дубликат. */
    public function fromUpload(Company $company, UploadedFile $file, array $context = []): ?KnowledgeResource
    {
        $hash = hash_file('sha256', $file->getRealPath());

        $duplicate = $company->knowledgeResources()
            ->where('status', 'ready')
            ->where('meta->file_sha256', $hash)
            ->exists();

        if ($duplicate) {
            return null;
        }

        $path = $file->store("knowledge/{$company->id}", 'local');

        $resource = $company->knowledgeResources()->create([
            'folder_id' => $context['folder_id'] ?? null,
            'type' => str_starts_with((string) $file->getMimeType(), 'image/') ? 'image' : 'upload',
            'title' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'storage_path' => $path,
            'status' => 'pending',
            'meta' => $this->meta($context) + ['file_sha256' => $hash],
        ]);

        IngestResourceJob::dispatch($resource->id);

        return $resource;
    }

    /**
     * URL ресурс. $singlePage=true ingest-ва само тази страница (без BFS краул),
     * ползва се за избрани уеб-резултати. Хвърля InvalidArgumentException при
     * невалиден URL; връща null при дубликат (нормализиран url_hash).
     */
    public function fromUrl(Company $company, string $url, array $context = [], bool $singlePage = false): ?KnowledgeResource
    {
        $normalized = app(WebPageCacheService::class)->normalizeUrl($url);
        if ($normalized === null) {
            throw new \InvalidArgumentException('Невалиден URL адрес.');
        }

        $urlHash = hash('sha256', $normalized);
        if ($company->knowledgeResources()->where('url_hash', $urlHash)->exists()) {
            return null;
        }

        $meta = $this->meta($context);
        if ($singlePage) {
            $meta['single_page'] = true;
        }

        $resource = $company->knowledgeResources()->create([
            'folder_id' => $context['folder_id'] ?? null,
            'type' => 'url',
            'title' => parse_url($normalized, PHP_URL_HOST).(parse_url($normalized, PHP_URL_PATH) ?: ''),
            'url' => $normalized,
            'url_hash' => $urlHash,
            'status' => 'pending',
            'meta' => $meta,
        ]);

        IngestUrlResourceJob::dispatch($resource->id);

        return $resource;
    }

    /**
     * Избрани уеб-резултати → по един single-page URL ресурс. Маркира ги
     * audience=external (конкурентни/пазарни данни, вж. KnowledgeSynthesizer).
     *
     * @param  array<int, string>  $urls
     * @return array<int, KnowledgeResource>
     */
    public function fromWebResearch(Company $company, array $urls, array $context = []): array
    {
        $context['source'] = 'task_web_research';
        $context['audience'] = 'external';

        $created = [];
        foreach ($urls as $url) {
            try {
                $resource = $this->fromUrl($company, (string) $url, $context, singlePage: true);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if ($resource !== null) {
                $created[] = $resource;
            }
        }

        return $created;
    }

    /**
     * Файл от свързана интеграция → pending shell ресурс. Реалният download +
     * тип (note за native текст, upload за бинарни) се решават в
     * IngestConnectorFileJob, за да не блокира HTTP заявката. Дедуп по вече
     * импортиран connector_file_id → само линква към задачата и връща null.
     */
    public function fromConnectorFile(Company $company, CompanyConnector $connector, array $fileRef, array $context = []): ?KnowledgeResource
    {
        $fileId = (string) ($fileRef['file_id'] ?? $fileRef['spreadsheet_id'] ?? $fileRef['document_id'] ?? $fileRef['message_id'] ?? $fileRef['calendar_id'] ?? '');

        if ($fileId !== '') {
            $existing = $company->knowledgeResources()
                ->where('status', 'ready')
                ->where('meta->provenance->connector_file_id', $fileId)
                ->first();

            if ($existing !== null) {
                $this->linkExisting($company, $existing, $context);

                return null;
            }
        }

        $context['source'] = 'task_connector';
        $context['connector_type'] = $connector->connector_type;
        $context['connector_file_id'] = $fileId;

        $meta = $this->meta($context) + ['connector' => ['connector_id' => $connector->id] + $fileRef];

        $resource = $company->knowledgeResources()->create([
            'type' => 'note', // placeholder — IngestConnectorFileJob задава реалния тип
            'title' => $context['title'] ?? ('Импорт от '.($connector->display_name ?: $connector->connector_type)),
            'status' => 'pending',
            'meta' => $meta,
        ]);

        IngestConnectorFileJob::dispatch($resource->id);

        return $resource;
    }

    /** Линква съществуващ готов ресурс към задача (meta.task_links) — без копие/ingest. */
    public function linkExisting(Company $company, KnowledgeResource $resource, array $context = []): KnowledgeResource
    {
        $meta = $resource->meta ?? [];
        $links = $meta['task_links'] ?? [];
        $taskId = $context['assistant_task_id'] ?? null;

        if ($taskId !== null && ! in_array($taskId, $links, true)) {
            $links[] = $taskId;
        }

        $meta['task_links'] = array_values($links);
        $resource->update(['meta' => $meta]);

        return $resource;
    }

    /** Строи meta.provenance с попълнени по подразбиране полета. */
    private function meta(array $context): array
    {
        return [
            'provenance' => [
                'source' => $context['source'] ?? 'kb_manual',
                'assistant_task_id' => $context['assistant_task_id'] ?? null,
                'requirement_key' => $context['requirement_key'] ?? null,
                'connector_type' => $context['connector_type'] ?? null,
                'connector_file_id' => $context['connector_file_id'] ?? null,
                'search_query' => $context['search_query'] ?? null,
                'audience' => $context['audience'] ?? 'own',
            ],
        ];
    }
}
