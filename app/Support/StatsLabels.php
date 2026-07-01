<?php

namespace App\Support;

/**
 * Тънък резолвер над config/stats.php — превежда context_type/purpose/ledger тип/origin
 * към човешки български етикети + цвят/иконка. Ползва се от CompanyStatsController/Service.
 */
class StatsLabels
{
    /** Услуга по context_type (fallback purpose, после общ fallback). */
    public static function service(?string $contextType, ?string $purpose = null): array
    {
        if ($contextType && ($s = config("stats.services.{$contextType}"))) {
            return $s + ['key' => $contextType];
        }
        if ($purpose && ($p = config("stats.purposes.{$purpose}"))) {
            return $p + ['icon' => $p['icon'] ?? 'ellipsis-horizontal', 'key' => $purpose];
        }

        return config('stats.fallback') + ['key' => $contextType ?? $purpose ?? 'other'];
    }

    /** Етикет (само текст) за ред с context_type/purpose/kind. */
    public static function label(?string $contextType, ?string $purpose = null, ?string $kind = null): string
    {
        $svc = self::service($contextType, $purpose);
        if (($svc['key'] ?? null) !== 'other') {
            return $svc['label'];
        }

        return $kind ? ucfirst($kind) : $svc['label'];
    }

    public static function externalName(string $provider): string
    {
        return config("stats.external.{$provider}", $provider);
    }

    public static function ledgerType(string $type): string
    {
        return config("stats.ledger_types.{$type}", $type);
    }

    public static function origin(?string $origin): string
    {
        return config('stats.origins.'.($origin ?? 'manual'), $origin ?? '—');
    }

    /** Целите карти за @js() към фронтенда. */
    public static function forJs(): array
    {
        return [
            'services' => config('stats.services'),
            'purposes' => config('stats.purposes'),
            'external' => config('stats.external'),
            'ledgerTypes' => config('stats.ledger_types'),
            'origins' => config('stats.origins'),
            'fallback' => config('stats.fallback'),
        ];
    }
}
