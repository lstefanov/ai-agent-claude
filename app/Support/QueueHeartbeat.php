<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Споделен heartbeat за `flows` опашката. Пише се от Horizon `SupervisorLooped`
 * (AppServiceProvider) и се чете преди старт на изпълнение (админ + клиент).
 * Липсващ heartbeat = Horizon supervisor-ът е мъртъв (виж docs/HORIZON-REDIS.md).
 */
class QueueHeartbeat
{
    public const FLOWS_KEY = 'queue.heartbeat.flows';

    public const FLOWS_TTL = 180;

    public static function flowsAlive(): bool
    {
        return Cache::has(self::FLOWS_KEY);
    }

    public static function markFlowsAlive(): void
    {
        Cache::put(self::FLOWS_KEY, now()->timestamp, self::FLOWS_TTL);
    }
}
