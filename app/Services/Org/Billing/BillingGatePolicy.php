<?php

namespace App\Services\Org\Billing;

/**
 * Решава дали даден билинг контекст е HARD-gated (блокира при липса на кредити, връща 402/skip)
 * или SOFT (best-effort — продължава, атрибутира, без резервация). По (context_type + origin),
 * с config override-и (`config('billing.gate')`). Call site-ът може да подаде explicit override.
 */
class BillingGatePolicy
{
    public static function hardGate(string $contextType, string $origin): bool
    {
        // Всяко автономно харчене е hard-gated — иначе заобикаля дневния автономен таван.
        if ($origin === 'autonomous') {
            return (string) config('billing.gate.autonomous', 'hard') === 'hard';
        }

        $key = match ($contextType) {
            'task_run' => 'task_run',
            'generation' => 'generation_manual',   // org generation подава explicit hardGate=true
            'text_assist' => 'text_assist',
            'avatar' => 'avatar',
            'assistant' => 'assistant',
            'client_wizard' => 'client_wizard',
            'research' => 'research',
            'interview' => 'interview',
            'knowledge_chat' => 'knowledge_chat',
            'knowledge_ingest' => 'knowledge_ingest',
            default => null,
        };

        if ($key === null) {
            return false;   // непознат owner-инструмент → best-effort
        }

        return (string) config("billing.gate.{$key}", 'soft') === 'hard';
    }
}
