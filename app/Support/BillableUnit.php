<?php

namespace App\Support;

use App\Models\AssistantTask;

/**
 * Pure helper: превежда употреба (LLM токени / flat инструменти) → кредити, и дава
 * консервативни ПРЕД-старт оценки за резервация (§0.5.1/§14.2/§6.1).
 *
 * Каноничната формула за LLM ред: credits = base(level) × ceil(completion/1000),
 * където base = config('billing.star_multipliers')[level]. work_per_token е глобален
 * множител (по подразбиране 1.0 → no-op). Не-LLM инструментите се таксуват flat от
 * config('billing.flat_costs'). НЕ оценяваме крайния дебит — settle чете реалните токени.
 */
class BillableUnit
{
    /** base множител в кредити за дадено ниво. */
    public static function base(ModelLevel|string $level): int
    {
        $key = $level instanceof ModelLevel ? $level->value : $level;

        return (int) (config("billing.star_multipliers.{$key}") ?? 1);
    }

    /** LLM ред → кредити по каноничната формула base(level) × ceil(completion/1000). */
    public static function creditsForLlm(ModelLevel $level, int $completionTokens): int
    {
        if ($completionTokens <= 0) {
            return 0;
        }

        $work = (float) config('billing.work_per_token', 1.0);
        $kTokens = (int) ceil($completionTokens / 1000);

        return (int) ceil(self::base($level) * $work * $kTokens);
    }

    /** Flat кредитна цена за не-LLM инструмент (brave_search/places/ocr_page/avatar/embedding). */
    public static function flatCredits(string $tool): int
    {
        return (int) (config("billing.flat_costs.{$tool}") ?? 0);
    }

    /**
     * Консервативна оценка за резервация по контекст+ниво (predict ktokens от config).
     * Винаги поне 1 кредит, за да не резервираме 0 за платен контекст.
     */
    public static function estimateFor(string $contextType, ModelLevel $level): int
    {
        $kTokens = (int) (config("billing.estimate_ktokens.{$contextType}") ?? 5);

        return max(1, (int) ceil(self::base($level) * (float) config('billing.work_per_token', 1.0) * $kTokens));
    }

    /** Оценка за пускане на задача (по effective нивото ѝ). */
    public static function estimate(AssistantTask $task): int
    {
        return self::estimateFor('task_run', $task->effectiveStarTier());
    }

    /** Оценка за генерация на pipeline-а на задача (три фази на планера). */
    public static function estimateGeneration(AssistantTask $task): int
    {
        return self::estimateFor('generation', $task->effectiveStarTier());
    }
}
