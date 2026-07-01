<?php

namespace App\Services\Mcp\Connectors;

use App\Services\Mcp\McpToolResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Google Docs конектор (OAuth Google access_token, Docs API v1 + Drive API v3 за
 * листинг). Read: list_documents, get_document. Write: create_document,
 * append_text. Token refresh се прави в McpClientService.
 */
class GoogleDocsConnector extends AbstractConnector
{
    private const DOCS = 'https://docs.googleapis.com/v1/documents';

    private const DRIVE = 'https://www.googleapis.com/drive/v3';

    private const DOC_MIME = 'application/vnd.google-apps.document';

    public function listTools(): array
    {
        $doc = ['label' => 'Документ', 'widget' => 'select', 'options' => 'docs_documents'];

        return [
            ['name' => 'docs.list_documents', 'description' => 'Google Docs документи на акаунта (по избор филтър по име)', 'writes' => false,
                'parameters' => [
                    'query' => ['label' => 'Търсене по име (по избор)', 'widget' => 'text'],
                    'max' => ['label' => 'Брой', 'widget' => 'text'],
                ]],
            ['name' => 'docs.get_document', 'description' => 'Текстово съдържание на документ (по ID или Docs URL)', 'writes' => false,
                'parameters' => [
                    'document_id' => $doc,
                ]],
            ['name' => 'docs.create_document', 'description' => 'Нов Google Doc със заглавие + текст', 'writes' => true,
                'parameters' => [
                    'title' => ['label' => 'Заглавие', 'widget' => 'text'],
                    'content' => ['label' => 'Съдържание', 'widget' => 'textarea'],
                ]],
            ['name' => 'docs.append_text', 'description' => 'Добавя текст в края на документ', 'writes' => true,
                'parameters' => [
                    'document_id' => $doc,
                    'text' => ['label' => 'Текст', 'widget' => 'textarea'],
                ]],
        ];
    }

    public function listOptions(string $source, array $context = []): array
    {
        if ($source !== 'docs_documents') {
            return [];
        }
        try {
            $res = $this->client()->acceptJson()->get(self::DRIVE.'/files', [
                'q' => "mimeType = '".self::DOC_MIME."' and trashed = false",
                'orderBy' => 'modifiedTime desc',
                'pageSize' => 100,
                'fields' => 'files(id,name)',
            ]);

            return collect((array) $res->json('files', []))
                ->map(fn ($f) => ['value' => (string) ($f['id'] ?? ''), 'label' => (string) ($f['name'] ?? '')])
                ->filter(fn ($o) => $o['value'] !== '')->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function testConnection(): bool
    {
        return $this->googleTokenValid($this->credentials['access_token'] ?? '');
    }

    public function callTool(string $tool, array $params): McpToolResult
    {
        try {
            return match ($tool) {
                'docs.list_documents' => $this->listDocuments($params),
                'docs.get_document' => $this->getDocument($params),
                'docs.create_document' => $this->createDocument($params),
                'docs.append_text' => $this->appendText($params),
                default => McpToolResult::fail("Непознат tool: {$tool}"),
            };
        } catch (\Throwable $e) {
            return McpToolResult::fail("Docs грешка: {$e->getMessage()}");
        }
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) ($this->credentials['access_token'] ?? ''))->timeout(30);
    }

    private function listDocuments(array $params): McpToolResult
    {
        $clauses = ["mimeType = '".self::DOC_MIME."'", 'trashed = false'];
        if (! empty($params['query'])) {
            $clauses[] = "name contains '".str_replace("'", "\\'", (string) $params['query'])."'";
        }

        $res = $this->client()->acceptJson()->get(self::DRIVE.'/files', [
            'q' => implode(' and ', $clauses),
            'orderBy' => 'modifiedTime desc',
            'pageSize' => min(100, max(1, (int) ($params['max'] ?? 25))),
            'fields' => 'files(id,name,modifiedTime,webViewLink)',
        ]);
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        $files = (array) $res->json('files', []);
        $lines = array_map(fn ($f) => '- '.($f['name'] ?? '?').' ('.($f['id'] ?? '').') '.($f['webViewLink'] ?? ''), $files);

        return McpToolResult::ok(count($files).' документа:'."\n".implode("\n", $lines), ['files' => $files]);
    }

    private function getDocument(array $params): McpToolResult
    {
        $id = $this->documentId((string) ($params['document_id'] ?? ''));
        if ($id === '') {
            return McpToolResult::fail('Липсва document_id');
        }

        $res = $this->client()->acceptJson()->get(self::DOCS."/{$id}");
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        $title = (string) $res->json('title', '');
        $text = $this->extractText((array) $res->json('body.content', []));

        return McpToolResult::ok(($title !== '' ? $title."\n\n" : '').mb_substr($text, 0, 8000), ['title' => $title]);
    }

    private function createDocument(array $params): McpToolResult
    {
        $title = (string) ($params['title'] ?? 'Нов документ');
        $res = $this->client()->acceptJson()->post(self::DOCS, ['title' => $title]);
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        $id = (string) $res->json('documentId', '');
        $content = (string) ($params['content'] ?? '');
        if ($id !== '' && $content !== '') {
            $this->client()->acceptJson()->post(self::DOCS."/{$id}:batchUpdate", [
                'requests' => [['insertText' => ['location' => ['index' => 1], 'text' => $content]]],
            ]);
        }

        return McpToolResult::ok("Създаден Google Doc: {$title} (id: {$id})", ['id' => $id]);
    }

    private function appendText(array $params): McpToolResult
    {
        $id = $this->documentId((string) ($params['document_id'] ?? ''));
        $text = (string) ($params['text'] ?? '');
        if ($id === '' || $text === '') {
            return McpToolResult::fail('Трябва document_id и text');
        }

        $res = $this->client()->acceptJson()->post(self::DOCS."/{$id}:batchUpdate", [
            'requests' => [['insertText' => ['endOfSegmentLocation' => (object) [], 'text' => $text]]],
        ]);
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        return McpToolResult::ok('Добавен текст в документа', ['id' => $id]);
    }

    /** Приема суров ID или пълен Docs URL (…/document/d/<ID>/…). */
    private function documentId(string $raw): string
    {
        if (preg_match('#/document/d/([a-zA-Z0-9_-]+)#', $raw, $m)) {
            return $m[1];
        }

        return trim($raw);
    }

    /** Рекурсивно събира текста от Docs structural elements (параграфи + таблици). */
    private function extractText(array $content): string
    {
        $out = '';
        foreach ($content as $el) {
            foreach ((array) ($el['paragraph']['elements'] ?? []) as $pe) {
                $out .= (string) ($pe['textRun']['content'] ?? '');
            }
            foreach ((array) ($el['table']['tableRows'] ?? []) as $row) {
                foreach ((array) ($row['tableCells'] ?? []) as $cell) {
                    $out .= $this->extractText((array) ($cell['content'] ?? []));
                }
            }
        }

        return $out;
    }
}
