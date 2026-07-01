<?php

namespace App\Services\Mcp\Connectors;

use App\Services\Mcp\McpToolResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Google Drive конектор (OAuth Google access_token, Drive API v3). Read:
 * list_files, get_file_content. Write: upload_file, create_doc.
 */
class GoogleDriveConnector extends AbstractConnector
{
    private const BASE = 'https://www.googleapis.com/drive/v3';

    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    public function listTools(): array
    {
        return [
            ['name' => 'drive.list_files', 'description' => 'Файлове в папка (с филтър за снимки + подредба по дата)', 'writes' => false,
                'parameters' => [
                    'folder_id' => ['label' => 'Папка', 'widget' => 'select', 'options' => 'drive_folders'],
                    'only_images' => ['label' => 'Само снимки (1/0)', 'widget' => 'text'],
                    'newest_first' => ['label' => 'Най-новите първо (1/0)', 'widget' => 'text'],
                    'max' => ['label' => 'Брой', 'widget' => 'text'],
                ]],
            ['name' => 'drive.get_file_content', 'description' => 'Текстово съдържание на файл (Docs/текст)', 'writes' => false,
                'parameters' => [
                    'folder_id' => ['label' => 'Папка', 'widget' => 'select', 'options' => 'drive_folders'],
                    'file_id' => ['label' => 'Файл', 'widget' => 'select', 'options' => 'drive_files', 'depends_on' => 'folder_id'],
                ]],
            ['name' => 'drive.upload_file', 'description' => 'Качва текстов файл в папка', 'writes' => true,
                'parameters' => [
                    'name' => ['label' => 'Име на файл', 'widget' => 'text'],
                    'content' => ['label' => 'Съдържание', 'widget' => 'textarea'],
                    'mime' => ['label' => 'MIME (по избор)', 'widget' => 'text'],
                    'folder_id' => ['label' => 'Папка', 'widget' => 'select', 'options' => 'drive_folders'],
                ]],
            ['name' => 'drive.create_doc', 'description' => 'Нов Google Doc с текст', 'writes' => true,
                'parameters' => [
                    'title' => ['label' => 'Заглавие', 'widget' => 'text'],
                    'content' => ['label' => 'Съдържание', 'widget' => 'textarea'],
                    'folder_id' => ['label' => 'Папка', 'widget' => 'select', 'options' => 'drive_folders'],
                ]],
        ];
    }

    public function listOptions(string $source, array $context = []): array
    {
        try {
            return match ($source) {
                'drive_folders' => $this->driveOptions("mimeType = 'application/vnd.google-apps.folder' and trashed = false", 'name'),
                'drive_files', 'drive_images' => $this->driveOptions($this->fileOptionQuery($context, $source === 'drive_images'), 'modifiedTime desc'),
                default => [],
            };
        } catch (\Throwable) {
            return [];
        }
    }

    private function fileOptionQuery(array $context, bool $imagesOnly): string
    {
        $clauses = ['trashed = false'];
        if (! empty($context['folder_id'])) {
            $clauses[] = "'".$context['folder_id']."' in parents";
        }
        if ($imagesOnly) {
            $clauses[] = "mimeType contains 'image/'";
        }

        return implode(' and ', $clauses);
    }

    private function driveOptions(string $q, string $orderBy): array
    {
        $res = $this->client()->acceptJson()->get(self::BASE.'/files', [
            'q' => $q, 'orderBy' => $orderBy, 'pageSize' => 100, 'fields' => 'files(id,name)',
        ]);

        return collect((array) $res->json('files', []))
            ->map(fn ($f) => ['value' => (string) ($f['id'] ?? ''), 'label' => (string) ($f['name'] ?? '')])
            ->values()->all();
    }

    public function testConnection(): bool
    {
        return $this->googleTokenValid($this->credentials['access_token'] ?? '');
    }

    public function callTool(string $tool, array $params): McpToolResult
    {
        try {
            return match ($tool) {
                'drive.list_files' => $this->listFiles($params),
                'drive.get_file_content' => $this->getContent($params),
                'drive.upload_file' => $this->upload($params, (string) ($params['mime'] ?? 'text/plain'), false),
                'drive.create_doc' => $this->upload($params, 'text/plain', true),
                default => McpToolResult::fail("Непознат tool: {$tool}"),
            };
        } catch (\Throwable $e) {
            return McpToolResult::fail("Drive грешка: {$e->getMessage()}");
        }
    }

