<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Global N-slot semaphore for LOCAL Ollama inference.
 *
 * The Ollama box has limited VRAM — running several different local models at
 * once forces model swapping, which is slower than strict serialization. The
 * slot count (OLLAMA_MAX_CONCURRENT, default 1) is GLOBAL, not per-run: two
 * simultaneous FlowRuns share the same budget, because they share the same GPU.
 * Cloud-pinned nodes (openai/*, anthropic/*) never pass through here.
 *
 * Works on the database cache driver: one Cache::lock per slot, non-blocking
 * get() + retry loop instead of block() so N slots can be probed fairly.
 */
class OllamaSemaphore
{
    /** Hard ceiling for one node (matches ExecuteNodeJob::$timeout headroom). */
    private const SLOT_TTL_SECONDS = 1800;

    /** How long a job may wait for a free slot before giving up. */
    private const WAIT_DEADLINE_SECONDS = 1200;

    private const RETRY_SLEEP_SECONDS = 3;

    public static function run(callable $callback): mixed
    {
        $slots = max(1, (int) config('services.ollama.max_concurrent', 1));
        $deadline = time() + self::WAIT_DEADLINE_SECONDS;

        while (time() < $deadline) {
            for ($i = 0; $i < $slots; $i++) {
                $lock = Cache::lock("ollama-slot-{$i}", self::SLOT_TTL_SECONDS);

                if ($lock->get()) {
                    try {
                        return $callback();
                    } finally {
                        $lock->release();
                    }
                }
            }

            sleep(self::RETRY_SLEEP_SECONDS);
        }

        throw new \RuntimeException(sprintf(
            'Не се освободи Ollama слот за %d минути (OLLAMA_MAX_CONCURRENT=%d).',
            (int) (self::WAIT_DEADLINE_SECONDS / 60),
            $slots,
        ));
    }
}
