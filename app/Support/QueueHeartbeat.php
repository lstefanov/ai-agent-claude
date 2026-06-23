<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Споделен heartbeat за `flows` и `org` опашките. Пише се от Horizon `SupervisorLooped`
 * (AppServiceProvider) и се чете преди старт на изпълнение/org работа (админ + клиент).
 * Липсващ heartbeat = съответният Horizon supervisor е мъртъв (виж docs/HORIZON-REDIS.md).
 */
class QueueHeartbeat
{
    public const FLOWS_KEY = 'queue.heartbeat.flows';

    public const FLOWS_TTL = 180;

    public const ORG_KEY = 'queue.heartbeat.org';

    public const ORG_TTL = 180;

    public static function flowsAlive(): bool
    {
        return Cache::has(self::FLOWS_KEY);
    }

    public static function markFlowsAlive(): void
    {
        Cache::put(self::FLOWS_KEY, now()->timestamp, self::FLOWS_TTL);
    }

    public static function orgAlive(): bool
    {
        return Cache::has(self::ORG_KEY);
    }

    public static function markOrgAlive(): void
    {
        Cache::put(self::ORG_KEY, now()->timestamp, self::ORG_TTL);
    }
}
