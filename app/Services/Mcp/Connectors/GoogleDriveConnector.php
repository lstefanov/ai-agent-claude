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
            ['name' => 'drive.list_files', 'description' => 'Файлове в папка (или по заявка)', 'writes' => false,
                'parameters' => ['folder_id' => ['type' => 'string'], 'query' => ['type' => 'string'], 'max' => ['type' => 'integer']]],
            ['name' => 'drive.get_file_content', 'description' => 'Текстово съдържание на файл (Docs/текст)', 'writes' => false,
                'parameters' => ['file_id' => ['type' => 'string']]],
            ['name' => 'drive.upload_file', 'description' => 'Качва текстов файл в папка', 'writes' => true,
                'parameters' => ['name' => ['type' => 'string'], 'content' => ['type' => 'string'], 'mime' => ['type' => 'string'], 'folder_id' => ['type' => 'string']]],
            ['name' => 'drive.create_doc', 'description' => 'Нов Google Doc с текст', 'writes' => true,
                'parameters' => ['title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'folder_id' => ['type' => 'string']]],
        ];
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

    private function client(): PendingRequest
    {
        return Http::withToken((string) ($this->credentials['access_token'] ?? ''))->timeout(30);
    }

    private function listFiles(array $params): McpToolResult
    {
        $q = $params['query'] ?? null;
        if (! empty($params['folder_id'])) {
            $q = "'".$params['folder_id']."' in parents".($q ? " and {$q}" : '');
        }

        $res = $this->client()->acceptJson()->get(self::BASE.'/files', array_filter([
            'q' => $q,
            'pageSize' => min(100, max(1, (int) ($params['max'] ?? 25))),
            'fields' => 'files(id,name,mimeType,modifiedTime)',
        ], fn ($v) => $v !== null));
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        $files = (array) $res->json('files', []);
        $lines = array_map(fn ($f) => '- '.($f['name'] ?? '?').' ('.($f['id'] ?? '').', '.($f['mimeType'] ?? '').')', $files);

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
