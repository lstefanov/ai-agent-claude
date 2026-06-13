<?php

namespace App\Services\Mcp\Connectors;

use App\Services\Mcp\McpToolResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Notion конектор (internal integration token / OAuth access_token).
 * Read: query_database, get_page_content. Write: create_page, update_page.
 */
class NotionConnector extends AbstractConnector
{
    public function listTools(): array
    {
        return [
            ['name' => 'notion.query_database', 'description' => 'Query на Notion database с филтри/сортиране', 'writes' => false,
                'parameters' => ['database_id' => ['type' => 'string'], 'filter' => ['type' => 'object'], 'sorts' => ['type' => 'array'], 'page_size' => ['type' => 'integer']]],
            ['name' => 'notion.get_page_content', 'description' => 'Чете текстовото съдържание на страница', 'writes' => false,
                'parameters' => ['page_id' => ['type' => 'string']]],
            ['name' => 'notion.create_page', 'description' => 'Нова страница в database/страница', 'writes' => true,
                'parameters' => ['database_id' => ['type' => 'string'], 'page_id' => ['type' => 'string'], 'title' => ['type' => 'string'], 'title_property' => ['type' => 'string'], 'properties' => ['type' => 'object'], 'content' => ['type' => 'string']]],
            ['name' => 'notion.update_page', 'description' => 'Обновява properties на страница', 'writes' => true,
                'parameters' => ['page_id' => ['type' => 'string'], 'properties' => ['type' => 'object']]],
        ];
    }

    public function testConnection(): bool
    {
        try {
            return $this->client()->get('/users/me')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function callTool(string $tool, array $params): McpToolResult
    {
        try {
            return match ($tool) {
                'notion.query_database' => $this->queryDatabase($params),
                'notion.get_page_content' => $this->getPageContent($params),
                'notion.create_page' => $this->createPage($params),
                'notion.update_page' => $this->updatePage($params),
                default => McpToolResult::fail("Непознат tool: {$tool}"),
            };
        } catch (\Throwable $e) {
            return McpToolResult::fail("Notion грешка: {$e->getMessage()}");
        }
    }

    private function client(): PendingRequest
    {
        $cfg = config('mcp.notion');
        $token = (string) ($this->credentials['token'] ?? $this->credentials['access_token'] ?? '');

        return Http::baseUrl($cfg['api_base'])
            ->withToken($token)
            ->withHeaders(['Notion-Version' => $cfg['version']])
            ->acceptJson()
            ->timeout(20);
    }

    private function queryDatabase(array $params): McpToolResult
    {
        $id = (string) ($params['database_id'] ?? '');
        if ($id === '') {
            return McpToolResult::fail('Липсва database_id');
        }

        $payload = array_filter([
            'filter' => $params['filter'] ?? null,
            'sorts' => $params['sorts'] ?? null,
            'page_size' => isset($params['page_size']) ? (int) $params['page_size'] : null,
        ], fn ($v) => $v !== null);

        $res = $this->client()->post("/databases/{$id}/query", $payload);
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        $results = (array) $res->json('results', []);
        $lines = [];
        foreach ($results as $page) {
            $lines[] = '- '.($page['id'] ?? '?').': '.$this->pageTitle($page);
        }
        $text = count($results).' записа от database:'."\n".implode("\n", $lines);

        return McpToolResult::ok($text, ['results' => $results]);
    }

    private function getPageContent(array $params): McpToolResult
    {
        $id = (string) ($params['page_id'] ?? '');
        if ($id === '') {
            return McpToolResult::fail('Липсва page_id');
        }

        $res = $this->client()->get("/blocks/{$id}/children", ['page_size' => 100]);
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        $text = $this->blocksToText((array) $res->json('results', []));

        return McpToolResult::ok($text !== '' ? $text : '(празна страница)', ['blocks' => $res->json('results', [])]);
    }

    private function createPage(array $params): McpToolResult
    {
        if (! empty($params['database_id'])) {
            $parent = ['database_id' => (string) $params['database_id']];
        } elseif (! empty($params['page_id'])) {
            $parent = ['page_id' => (string) $params['page_id']];
        } else {
            return McpToolResult::fail('Трябва database_id или page_id (родител)');
        }

        $payload = ['parent' => $parent];

        if (! empty($params['properties']) && is_array($params['properties'])) {
            $payload['properties'] = $params['properties'];
        } elseif (! empty($params['title'])) {
            $prop = (string) ($params['title_property'] ?? 'Name');
            $payload['properties'] = [
                $prop => ['title' => [['type' => 'text', 'text' => ['content' => (string) $params['title']]]]],
            ];
        } else {
            $payload['properties'] = new \stdClass;
        }

        if (! empty($params['content'])) {
            $payload['children'] = $this->textToParagraphs((string) $params['content']);
        }

        $res = $this->client()->post('/pages', $payload);
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        // §14: write резюме без съдържанието.
        return McpToolResult::ok('Създадена страница в Notion (id: '.$res->json('id', '?').')', ['id' => $res->json('id')]);
    }

    private function updatePage(array $params): McpToolResult
    {
        $id = (string) ($params['page_id'] ?? '');
        if ($id === '' || empty($params['properties'])) {
            return McpToolResult::fail('Трябва page_id и properties');
        }

        $res = $this->client()->patch("/pages/{$id}", ['properties' => $params['properties']]);
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        return McpToolResult::ok("Обновена страница в Notion (id: {$id})", ['id' => $id]);
    }

    private function pageTitle(array $page): string
    {
        foreach ((array) ($page['properties'] ?? []) as $prop) {
            if (($prop['type'] ?? null) === 'title') {
                return $this->richText($prop['title'] ?? []);
            }
        }

        return '(без заглавие)';
    }

    private function blocksToText(array $blocks): string
    {
        $out = [];
        foreach ($blocks as $block) {
            $type = $block['type'] ?? '';
            $rich = $block[$type]['rich_text'] ?? null;
            if (is_array($rich)) {
                $line = $this->richText($rich);
                if ($line !== '') {
                    $out[] = $line;
                }
            }
        }

        return implode("\n", $out);
    }

    private function richText(array $rich): string
    {
        return trim(implode('', array_map(fn ($r) => $r['plain_text'] ?? ($r['text']['content'] ?? ''), $rich)));
    }

    private function textToParagraphs(string $content): array
    {
        $blocks = [];
        foreach (preg_split('/\n{2,}/', trim($content)) as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            $blocks[] = [
                'object' => 'block',
                'type' => 'paragraph',
                'paragraph' => ['rich_text' => [['type' => 'text', 'text' => ['content' => mb_substr($para, 0, 2000)]]]],
            ];
        }

        return $blocks;
    }
}
