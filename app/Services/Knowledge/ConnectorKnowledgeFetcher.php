<?php

namespace App\Services\Knowledge;

use App\Models\CompanyConnector;
use App\Services\McpClientService;

/**
 * Нормализира съдържанието на файл от свързана интеграция до едно от двете:
 * text (native документи/листове/имейли/събития → бележка) или file (бинарен
 * файл → сурови байтове за DocumentTextExtractor). Само READ tools — не иска
 * одобрение. Целият MCP достъп минава през McpClientService (одит + refresh).
 */
class ConnectorKnowledgeFetcher
{
    public function __construct(private McpClientService $mcp) {}

    /**
     * @param  array<string, mixed>  $ref
     * @return array{kind: string, title: string, content?: string, name?: string, mime?: string, bytes?: string}
     */
    public function fetch(CompanyConnector $connector, array $ref): array
    {
        return match ($connector->connector_type) {
            'google_drive' => $this->drive($connector, $ref),
            'google_sheets' => $this->sheets($connector, $ref),
            'google_docs' => $this->docs($connector, $ref),
            'gmail' => $this->gmail($connector, $ref),
            'google_calendar' => $this->calendar($connector, $ref),
            default => throw new \RuntimeException('Този конектор не се поддържа като източник на знание.'),
        };
    }

    /** @param array<string, mixed> $ref */
    private function drive(CompanyConnector $connector, array $ref): array
    {
        $fileId = (string) ($ref['file_id'] ?? '');
        if ($fileId === '') {
            throw new \RuntimeException('Не е избран файл.');
        }

        $raw = $this->mcp->downloadConnectorFile($connector, $fileId);

        if (($raw['exported'] ?? false) === true) {
            return ['kind' => 'text', 'title' => (string) ($raw['name'] ?? 'Документ'), 'content' => (string) ($raw['bytes'] ?? '')];
        }

        return [
            'kind' => 'file',
            'title' => (string) ($raw['name'] ?? 'Файл'),
            'name' => (string) ($raw['name'] ?? 'file'),
            'mime' => (string) ($raw['mime'] ?? 'application/octet-stream'),
            'bytes' => (string) ($raw['bytes'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $ref */
    private function sheets(CompanyConnector $connector, array $ref): array
    {
        $id = (string) ($ref['spreadsheet_id'] ?? '');
        $sheet = (string) ($ref['sheet'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('Липсва spreadsheet_id.');
        }

        // Конкретен лист.
        if ($sheet !== '') {
            return ['kind' => 'text', 'title' => 'Google Sheets: '.$sheet, 'content' => $this->readTab($connector, $id, $sheet)];
        }

        // Без избран лист → всички листове (както xlsx extractor-ът: „## Лист: <име>").
        $tabs = collect($this->mcp->listOptions($connector, 'sheets_tabs', ['spreadsheet_id' => $id]))
            ->pluck('value')->filter()->values()->all();

        if ($tabs === []) {
            return ['kind' => 'text', 'title' => 'Google Sheets', 'content' => $this->readTab($connector, $id, 'Sheet1')];
        }

        $blocks = [];
        foreach ($tabs as $tab) {
            $rows = $this->readTab($connector, $id, (string) $tab);
            if (trim($rows) !== '') {
                $blocks[] = '## Лист: '.$tab."\n".$rows;
            }
        }

        return ['kind' => 'text', 'title' => 'Google Sheets ('.count($tabs).' листа)', 'content' => implode("\n\n", $blocks)];
    }

    /** Чете един лист (get_values) → markdown таблица. */
    private function readTab(CompanyConnector $connector, string $id, string $sheet): string
    {
        $result = $this->mcp->callTool($connector, 'sheets.get_values', ['spreadsheet_id' => $id, 'sheet' => $sheet]);
        if (! $result->success) {
            throw new \RuntimeException($result->error ?: 'Грешка при четене на Google Sheets.');
        }

        return $this->rowsToMarkdown((array) ($result->data['values'] ?? []));
    }

    /** @param array<string, mixed> $ref */
    private function docs(CompanyConnector $connector, array $ref): array
    {
        $id = (string) ($ref['document_id'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('Не е избран документ.');
        }

        $result = $this->mcp->callTool($connector, 'docs.get_document', ['document_id' => $id]);
        if (! $result->success) {
            throw new \RuntimeException($result->error ?: 'Грешка при четене на Google Docs.');
        }

        return ['kind' => 'text', 'title' => (string) ($result->data['title'] ?? 'Google Doc'), 'content' => $result->text];
    }

    /** @param array<string, mixed> $ref */
    private function gmail(CompanyConnector $connector, array $ref): array
    {
        $id = (string) ($ref['message_id'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('Липсва message_id.');
        }

        $result = $this->mcp->callTool($connector, 'gmail.get_email', ['message_id' => $id]);
        if (! $result->success) {
            throw new \RuntimeException($result->error ?: 'Грешка при четене на имейл.');
        }

        return ['kind' => 'text', 'title' => 'Имейл (Gmail)', 'content' => $result->text];
    }

    /** @param array<string, mixed> $ref */
    private function calendar(CompanyConnector $connector, array $ref): array
    {
        $params = array_filter([
            'calendar_id' => (string) ($ref['calendar_id'] ?? 'primary'),
            'time_min' => (string) ($ref['time_min'] ?? ''),
            'time_max' => (string) ($ref['time_max'] ?? ''),
            'max' => (string) ($ref['max'] ?? ''),
        ], fn ($v) => $v !== '');

        $result = $this->mcp->callTool($connector, 'calendar.list_events', $params);
        if (! $result->success) {
            throw new \RuntimeException($result->error ?: 'Грешка при четене на календара.');
        }

        return ['kind' => 'text', 'title' => 'Календар (Google Calendar)', 'content' => $result->text];
    }

    /**
     * Редове → markdown таблица, с таван на редовете (иначе огромен лист става
     * гигантска бележка).
     *
     * @param  array<int, mixed>  $rows
     */
    private function rowsToMarkdown(array $rows): string
    {
        $max = (int) config('services.knowledge.xlsx_max_rows', 2000);
        $lines = [];
        $count = 0;

        foreach ($rows as $row) {
            if (++$count > $max) {
                $lines[] = '… (съкратено до '.$max.' реда)';
                break;
            }

            $cells = array_map(fn ($c) => trim((string) $c), (array) $row);
            if (trim(implode('', $cells)) === '') {
                continue;
            }

            $lines[] = '| '.implode(' | ', $cells).' |';
        }

        return implode("\n", $lines);
    }
}
