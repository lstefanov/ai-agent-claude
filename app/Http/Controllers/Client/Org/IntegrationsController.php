<?php

namespace App\Http\Controllers\Client\Org;

use App\Http\Controllers\Controller;
use App\Models\AssistantTask;
use App\Models\Company;
use App\Models\CompanyConnector;
use App\Services\McpClientService;
use App\Services\Org\OrgActPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * Клиентски „Интеграции" — фирмата свързва и управлява своите MCP конектори
 * (Google OAuth). Огледало на админ CompanyConnectorController, но фирмата идва
 * ВИНАГИ от сесията и всеки {connector} се скоупва към нея. OAuth минава през
 * общия stateless OAuthController (origin=client).
 */
class IntegrationsController extends Controller
{
    public function index(): View
    {
        $company = $this->company();

        // OAuth callback (админ домейн) връща тук с ?connected/?error — cross-domain
        // flash не оцелява, затова превръщаме query параметъра в локален flash.
        if ($type = request('connected')) {
            $label = collect(config('mcp.catalog'))->firstWhere('type', $type)['label'] ?? $type;
            session()->flash('success', "{$label} е свързан.");
        } elseif ($error = request('error')) {
            session()->flash('success', 'Google връзката не успя: '.$error);
        }

        $actTasks = AssistantTask::whereIn('org_member_id', $company->members()->pluck('id'))
            ->whereIn('act_mode', ['act', 'mixed'])
            ->with('orgMember.persona')
            ->get();

        return view('client.org.integrations', [
            'company' => $company,
            'actTasks' => $actTasks,
            'actEnabled' => OrgActPolicy::enabledFor($company),
            'config' => [
                'base' => route('client.org.integrations'),
                'csrf' => csrf_token(),
                'googleRedirect' => route('client.org.oauth.google.redirect'),
            ],
        ]);
    }

    public function data(): JsonResponse
    {
        $connectors = $this->company()->connectors()
            ->orderBy('connector_type')
            ->get()
            ->map(fn (CompanyConnector $c) => $this->present($c));

        return response()->json([
            'connectors' => $connectors,
            'types' => config('mcp.catalog'),
        ]);
    }

    public function destroy(CompanyConnector $connector): JsonResponse
    {
        $this->authorizeConnector($connector);
        $connector->delete();

        return response()->json(['ok' => true]);
    }

    public function test(CompanyConnector $connector, McpClientService $mcp): JsonResponse
    {
        $this->authorizeConnector($connector);
        $ok = $mcp->testConnection($connector);

        return response()->json(['ok' => $ok, 'connector' => $this->present($connector->fresh())]);
    }

    public function logs(CompanyConnector $connector): JsonResponse
    {
        $this->authorizeConnector($connector);

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

    private function company(): Company
    {
        return Company::findOrFail((int) session('client_company_id'));
    }

    private function authorizeConnector(CompanyConnector $connector): void
    {
        abort_unless($connector->company_id === $this->company()->id, 404);
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
