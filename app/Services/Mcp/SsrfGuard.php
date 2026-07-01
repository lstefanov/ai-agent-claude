<?php

namespace App\Services\Mcp;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * SSRF политика за изходящи заявки от конекторите (напр. сваляне на attachment-и).
 * Само разрешени схеми, блокирани hosts + private/loopback IP диапазони (СЛЕД
 * DNS резолюция), опционален domain whitelist. Конфиг: config('mcp.ssrf').
 */
class SsrfGuard
{
    /** @throws InvalidArgumentException при нарушение на политиката */
    public static function assert(string $url, ?array $cfg = null): void
    {
        $cfg ??= config('mcp.ssrf');
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException("Невалиден URL: {$url}");
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, (array) $cfg['allowed_schemes'], true)) {
            throw new InvalidArgumentException("Забранена схема ({$scheme}) — позволени: ".implode(', ', (array) $cfg['allowed_schemes']));
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

        foreach (self::resolveIps($host) as $ip) {
            foreach ((array) $cfg['blocked_cidrs'] as $cidr) {
                if (self::ipInCidr($ip, $cidr)) {
                    throw new InvalidArgumentException("Блокиран вътрешен адрес ({$ip}) за host {$host}");
                }
            }
        }
    }

    /**
     * Сваля URL зад guard-а. Връща [contentType, binary] или null при провал /
     * надхвърлен размер.
     *
     * @return array{0:string,1:string}|null
     */
    public static function download(string $url, int $maxBytes = 10485760, int $timeout = 25): ?array
    {
        self::assert($url);

        $res = Http::timeout($timeout)->get($url);
        if ($res->failed()) {
            return null;
        }
        $body = $res->body();
        if (strlen($body) > $maxBytes) {
            return null;
        }

        return [$res->header('Content-Type') ?: 'application/octet-stream', $body];
    }

    /** @return string[] */
    private static function resolveIps(string $host): array
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

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
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
