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
                'csrf' => csrf_token(),
                // Релативен → connect линкът остава на текущия хост (важно, ако
                // ползваш flowai.local.com заради Google OAuth) вместо APP_URL.
                'googleRedirect' => route('oauth.google.redirect', $company, absolute: false),
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
            'types' => config('mcp.catalog'),
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
}