    /**
     * Read-only ПЪЛНО сваляне на файл (без 8000-char cap на getContent) — за
     * ingest в базата знания. Google-native файлове се експортират: Docs→текст,
     * Sheets→xlsx, Slides→pdf; бинарните се теглят сурово. Връща
     * ['mime','name','bytes','exported'] (exported=true → съдържанието е текст).
     */
    public function downloadRaw(string $fileId): array
    {
        $fileId = trim($fileId);
        if ($fileId === '') {
            throw new \InvalidArgumentException('Липсва file_id');
        }

        $meta = $this->client()->acceptJson()->get(self::BASE."/files/{$fileId}", ['fields' => 'mimeType,name,size']);
        if ($meta->failed()) {
            throw new \RuntimeException("Drive метаданни HTTP {$meta->status()}: ".mb_substr($meta->body(), 0, 300));
        }

        $mime = (string) $meta->json('mimeType', '');
        $name = (string) $meta->json('name', 'file');
        $size = (int) $meta->json('size', 0);

        $maxBytes = (int) config('services.knowledge.max_file_mb', 20) * 1024 * 1024;
        if ($size > 0 && $size > $maxBytes) {
            throw new \RuntimeException('Файлът е твърде голям ('.round($size / 1048576, 1).' MB).');
        }

        if (str_starts_with($mime, 'application/vnd.google-apps.')) {
            [$exportMime, $ext, $exported] = match ($mime) {
                'application/vnd.google-apps.document' => ['text/plain', '', true],
                'application/vnd.google-apps.spreadsheet' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', '.xlsx', false],
                'application/vnd.google-apps.presentation' => ['application/pdf', '.pdf', false],
                default => ['text/plain', '', true],
            };

            $res = $this->client()->get(self::BASE."/files/{$fileId}/export", ['mimeType' => $exportMime]);
            if ($res->failed()) {
                throw new \RuntimeException("Drive export HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
            }

            // Google-native файлове нямат 'size' в метаданните → провери реалния размер СЛЕД export.
            $bytes = $this->guardSize($res->body(), $maxBytes);
            $finalName = ($ext !== '' && ! str_ends_with(mb_strtolower($name), $ext)) ? $name.$ext : $name;

            return ['mime' => $exportMime, 'name' => $finalName, 'bytes' => $bytes, 'exported' => $exported];
        }

        $res = $this->client()->get(self::BASE."/files/{$fileId}", ['alt' => 'media']);
        if ($res->failed()) {
            throw new \RuntimeException("Drive download HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        return ['mime' => $mime, 'name' => $name, 'bytes' => $this->guardSize($res->body(), $maxBytes), 'exported' => false];
    }

    /** Пази срещу твърде голям сваленбайтов масив (когато метаданните нямат size). */
    private function guardSize(string $bytes, int $maxBytes): string
    {
        if (strlen($bytes) > $maxBytes) {
            throw new \RuntimeException('Файлът е твърде голям ('.round(strlen($bytes) / 1048576, 1).' MB).');
        }

        return $bytes;
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) ($this->credentials['access_token'] ?? ''))->timeout(30);
    }

    private function listFiles(array $params): McpToolResult
    {
        $clauses = ['trashed = false'];
        if (! empty($params['folder_id'])) {
            $clauses[] = "'".$params['folder_id']."' in parents";
        }
        if (filter_var($params['only_images'] ?? false, FILTER_VALIDATE_BOOL)) {
            $clauses[] = "mimeType contains 'image/'";
        }
        if (! empty($params['query'])) {
            $clauses[] = (string) $params['query'];
        }

        $newestFirst = filter_var($params['newest_first'] ?? true, FILTER_VALIDATE_BOOL);

        $res = $this->client()->acceptJson()->get(self::BASE.'/files', [
            'q' => implode(' and ', $clauses),
            'orderBy' => $newestFirst ? 'modifiedTime desc' : 'name',
            'pageSize' => min(100, max(1, (int) ($params['max'] ?? 25))),
            'fields' => 'files(id,name,mimeType,modifiedTime,webViewLink,thumbnailLink)',
        ]);
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        $files = (array) $res->json('files', []);
        $lines = array_map(fn ($f) => '- '.($f['name'] ?? '?').' ('.($f['id'] ?? '').') '.($f['webViewLink'] ?? ''), $files);

        return McpToolResult::ok(count($files).' файла:'."\n".implode("\n", $lines), ['files' => $files]);
    }

    private function getContent(array $params): McpToolResult
    {
        $id = (string) ($params['file_id'] ?? '');
        if ($id === '') {
            return McpToolResult::fail('Липсва file_id');
        }

        $meta = $this->client()->acceptJson()->get(self::BASE."/files/{$id}", ['fields' => 'mimeType,name']);
        $mime = (string) $meta->json('mimeType', '');

        // Google Docs → export като text/plain; иначе直接 download.
        $res = str_starts_with($mime, 'application/vnd.google-apps.')
            ? $this->client()->get(self::BASE."/files/{$id}/export", ['mimeType' => 'text/plain'])
            : $this->client()->get(self::BASE."/files/{$id}", ['alt' => 'media']);

        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        return McpToolResult::ok(mb_substr($res->body(), 0, 8000), ['name' => $meta->json('name')]);
    }

    /** Multipart upload; $asDoc=true конвертира в Google Doc. */
    private function upload(array $params, string $mime, bool $asDoc): McpToolResult
    {
        $name = (string) ($params['name'] ?? $params['title'] ?? 'document.txt');
        $content = (string) ($params['content'] ?? '');
        $metadata = ['name' => $name];
        if (! empty($params['folder_id'])) {
            $metadata['parents'] = [(string) $params['folder_id']];
        }
        if ($asDoc) {
            $metadata['mimeType'] = 'application/vnd.google-apps.document';
        }

        $boundary = 'mcp'.bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n"
            .json_encode($metadata, JSON_UNESCAPED_UNICODE)."\r\n"
            ."--{$boundary}\r\nContent-Type: {$mime}\r\n\r\n{$content}\r\n--{$boundary}--";

        $res = $this->client()
            ->withBody($body, "multipart/related; boundary={$boundary}")
            ->post(self::UPLOAD.'?uploadType=multipart&fields=id,name');
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        return McpToolResult::ok(($asDoc ? 'Създаден Google Doc: ' : 'Качен файл: ').$name.' (id: '.$res->json('id', '?').')', ['id' => $res->json('id')]);
    }
}
