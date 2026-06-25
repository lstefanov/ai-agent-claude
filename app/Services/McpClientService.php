<?php

namespace App\Services;

use App\Models\CompanyConnector;
use App\Models\ConnectorToolLog;
use App\Services\Mcp\McpConnectorInterface;
use App\Services\Mcp\McpToolResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Централен MCP router — ЕДИНСТВЕНОТО място, което резолвира token → HTTP call.
 * Агентите минават само през callTool(); никога не виждат raw credentials.
 */
class McpClientService
{
    /**
     * Взима конфигуриран connector instance + инжектира credentials.
     * connector_type → клас идва от config('mcp.registry').
     */
    public function resolve(CompanyConnector $connector): McpConnectorInterface
    {
        $class = config("mcp.registry.{$connector->connector_type}");
        if (! is_string($class) || ! class_exists($class)) {
            throw new \InvalidArgumentException("Непознат конектор: {$connector->connector_type}");
        }

        $impl = app($class);
        if (! $impl instanceof McpConnectorInterface) {
            throw new \InvalidArgumentException("{$class} не имплементира McpConnectorInterface");
        }

        // Инжектираме credentials + мета (auth_type, scopes) — единствената точка.
        $creds = $connector->credentials ?? [];
        $creds['auth_type'] = $connector->auth_type;
        $creds['scopes'] = $connector->scopes ?? [];

        return $impl->withCredentials($creds);
    }

    /**
     * Изпълнява tool call + логва в connector_tool_logs. OAuth refresh става
     * автоматично преди извикването, ако access token е изтекъл.
     */
    public function callTool(
        CompanyConnector $connector,
        string $tool,
        array $params,
        ?int $flowRunId = null,
        ?int $nodeRunId = null,
    ): McpToolResult {
        $started = microtime(true);

        if ($connector->needsRefresh()) {
            try {
                $this->refreshOAuthToken($connector);
            } catch (\Throwable $e) {
                Log::warning("[MCP] OAuth refresh fail (connector {$connector->id}): {$e->getMessage()}");
            }
        }

        try {
            $result = $this->resolve($connector)->callTool($tool, $params);
            $status = $result->success ? 'ok' : 'error';
        } catch (\Throwable $e) {
            $result = McpToolResult::fail($e->getMessage());
            $status = 'error';
        }

        ConnectorToolLog::create([
            'company_id' => $connector->company_id,
            'connector_id' => $connector->id,
            'flow_run_id' => $flowRunId,
            'node_run_id' => $nodeRunId,
            'tool' => $tool,
            'params' => $this->sanitizeParams($params),
            'status' => $status,
            'result_summary' => mb_substr($result->text, 0, 500),
            'error' => $result->error,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);

        return $result;
    }

    /** Налични tools за конектор (за планер/builder/UI). */
    public function listTools(CompanyConnector $connector): array
    {
        try {
            return $this->resolve($connector)->listTools();
        } catch (\Throwable $e) {
            Log::warning("[MCP] listTools fail ({$connector->connector_type}): {$e->getMessage()}");

            return [];
        }
    }

    /** Live опции за select-параметър (Drive папки, Slack канали…) — за builder-а. */
    public function listOptions(CompanyConnector $connector, string $source, array $context = []): array
    {
        if ($connector->needsRefresh()) {
            try {
                $this->refreshOAuthToken($connector);
            } catch (\Throwable) {
            }
        }

        try {
            return $this->resolve($connector)->listOptions($source, $context);
        } catch (\Throwable $e) {
            Log::warning("[MCP] listOptions fail ({$connector->connector_type}/{$source}): {$e->getMessage()}");

            return [];
        }
    }

    /** Тества връзката + ъпдейтва статуса на конектора. */
    public function testConnection(CompanyConnector $connector): bool
    {
        if ($connector->needsRefresh()) {
            try {
                $this->refreshOAuthToken($connector);
            } catch (\Throwable) {
            }
        }

        try {
            $ok = $this->resolve($connector)->testConnection();
        } catch (\Throwable $e) {
            $connector->update(['status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 500), 'last_tested_at' => now()]);

            return false;
        }

        $connector->update([
            'status' => $ok ? 'active' : 'error',
            'last_error' => $ok ? null : 'Connection test fail',
            'last_tested_at' => now(),
        ]);

        return $ok;
    }

    /** Обновява изтекъл Google OAuth access token през refresh_token. */
    private function refreshOAuthToken(CompanyConnector $connector): void
    {
        $creds = $connector->credentials ?? [];
        if (empty($creds['refresh_token'])) {
            return; // API-key конектор или без offline access
        }

        $res = Http::asForm()->post(config('mcp.google.token_url'), [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $creds['refresh_token'],
            'grant_type' => 'refresh_token',
        ]);

        if ($res->failed()) {
            $connector->update([
                'status' => 'expired',
                'last_error' => 'OAuth refresh failed: '.mb_substr($res->body(), 0, 200),
            ]);

            return;
        }

        $creds['access_token'] = (string) $res->json('access_token');
        $creds['expires_at'] = now()->timestamp + (int) $res->json('expires_in', 3600);
        $connector->update(['credentials' => $creds, 'status' => 'active', 'last_error' => null]);
    }

    /** Маха sensitive ключове преди логване (никога tokens в логовете). */
    private function sanitizeParams(array $params): array
    {
        $sensitive = ['token', 'secret', 'password', 'key', 'authorization', 'access_token', 'refresh_token', 'api_key'];

        $out = [];
        foreach ($params as $k => $v) {
            if (in_array(strtolower((string) $k), $sensitive, true)) {
                continue;
            }
            $out[$k] = is_array($v) ? $this->sanitizeParams($v) : $v;
        }

        return $out;
    }
}
