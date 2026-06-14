<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyConnector;
use App\Services\McpClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * „Свързани системи" — MCP конектори на ниво фирма (страница + JSON endpoints).
 * OAuth типове (Gmail) се създават през OAuthController; API-key типове —
 * директно през store().
 */
class CompanyConnectorController extends Controller
{
    public function index(Company $company): View
    {
        return view('companies.connectors', [
            'company' => $company,
            'config' => [
                'base' => route('companies.connectors.index', $company),
                'companyName' => $company->name,
                'backUrl' => route('companies.show', $company),
                'csrf' => csrf_token(),
                'googleRedirect' => route('oauth.google.redirect', $company),
                'slackRedirect' => route('oauth.slack.redirect', $company),
            ],
        ]);
    }

    public function data(Company $company): JsonResponse
    {
        $connectors = $company->connectors()
            ->orderBy('connector_type')
            ->get()
            ->map(fn (CompanyConnector $c) => $this->present($c));

        return response()->json([
            'connectors' => $connectors,
            'types' => $this->availableTypes(),
        ]);
    }

    /** Активни конектори + техните tools — за MCP node панела в builder-а. */
    public function available(Company $company, McpClientService $mcp): JsonResponse
    {
        $items = $company->connectors()->active()->get()->map(fn (CompanyConnector $c) => [
            'id' => $c->id,
            'type' => $c->connector_type,
            'name' => $c->display_name ?: $c->connector_type,
            'tools' => collect($mcp->listTools($c))->map(fn ($t) => [
                'name' => $t['name'],
                'description' => $t['description'] ?? '',
                'writes' => (bool) ($t['writes'] ?? false),
                'parameters' => collect((array) ($t['parameters'] ?? []))->map(fn ($meta, $key) => [
                    'key' => $key,
                    'label' => is_array($meta) ? ($meta['label'] ?? $key) : $key,
                    'widget' => is_array($meta) ? ($meta['widget'] ?? 'text') : 'text',
                    'options' => is_array($meta) ? ($meta['options'] ?? null) : null,
                    'depends_on' => is_array($meta) ? ($meta['depends_on'] ?? null) : null,
                ])->values(),
            ])->values(),
        ]);

        return response()->json(['connectors' => $items]);
    }

    /** Live опции за select-параметър (Drive папки, Slack канали…). */
    public function options(Request $request, Company $company, CompanyConnector $connector, McpClientService $mcp): JsonResponse
    {
        abort_unless($connector->company_id === $company->id, 404);

        $source = (string) $request->query('source', '');
        $context = (array) $request->query('context', []);

        return response()->json(['options' => $mcp->listOptions($connector, $source, $context)]);
    }

    /** Създава API-key/bearer/basic конектор (OAuth минава през OAuthController). */
    public function store(Request $request, Company $company, McpClientService $mcp): JsonResponse
    {
        $data = $request->validate([
            'connector_type' => 'required|string|max:50',
            'display_name' => 'nullable|string|max:255',
            'auth_type' => 'required|in:api_key,bearer,basic',
            'credentials' => 'required|array',
            'settings' => 'nullable|array',
        ]);

        $connector = $company->connectors()->create([
            'connector_type' => $data['connector_type'],
            'display_name' => $data['display_name'] ?? null,
            'auth_type' => $data['auth_type'],
            'credentials' => $data['credentials'],
            'settings' => $data['settings'] ?? null,
            'status' => 'active',
        ]);

        $mcp->testConnection($connector);

        return response()->json(['connector' => $this->present($connector->fresh())]);
    }

    public function update(Request $request, Company $company, CompanyConnector $connector): JsonResponse
    {
        abort_unless($connector->company_id === $company->id, 404);

        $data = $request->validate([
            'display_name' => 'nullable|string|max:255',
            'credentials' => 'nullable|array',
            'settings' => 'nullable|array',
        ]);

        $update = [];
        if ($request->has('display_name')) {
            $update['display_name'] = $data['display_name'] ?? null;
        }
        if ($request->has('settings')) {
            $update['settings'] = $data['settings'] ?? null;
        }
        if (! empty($data['credentials'])) {
            $update['credentials'] = array_merge($connector->credentials ?? [], $data['credentials']);
        }
        $connector->update($update);

        return response()->json(['connector' => $this->present($connector->fresh())]);
    }

    public function destroy(Company $company, CompanyConnector $connector): JsonResponse
    {
        abort_unless($connector->company_id === $company->id, 404);
        $connector->delete();

        return response()->json(['ok' => true]);
    }

    public function test(Company $company, CompanyConnector $connector, McpClientService $mcp): JsonResponse
    {
        abort_unless($connector->company_id === $company->id, 404);
        $ok = $mcp->testConnection($connector);

        return response()->json(['ok' => $ok, 'connector' => $this->present($connector->fresh())]);
    }

    public function logs(Company $company, CompanyConnector $connector): JsonResponse
    {
        abort_unless($connector->company_id === $company->id, 404);

        $logs = $connector->toolLogs()->latest('id')->limit(50)->get()->map(fn ($l) => [
            'id' => $l->id,
            'tool' => $l->tool,
            'status' => $l->status,
            'result_summary' => $l->result_summary,
            'error' => $l->error,
            'duration_ms' => $l->duration_ms,
            'created_at' => optional($l->created_at)->format('d.m H:i'),
        ]);

        return response()->json(['logs' => $logs]);
    }

    private function present(CompanyConnector $c): array
    {
        return [
            'id' => $c->id,
            'connector_type' => $c->connector_type,
            'display_name' => $c->display_name,
            'auth_type' => $c->auth_type,
            'status' => $c->status,
            'scopes' => $c->scopes ?? [],
            'last_error' => $c->last_error,
            'last_tested_at' => optional($c->last_tested_at)->format('d.m.Y H:i'),
        ];
    }

    /** Каталог от налични за свързване услуги (катало с карти). */
    private function availableTypes(): array
    {
        return [
            ['type' => 'gmail', 'label' => 'Gmail', 'auth' => 'oauth2', 'provider' => 'google', 'service' => 'gmail', 'icon' => '📧', 'hint' => 'Google · 1 клик'],
            ['type' => 'google_sheets', 'label' => 'Google Sheets', 'auth' => 'oauth2', 'provider' => 'google', 'service' => 'google_sheets', 'icon' => '📊', 'hint' => 'Google · 1 клик'],
            ['type' => 'google_drive', 'label' => 'Google Drive', 'auth' => 'oauth2', 'provider' => 'google', 'service' => 'google_drive', 'icon' => '📁', 'hint' => 'Google · 1 клик'],
            ['type' => 'slack', 'label' => 'Slack', 'auth' => 'oauth2', 'provider' => 'slack', 'icon' => '💬', 'hint' => 'OAuth · 1 клик'],
            ['type' => 'notion', 'label' => 'Notion', 'auth' => 'api_key', 'icon' => '📝', 'hint' => 'API ключ',
                'fields' => [['key' => 'token', 'label' => 'Integration Token', 'type' => 'password']]],
            ['type' => 'airtable', 'label' => 'Airtable', 'auth' => 'api_key', 'icon' => '🗂', 'hint' => 'API ключ',
                'fields' => [['key' => 'token', 'label' => 'Personal Access Token', 'type' => 'password']]],
            ['type' => 'http_api', 'label' => 'HTTP API', 'auth' => 'bearer', 'icon' => '🔗', 'hint' => 'Bearer / ключ',
                'fields' => [['key' => 'token', 'label' => 'Bearer токен', 'type' => 'password']]],
        ];
    }
}
