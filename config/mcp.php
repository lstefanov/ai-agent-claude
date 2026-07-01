<?php

use App\Services\Mcp\Connectors\GmailConnector;
use App\Services\Mcp\Connectors\GoogleCalendarConnector;
use App\Services\Mcp\Connectors\GoogleDocsConnector;
use App\Services\Mcp\Connectors\GoogleDriveConnector;
use App\Services\Mcp\Connectors\GoogleSheetsConnector;

return [

    /*
    |--------------------------------------------------------------------------
    | MCP Конектори
    |--------------------------------------------------------------------------
    | Агентите стават оперативни — четат/пишат в реални системи. Auth живее на
    | ниво Company (company_connectors); агентите виждат само connector_id +
    | tool + params. Единственото място, което резолвира token → HTTP call, е
    | McpClientService. Каталогът е фокусиран върху Google екосистемата — там
    | живеят файловете/информацията, които flow-овете дърпат.
    */

    // connector_type → клас. Добавяне на нов конектор = ред тук + клас, без
    // редакция на McpClientService::resolve().
    'registry' => [
        'gmail' => GmailConnector::class,
        'google_sheets' => GoogleSheetsConnector::class,
        'google_drive' => GoogleDriveConnector::class,
        'google_docs' => GoogleDocsConnector::class,
        'google_calendar' => GoogleCalendarConnector::class,
    ],

    // tool namespace (префиксът на tool името) → connector_type. Sheets/Drive/
    // Docs/Calendar ползват различен namespace от типа на конектора (sheets.* →
    // google_sheets и т.н.), затова всяко място, което резолвира конектор по
    // tool име, минава ОТ ТУК — не от суровия explode('.', $tool)[0].
    'tool_namespaces' => [
        'gmail' => 'gmail',
        'sheets' => 'google_sheets',
        'drive' => 'google_drive',
        'docs' => 'google_docs',
        'calendar' => 'google_calendar',
    ],

    // Tools, които ПИШАТ/изпращат → изискват human_approval gate. Ползва се
    // като default за requires_approval в builder-а и от планерния gate.
    // read/list/get tools НЕ са тук (read-only, без approval).
    'write_tools' => [
        'gmail.send_email', 'gmail.reply',
        'sheets.append_row', 'sheets.update_range', 'sheets.create_sheet',
        'drive.upload_file', 'drive.create_doc',
        'docs.create_document', 'docs.append_text',
        'calendar.create_event',
    ],

    // Каталог от налични за свързване услуги (картите в „Добави услуга"). Единен
    // източник за админ страницата И клиентския портал. Всички са Google OAuth
    // → един клик през общия FlowAI Google app.
    'catalog' => [
        ['type' => 'gmail', 'label' => 'Gmail', 'auth' => 'oauth2', 'provider' => 'google', 'service' => 'gmail', 'icon' => '📧', 'hint' => 'Google · 1 клик'],
        ['type' => 'google_sheets', 'label' => 'Google Sheets', 'auth' => 'oauth2', 'provider' => 'google', 'service' => 'google_sheets', 'icon' => '📊', 'hint' => 'Google · 1 клик'],
        ['type' => 'google_drive', 'label' => 'Google Drive', 'auth' => 'oauth2', 'provider' => 'google', 'service' => 'google_drive', 'icon' => '📁', 'hint' => 'Google · 1 клик'],
        ['type' => 'google_docs', 'label' => 'Google Docs', 'auth' => 'oauth2', 'provider' => 'google', 'service' => 'google_docs', 'icon' => '📄', 'hint' => 'Google · 1 клик'],
        ['type' => 'google_calendar', 'label' => 'Google Calendar', 'auth' => 'oauth2', 'provider' => 'google', 'service' => 'google_calendar', 'icon' => '📅', 'hint' => 'Google · 1 клик'],
    ],

    // SSRF политика за изходящи заявки на конекторите (напр. сваляне на Gmail
    // attachment зад SsrfGuard). Само разрешени схеми + блокирани private/
    // loopback/link-local диапазони СЛЕД DNS резолюция.
    'ssrf' => [
        'allowed_schemes' => ['https'],
        'blocked_hosts' => ['localhost', '127.0.0.1', '0.0.0.0', '::1'],
        'blocked_cidrs' => [
            '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
            '127.0.0.0/8', '169.254.0.0/16', '::1/128', 'fc00::/7', 'fe80::/10',
        ],
        // [] = всички external домейни; иначе whitelist по host суфикс.
        'allowed_domains' => [],
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
];
