<?php

namespace App\Services\Mcp\Connectors;

use App\Services\Mcp\McpToolResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Airtable конектор (Personal Access Token). Read: list_records, find_records.
 * Write: create_record, update_record.
 */
class AirtableConnector extends AbstractConnector
{
    public function listTools(): array
    {
        return [
            ['name' => 'airtable.list_records', 'description' => 'Записи от таблица (с филтър/изглед)', 'writes' => false,
                'parameters' => ['base_id' => ['type' => 'string'], 'table' => ['type' => 'string'], 'max_records' => ['type' => 'integer'], 'view' => ['type' => 'string'], 'filter_by_formula' => ['type' => 'string']]],
            ['name' => 'airtable.find_records', 'description' => 'Търси записи по стойност на поле', 'writes' => false,
                'parameters' => ['base_id' => ['type' => 'string'], 'table' => ['type' => 'string'], 'field' => ['type' => 'string'], 'value' => ['type' => 'string']]],
            ['name' => 'airtable.create_record', 'description' => 'Нов запис', 'writes' => true,
                'parameters' => ['base_id' => ['type' => 'string'], 'table' => ['type' => 'string'], 'fields' => ['type' => 'object']]],
            ['name' => 'airtable.update_record', 'description' => 'Обновява поле/та на запис', 'writes' => true,
                'parameters' => ['base_id' => ['type' => 'string'], 'table' => ['type' => 'string'], 'record_id' => ['type' => 'string'], 'fields' => ['type' => 'object']]],
        ];
    }

    public function testConnection(): bool
    {
        try {
            return $this->client()->get('https://api.airtable.com/v0/meta/whoami')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function callTool(string $tool, array $params): McpToolResult
    {
        try {
            return match ($tool) {
                'airtable.list_records' => $this->listRecords($params),
                'airtable.find_records' => $this->findRecords($params),
                'airtable.create_record' => $this->createRecord($params),
                'airtable.update_record' => $this->updateRecord($params),
                default => McpToolResult::fail("Непознат tool: {$tool}"),
            };
        } catch (\Throwable $e) {
            return McpToolResult::fail("Airtable грешка: {$e->getMessage()}");
        }
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) ($this->credentials['token'] ?? ''))->acceptJson()->timeout(20);
    }

    private function base(array $params): string
    {
        $baseId = (string) ($params['base_id'] ?? '');
        $table = rawurlencode((string) ($params['table'] ?? ''));

        return "https://api.airtable.com/v0/{$baseId}/{$table}";
    }

    private function listRecords(array $params): McpToolResult
    {
        $query = array_filter([
            'maxRecords' => isset($params['max_records']) ? (int) $params['max_records'] : null,
            'view' => $params['view'] ?? null,
            'filterByFormula' => $params['filter_by_formula'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $res = $this->client()->get($this->base($params), $query);
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        $records = (array) $res->json('records', []);
        $lines = array_map(fn ($r) => '- '.($r['id'] ?? '?').': '.json_encode($r['fields'] ?? [], JSON_UNESCAPED_UNICODE), $records);

        return McpToolResult::ok(count($records).' записа:'."\n".implode("\n", $lines), ['records' => $records]);
    }

    private function findRecords(array $params): McpToolResult
    {
        $field = (string) ($params['field'] ?? '');
        $value = (string) ($params['value'] ?? '');
        $formula = '{'.$field.'} = "'.str_replace('"', '\\"', $value).'"';

        return $this->listRecords(['base_id' => $params['base_id'] ?? '', 'table' => $params['table'] ?? '', 'filter_by_formula' => $formula]);
    }

    private function createRecord(array $params): McpToolResult
    {
        $res = $this->client()->post($this->base($params), ['fields' => (array) ($params['fields'] ?? [])]);
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        return McpToolResult::ok('Създаден запис в Airtable (id: '.$res->json('id', '?').')', ['id' => $res->json('id')]);
    }

    private function updateRecord(array $params): McpToolResult
    {
        $id = (string) ($params['record_id'] ?? '');
        if ($id === '' || empty($params['fields'])) {
            return McpToolResult::fail('Трябва record_id и fields');
        }

        $res = $this->client()->patch($this->base($params)."/{$id}", ['fields' => (array) $params['fields']]);
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        return McpToolResult::ok("Обновен запис в Airtable (id: {$id})", ['id' => $id]);
    }
}
