<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Един ревю-job на компания наведнъж (ръчно, cron или паралелни табове).
 * TTL = OrgReviewJob timeout; job-ът освобождава ключа в finally.
 */
class OrgReviewLock
{
    public const TTL_SECONDS = 1200;

    public static function key(int $companyId): string
    {
        return 'org-review:'.$companyId;
    }

    public static function running(int $companyId): bool
    {
        return Cache::has(self::key($companyId));
    }

    /** Атомарно — false ако вече тече или е пуснато наскоро. */
    public static function acquire(int $companyId): bool
    {
        return Cache::add(self::key($companyId), now()->timestamp, self::TTL_SECONDS);
    }

    public static function release(int $companyId): void
    {
        Cache::forget(self::key($companyId));
    }
}
