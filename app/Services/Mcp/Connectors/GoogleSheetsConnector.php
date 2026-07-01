<?php

namespace App\Services\Mcp\Connectors;

use App\Services\Mcp\McpToolResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Google Sheets конектор (OAuth Google access_token, Sheets API v4). Read:
 * read_range, get_values. Write: append_row, update_range, create_sheet.
 * Token refresh се прави в McpClientService.
 */
class GoogleSheetsConnector extends AbstractConnector
{
    private const BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

    public function listTools(): array
    {
        $ss = ['label' => 'Spreadsheet ID', 'widget' => 'text'];
        $tab = ['label' => 'Лист', 'widget' => 'select', 'options' => 'sheets_tabs', 'depends_on' => 'spreadsheet_id'];

        return [
            ['name' => 'sheets.read_range', 'description' => 'Чете клетки (A1 нотация, напр. Sheet1!A1:D100)', 'writes' => false,
                'parameters' => ['spreadsheet_id' => $ss, 'range' => ['label' => 'Диапазон (A1)', 'widget' => 'text']]],
            ['name' => 'sheets.get_values', 'description' => 'Всички стойности от лист', 'writes' => false,
                'parameters' => ['spreadsheet_id' => $ss, 'sheet' => $tab]],
            ['name' => 'sheets.append_row', 'description' => 'Добавя ред в края', 'writes' => true,
                'parameters' => ['spreadsheet_id' => $ss, 'range' => $tab, 'values' => ['label' => 'Стойности (CSV ред)', 'widget' => 'textarea']]],
            ['name' => 'sheets.update_range', 'description' => 'Обновява клетки', 'writes' => true,
                'parameters' => ['spreadsheet_id' => $ss, 'range' => ['label' => 'Диапазон (A1)', 'widget' => 'text'], 'values' => ['label' => 'Стойности', 'widget' => 'textarea']]],
            ['name' => 'sheets.create_sheet', 'description' => 'Нов лист в spreadsheet', 'writes' => true,
                'parameters' => ['spreadsheet_id' => $ss, 'title' => ['label' => 'Име на листа', 'widget' => 'text']]],
        ];
    }

    public function listOptions(string $source, array $context = []): array
    {
        // Разглеждане на spreadsheet-ите по име (Drive files.list) — изисква read scope
        // за Drive (drive.metadata.readonly); без него върни [] → UI пада към ръчен ID.
        if ($source === 'sheets_spreadsheets') {
            if (! $this->hasScope(
                'https://www.googleapis.com/auth/drive.metadata.readonly',
                'https://www.googleapis.com/auth/drive.readonly',
                'https://www.googleapis.com/auth/drive',
            )) {
                return [];
            }
            try {
                $res = $this->client()->get('https://www.googleapis.com/drive/v3/files', [
                    'q' => "mimeType = 'application/vnd.google-apps.spreadsheet' and trashed = false",
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

        if ($source !== 'sheets_tabs' || empty($context['spreadsheet_id'])) {
            return [];
        }
        try {
            $res = $this->client()->get(self::BASE.'/'.$context['spreadsheet_id'], ['fields' => 'sheets.properties.title']);

            return collect((array) $res->json('sheets', []))
                ->map(fn ($s) => ['value' => (string) ($s['properties']['title'] ?? ''), 'label' => (string) ($s['properties']['title'] ?? '')])
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
                'sheets.read_range', 'sheets.get_values' => $this->read($params, $tool),
                'sheets.append_row' => $this->append($params),
                'sheets.update_range' => $this->update($params),
                'sheets.create_sheet' => $this->createSheet($params),
                default => McpToolResult::fail("Непознат tool: {$tool}"),
            };
        } catch (\Throwable $e) {
            return McpToolResult::fail("Sheets грешка: {$e->getMessage()}");
        }
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) ($this->credentials['access_token'] ?? ''))->acceptJson()->timeout(25);
    }

    private function read(array $params, string $tool): McpToolResult
    {
        $id = (string) ($params['spreadsheet_id'] ?? '');
        $range = $tool === 'sheets.get_values' ? (string) ($params['sheet'] ?? 'Sheet1') : (string) ($params['range'] ?? '');
        if ($id === '' || $range === '') {
            return McpToolResult::fail('Трябва spreadsheet_id и range/sheet');
        }

        $res = $this->client()->get(self::BASE."/{$id}/values/".rawurlencode($range));
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        $values = (array) $res->json('values', []);
        $text = count($values).' реда:'."\n".implode("\n", array_map(fn ($row) => implode(' | ', (array) $row), $values));

        return McpToolResult::ok($text, ['values' => $values]);
    }

    private function append(array $params): McpToolResult
    {
        $id = (string) ($params['spreadsheet_id'] ?? '');
        $range = (string) ($params['range'] ?? 'Sheet1!A1');
        $values = $this->normalizeRows($params['values'] ?? []);

        $res = $this->client()->post(self::BASE."/{$id}/values/".rawurlencode($range).':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS', [
            'values' => $values,
        ]);
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        return McpToolResult::ok('Добавен ред в Sheets', ['updates' => $res->json('updates')]);
    }

    private function update(array $params): McpToolResult
    {
        $id = (string) ($params['spreadsheet_id'] ?? '');
        $range = (string) ($params['range'] ?? '');
        if ($id === '' || $range === '') {
            return McpToolResult::fail('Трябва spreadsheet_id и range');
        }

        $res = $this->client()->put(self::BASE."/{$id}/values/".rawurlencode($range).'?valueInputOption=USER_ENTERED', [
            'values' => $this->normalizeRows($params['values'] ?? []),
        ]);
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        return McpToolResult::ok("Обновени клетки ({$range})", ['updated' => $res->json('updatedCells')]);
    }

    private function createSheet(array $params): McpToolResult
    {
        $id = (string) ($params['spreadsheet_id'] ?? '');
        $res = $this->client()->post(self::BASE."/{$id}:batchUpdate", [
            'requests' => [['addSheet' => ['properties' => ['title' => (string) ($params['title'] ?? 'Нов лист')]]]],
        ]);
        if ($res->failed()) {
            return McpToolResult::fail("HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }

        return McpToolResult::ok('Създаден лист: '.(string) ($params['title'] ?? ''), []);
    }

    /** Приема ['a','b'] (един ред) или [['a','b'],['c']] (много редове). */
    private function normalizeRows(mixed $values): array
    {
        $values = (array) $values;
        if ($values === []) {
            return [[]];
        }

        return is_array($values[array_key_first($values)] ?? null) ? array_values($values) : [array_values($values)];
    }
}
