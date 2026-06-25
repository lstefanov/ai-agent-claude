<?php

use App\Services\Mcp\Connectors\AirtableConnector;
use App\Services\Mcp\Connectors\GmailConnector;
use App\Services\Mcp\Connectors\GoogleDriveConnector;
use App\Services\Mcp\Connectors\GoogleSheetsConnector;
use App\Services\Mcp\Connectors\HttpApiConnector;
use App\Services\Mcp\Connectors\NotionConnector;
use App\Services\Mcp\Connectors\SlackConnector;

return [

    /*
    |--------------------------------------------------------------------------
    | MCP Конектори
    |--------------------------------------------------------------------------
    | Агентите стават оперативни — четат/пишат в реални системи. Auth живее на
    | ниво Company (company_connectors); агентите виждат само connector_id +
    | tool + params. Единственото място, което резолвира token → HTTP call, е
    | McpClientService.
    */

    // connector_type → клас. Добавяне на нов конектор = ред тук + клас, без
    // редакция на McpClientService::resolve().
    'registry' => [
        'http_api' => HttpApiConnector::class,
        'notion' => NotionConnector::class,
        'airtable' => AirtableConnector::class,
        'gmail' => GmailConnector::class,
        'google_sheets' => GoogleSheetsConnector::class,
        'google_drive' => GoogleDriveConnector::class,
        'slack' => SlackConnector::class,
    ],

    // tool namespace (префиксът на tool името) → connector_type. Sheets/Drive
    // ползват различен namespace от типа на конектора (sheets.* → google_sheets,
    // drive.* → google_drive), затова всяко място, което резолвира конектор по
    // tool име, минава ОТ ТУК — не от суровия explode('.', $tool)[0].
    'tool_namespaces' => [
        'gmail' => 'gmail',
        'sheets' => 'google_sheets',
        'drive' => 'google_drive',
        'slack' => 'slack',
        'notion' => 'notion',
        'airtable' => 'airtable',
        'http_api' => 'http_api',
    ],

    // Tools, които ПИШАТ/изпращат → изискват human_approval gate. Ползва се
    // като default за requires_approval в builder-а и от планерния gate.
    // read/list/get tools НЕ са тук (read-only, без approval).
    'write_tools' => [
        'gmail.send_email', 'gmail.reply',
        'notion.create_page', 'notion.update_page',
        'airtable.create_record', 'airtable.update_record',
        'sheets.append_row', 'sheets.update_range', 'sheets.create_sheet',
        'drive.upload_file', 'drive.create_doc',
        'slack.post_message', 'slack.create_thread', 'slack.upload_file',
        'http_api.post', 'http_api.put', 'http_api.patch',
    ],

    // Generic HTTP API конектор — SSRF guard (виж HttpApiConnector::guardUrl).
    'http_api' => [
        'allowed_schemes' => ['https'],
        'blocked_hosts' => ['localhost', '127.0.0.1', '0.0.0.0', '::1'],
        // Private/loopback/link-local диапазони — блокирани СЛЕД DNS резолюция.
        'blocked_cidrs' => [
            '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
            '127.0.0.0/8', '169.254.0.0/16', '::1/128', 'fc00::/7', 'fe80::/10',
        ],
        // [] = всички external домейни; иначе whitelist по host суфикс.
        'allowed_domains' => [],
        'max_response_size_kb' => (int) env('MCP_HTTP_API_MAX_KB', 512),
        'timeout_seconds' => (int) env('MCP_HTTP_API_TIMEOUT', 15),
    ],

    // Google OAuth token endpoint — общ за всички google_* конектори (refresh
    // в McpClientService). OAuth client е в config/services.php 'google'.
    'google' => [
        'token_url' => 'https://oauth2.googleapis.com/token',
    ],

    // Gmail REST.
    'gmail' => [
        'api_base' => 'https://gmail.googleapis.com/gmail/v1/users/me',
        'token_url' => 'https://oauth2.googleapis.com/token',
    ],

    // Notion (internal integration token или OAuth).
    'notion' => [
        'api_base' => 'https://api.notion.com/v1',
        'version' => env('NOTION_API_VERSION', '2022-06-28'),
    ],
];
