<?php

namespace App\Services\Mcp\Connectors;

use App\Services\Mcp\McpToolResult;
use App\Services\Mcp\SsrfGuard;
use Illuminate\Support\Facades\Http;

/**
 * Generic HTTP API конектор (Bearer/API-key/Basic). SSRF guard от
 * config('mcp.http_api'): само разрешени схеми, блокирани hosts + private/
 * loopback IP диапазони (СЛЕД DNS резолюция), опционален domain whitelist,
 * таван на размера и timeout.
 */
class HttpApiConnector extends AbstractConnector
{
    public function listTools(): array
    {
        $url = ['url' => ['label' => 'URL (празно = базовия; път или пълен URL)', 'widget' => 'text']];
        $body = ['body' => ['label' => 'Body (JSON или текст)', 'widget' => 'textarea'], 'headers' => ['label' => 'Headers (JSON)', 'widget' => 'text']];

        return [
            ['name' => 'http_api.get', 'description' => 'GET заявка към външен endpoint', 'writes' => false,
                'parameters' => $url + ['query' => ['label' => 'Query (JSON)', 'widget' => 'text']]],
            ['name' => 'http_api.post', 'description' => 'POST заявка с JSON body', 'writes' => true,
                'parameters' => $url + $body],
            ['name' => 'http_api.put', 'description' => 'PUT заявка с JSON body', 'writes' => true,
                'parameters' => $url + $body],
            ['name' => 'http_api.patch', 'description' => 'PATCH заявка с JSON body', 'writes' => true,
                'parameters' => $url + $body],
        ];
    }

    public function testConnection(): bool
    {
        // Ако има базов URL → реален ping (всеки HTTP отговор = достъпен и
        // SSRF-allowed). Иначе само проверка, че credentials присъстват.
        $base = trim((string) ($this->credentials['base_url'] ?? ''));
        if ($base !== '') {
            try {
                SsrfGuard::assert($base);
                Http::timeout((int) config('mcp.http_api.timeout_seconds', 15))
                    ->withHeaders($this->authHeaders())->get($base);

                return true;
            } catch (\Throwable) {
                return false;
            }
        }

        $type = $this->credentials['auth_type'] ?? 'bearer';

        return match ($type) {
            'bearer' => ! empty($this->credentials['token']),
            'basic' => ! empty($this->credentials['username']),
            'api_key' => ! empty($this->credentials['value']) || ! empty($this->credentials['token']),
            default => false,
        };
    }

    public function callTool(string $tool, array $params): McpToolResult
    {
        $method = match ($tool) {
            'http_api.get' => 'GET',
            'http_api.post' => 'POST',
            'http_api.put' => 'PUT',
            'http_api.patch' => 'PATCH',
            default => null,
        };
        if ($method === null) {
            return McpToolResult::fail("Непознат tool: {$tool}");
        }

        $url = $this->resolveUrl((string) ($params['url'] ?? ''));
        try {
            SsrfGuard::assert($url);
        } catch (\Throwable $e) {
            return McpToolResult::fail($e->getMessage());
        }

        $cfg = config('mcp.http_api');
        try {
            $request = Http::timeout((int) $cfg['timeout_seconds'])
                ->withHeaders($this->authHeaders() + (array) ($params['headers'] ?? []))
                ->acceptJson();

            // tool_params дава body като стринг (от планера/builder-а). JSON стринг
            // → декодира се; обикновен текст → {"text": "..."} (за webhook/прости API).
            $body = $params['body'] ?? [];
            if (is_string($body) && $body !== '') {
                $decoded = json_decode($body, true);
                $body = is_array($decoded) ? $decoded : ['text' => $body];
            }
            $body = (array) $body;

            $response = match ($method) {
                'GET' => $request->get($url, (array) ($params['query'] ?? [])),
                'POST' => $request->post($url, $body),
                'PUT' => $request->put($url, $body),
                'PATCH' => $request->patch($url, $body),
            };
        } catch (\Throwable $e) {
            return McpToolResult::fail("HTTP грешка: {$e->getMessage()}");
        }

        $maxBytes = ((int) $cfg['max_response_size_kb']) * 1024;
        $body = $response->body();
        if (strlen($body) > $maxBytes) {
            $body = substr($body, 0, $maxBytes)."\n\n[Отрязан отговор над {$cfg['max_response_size_kb']} KB]";
        }

        if ($response->failed()) {
            return McpToolResult::fail("HTTP {$response->status()}: ".mb_substr($body, 0, 300));
        }

        $data = json_decode($body, true);
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $text = "HTTP {$response->status()} {$method} {$host}\n".mb_substr($body, 0, 4000);

        return McpToolResult::ok($text, is_array($data) ? $data : []);
    }

    /** Празно → базовия URL; относителен път → база+път; пълен URL → както е. */
    private function resolveUrl(string $url): string
    {
        $url = trim($url);
        $base = trim((string) ($this->credentials['base_url'] ?? ''));

        if ($url === '') {
            return $base;
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if ($base !== '') {
            return rtrim($base, '/').'/'.ltrim($url, '/');
        }

        return $url; // относителен без база → SsrfGuard ще го отхвърли
    }

    private function authHeaders(): array
    {
        $c = $this->credentials;
        $type = $c['auth_type'] ?? 'bearer';

        return match ($type) {
            'bearer' => ['Authorization' => 'Bearer '.($c['token'] ?? '')],
            'basic' => ['Authorization' => 'Basic '.base64_encode(($c['username'] ?? '').':'.($c['password'] ?? ''))],
            'api_key' => ! empty($c['header']) ? [$c['header'] => (string) ($c['value'] ?? $c['token'] ?? '')] : [],
            default => [],
        };
    }
}
