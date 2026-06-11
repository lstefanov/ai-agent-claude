<?php

namespace App\Support;

/**
 * In-process accumulator for paid-provider token usage (Фаза 2 — cost tracking).
 *
 * Providers call record() after every API response; consumers (planner phase
 * logging, node executor) call take() to collect-and-reset the running total
 * for the unit of work they just finished. Accumulation matters: one node may
 * make several calls (self-check retries), one planner phase exactly one.
 */
class LlmUsage
{
    private static int $promptTokens = 0;

    private static int $completionTokens = 0;

    private static float $costUsd = 0.0;

    public static function record(
        string $provider,
        string $model,
        int $promptTokens,
        int $completionTokens,
        int $cacheWriteTokens = 0,
        int $cacheReadTokens = 0,
    ): void {
        self::$promptTokens += $promptTokens;
        self::$completionTokens += $completionTokens;
        self::$costUsd += self::costFor($provider, $model, $promptTokens, $completionTokens, $cacheWriteTokens, $cacheReadTokens);
    }

    public static function addFlatCost(float $costUsd): void
    {
        if ($costUsd > 0) {
            self::$costUsd += $costUsd;
        }
    }

    /**
     * Return the accumulated usage and reset the counters.
     *
     * @return array{prompt_tokens: int|null, completion_tokens: int|null, cost_usd: float|null}
     */
    public static function take(): array
    {
        $usage = [
            'prompt_tokens' => self::$promptTokens ?: null,
            'completion_tokens' => self::$completionTokens ?: null,
            'cost_usd' => self::$costUsd > 0 ? round(self::$costUsd, 6) : null,
        ];

        self::$promptTokens = 0;
        self::$completionTokens = 0;
        self::$costUsd = 0.0;

        return $usage;
    }

    /**
     * USD cost from the per-1M-token price table in config/services.php.
     *
     * When cacheWriteTokens / cacheReadTokens are provided (Anthropic prompt
     * caching), the cost is computed with the correct multipliers:
     *   - cache write tokens: 1.25 × base input price
     *   - cache read tokens:  0.10 × base input price
     *   - regular input tokens (input_tokens − write − read): 1 × base input price
     */
    public static function costFor(
        string $provider,
        string $model,
        int $promptTokens,
        int $completionTokens,
        int $cacheWriteTokens = 0,
        int $cacheReadTokens = 0,
    ): float {
        $pricing = config("services.{$provider}.pricing", []);

        // Exact tag first, then prefix match (e.g. "gpt-4o-2024-11-20" → "gpt-4o").
        $prices = $pricing[$model] ?? null;
        if ($prices === null) {
            foreach ($pricing as $tag => $p) {
                if (str_starts_with($model, (string) $tag)) {
                    $prices = $p;
                    break;
                }
            }
        }

        if (! is_array($prices)) {
            return 0.0;
        }

        $baseIn = (float) ($prices['in'] ?? 0);
        $baseOut = (float) ($prices['out'] ?? 0);

        $regularIn = max(0, $promptTokens - $cacheWriteTokens - $cacheReadTokens);

        return ($regularIn / 1_000_000) * $baseIn
            + ($cacheWriteTokens / 1_000_000) * ($baseIn * 1.25)
            + ($cacheReadTokens / 1_000_000) * ($baseIn * 0.10)
            + ($completionTokens / 1_000_000) * $baseOut;
    }
}
