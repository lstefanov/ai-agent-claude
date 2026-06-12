<?php

namespace App\Support;

/**
 * Ambient deadline за текущия node job (огледало на статичния модел на
 * LlmContext/LlmUsage). NodeExecutorService го set()-ва при влизане в job-а
 * (timeout − headroom) и го clear()-ва накрая; дълго въртящи се цикли дълбоко
 * в инструментите (CrawlService::crawlSite per-page, DeepResearcher map фазата)
 * го проверяват, за да спрат НАВРЕМЕ с частичен резултат, вместо job-ът да
 * умре с TimeoutExceededException — AgentLoop спира само СТАРТИРАНЕТО на нови
 * tool кръгове, но не може да прекъсне вече започнал crawl.
 */
final class NodeDeadline
{
    private static ?float $deadlineTs = null;

    public static function set(?float $deadlineTs): void
    {
        self::$deadlineTs = $deadlineTs;
    }

    public static function clear(): void
    {
        self::$deadlineTs = null;
    }

    /** True когато deadline-ът (минус буфера) е минал; false при незададен. */
    public static function passed(int $bufferSeconds = 0): bool
    {
        return self::$deadlineTs !== null
            && microtime(true) >= self::$deadlineTs - $bufferSeconds;
    }
}
