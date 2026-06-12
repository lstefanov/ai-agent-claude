<?php

namespace App\Support;

/**
 * Per-run лог файл: storage/logs/run-{id}.log.
 *
 * Човешки четима следа на едно изпълнение — пишат я GraphFlowExecutor
 * (start/wave/финал), NodeExecutorService (стъпки, QA, replan, памет) и
 * агенти със собствен прогрес (DeepResearcherAgent). Чете се сурово от
 * FlowRunController::log() и се парсва от parseRunProgress(), който
 * разпознава "STEP n/total: име" маркери за live-прогрес панела.
 */
final class RunLog
{
    public static function path(int $flowRunId): string
    {
        return storage_path("logs/run-{$flowRunId}.log");
    }

    /** Добавя един "[H:i:s] съобщение" ред; логването никога не проваля run. */
    public static function append(int $flowRunId, string $message): void
    {
        @file_put_contents(self::path($flowRunId), date('[H:i:s]')." {$message}\n", FILE_APPEND | LOCK_EX);
    }
}
