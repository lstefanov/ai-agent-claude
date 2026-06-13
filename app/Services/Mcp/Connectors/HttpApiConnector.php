<?php

namespace App\Services\Mcp\Connectors;

use App\Services\Mcp\McpToolResult;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

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
        $urlParam = ['url' => ['type' => 'string', 'description' => 'Пълен https URL']];

        return [
            ['name' => 'http_api.get', 'description' => 'GET заявка към външен endpoint', 'writes' => false,
                'parameters' => $urlParam + ['query' => ['type' => 'object', 'description' => 'query параметри']]],
            ['name' => 'http_api.post', 'description' => 'POST заявка с JSON body', 'writes' => true,
                'parameters' => $urlParam + ['body' => ['type' => 'object'], 'headers' => ['type' => 'object']]],
            ['name' => 'http_api.put', 'description' => 'PUT заявка с JSON body', 'writes' => true,
                'parameters' => $urlParam + ['body' => ['type' => 'object'], 'headers' => ['type' => 'object']]],
            ['name' => 'http_api.patch', 'description' => 'PATCH заявка с JSON body', 'writes' => true,
                'parameters' => $urlParam + ['body' => ['type' => 'object'], 'headers' => ['type' => 'object']]],
        ];
    }

    public function testConnection(): bool
    {
        // Няма универсален ping endpoint — приемаме, че credentials присъстват.
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

        $url = trim((string) ($params['url'] ?? ''));
        try {
            $this->guardUrl($url);
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

    /** @throws InvalidArgumentException при нарушение на SSRF политиката */
    private function guardUrl(string $url): void
    {
        $cfg = config('mcp.http_api');
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException("Невалиден URL: {$url}");
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, $cfg['allowed_schemes'], true)) {
            throw new InvalidArgumentException("Забранена схема ({$scheme}) — позволени: ".implode(', ', $cfg['allowed_schemes']));
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if (in_array($host, array_map('strtolower', (array) $cfg['blocked_hosts']), true)) {
            throw new InvalidArgumentException("Блокиран host: {$host}");
        }

        if (! empty($cfg['allowed_domains'])) {
            $ok = false;
            foreach ($cfg['allowed_domains'] as $d) {
                $d = strtolower($d);
                if ($host === $d || str_ends_with($host, '.'.$d)) {
                    $ok = true;
                    break;
                }
            }
            if (! $ok) {
                throw new InvalidArgumentException("Домейнът не е в allowed_domains: {$host}");
            }
        }

        foreach ($this->resolveIps($host) as $ip) {
            foreach ((array) $cfg['blocked_cidrs'] as $cidr) {
                if ($this->ipInCidr($ip, $cidr)) {
                    throw new InvalidArgumentException("Блокиран вътрешен адрес ({$ip}) за host {$host}");
                }
            }
        }
    }

    /** @return string[] */
    private function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $rec) {
                if (! empty($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }

        return $ips;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false; // различни IP фамилии
        }

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }
        if ($remainder > 0) {
            $mask = 0xFF << (8 - $remainder) & 0xFF;
            if ((ord($ipBin[$bytes]) & $mask) !== (ord($subnetBin[$bytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
