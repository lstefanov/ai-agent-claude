<?php

namespace App\Support;

/**
 * Презентационен слой на Хрониката: тип на събитие → икона (heroicon), цветен
 * акцент (token), група (за филтрите) и етикет. Централизира и разширява стария
 * inline `$typeTone` map от chronicle.blade.php.
 *
 * Покрива и синтетичните типове, които OrgChronicleService произвежда за
 * източниците извън org_events (flow_run_*, knowledge_*, credit_*).
 *
 * Purge-safety: цветовете се връщат само като ИМЕ на token; класовете се сглобяват
 * през colorClasses() и са или whitelist-нати char-* (виж app.css `@source inline`),
 * или семантични `*-soft/*-strong` (присъстват като литерали в badge.blade.php).
 * Иконата е само ИМЕ — `<x-icon>` е purge-safe сам по себе си.
 */
final class ChronicleType
{
    /** @var array<string,array{icon:string,color:string,group:string,label:string}> */
    private const MAP = [
        // ── org_events: екип ──
        'hire' => ['icon' => 'user-plus', 'color' => 'success', 'group' => 'team', 'label' => 'Наемане'],
        'fire' => ['icon' => 'user-minus', 'color' => 'danger', 'group' => 'team', 'label' => 'Напускане'],
        'reassign' => ['icon' => 'arrows-right-left', 'color' => 'char-blue', 'group' => 'team', 'label' => 'Преназначаване'],
        'mandate_change' => ['icon' => 'arrow-trending-up', 'color' => 'char-amber', 'group' => 'team', 'label' => 'Ниво/мандат'],

        // ── org_events: решения/управление ──
        'approval' => ['icon' => 'check-badge', 'color' => 'accent', 'group' => 'decision', 'label' => 'Одобрение'],
        'review' => ['icon' => 'eye', 'color' => 'neutral', 'group' => 'decision', 'label' => 'Ревю'],
        'daily_digest' => ['icon' => 'newspaper', 'color' => 'neutral', 'group' => 'decision', 'label' => 'Дневен преглед'],

        // ── org_events: задачи (lifecycle до изпълнение) ──
        'task_proposed' => ['icon' => 'light-bulb', 'color' => 'char-amber', 'group' => 'task', 'label' => 'Предложена задача'],
        'task_approved' => ['icon' => 'check-circle', 'color' => 'success', 'group' => 'task', 'label' => 'Одобрена задача'],
        'task_rejected' => ['icon' => 'x-circle', 'color' => 'danger', 'group' => 'task', 'label' => 'Отхвърлена задача'],
        'task_completed' => ['icon' => 'check-circle', 'color' => 'success', 'group' => 'task', 'label' => 'Изпълнена задача'],
        'task_failed' => ['icon' => 'exclamation-triangle', 'color' => 'danger', 'group' => 'task', 'label' => 'Провалена задача'],

        // ── org_events: изпълнения/действия ──
        'action' => ['icon' => 'bolt', 'color' => 'char-teal', 'group' => 'run', 'label' => 'Действие'],
        'flow_activated' => ['icon' => 'rocket-launch', 'color' => 'accent', 'group' => 'run', 'label' => 'Активиран flow'],

        // ── синтетични: изпълнения (flow_runs) ──
        'flow_run_completed' => ['icon' => 'bolt', 'color' => 'accent', 'group' => 'run', 'label' => 'Изпълнение'],
        'flow_run_failed' => ['icon' => 'exclamation-triangle', 'color' => 'danger', 'group' => 'run', 'label' => 'Неуспешно изпълнение'],
        'flow_run_active' => ['icon' => 'play', 'color' => 'info', 'group' => 'run', 'label' => 'Тече изпълнение'],

        // ── синтетични: знание (knowledge_events) ──
        'knowledge_added' => ['icon' => 'book-open', 'color' => 'char-teal', 'group' => 'knowledge', 'label' => 'Ново знание'],
        'knowledge_updated' => ['icon' => 'book-open', 'color' => 'char-teal', 'group' => 'knowledge', 'label' => 'Обновено знание'],
        'knowledge_deleted' => ['icon' => 'trash', 'color' => 'neutral', 'group' => 'knowledge', 'label' => 'Премахнато знание'],

        // ── синтетични: билинг/кредити (credit_ledger) ──
        'credit_settle' => ['icon' => 'banknotes', 'color' => 'neutral', 'group' => 'billing', 'label' => 'Разход'],
        'credit_topup' => ['icon' => 'arrow-up-circle', 'color' => 'success', 'group' => 'billing', 'label' => 'Зареждане'],
        'credit_grant' => ['icon' => 'gift', 'color' => 'success', 'group' => 'billing', 'label' => 'Кредитен пакет'],
        'credit_refund' => ['icon' => 'arrow-uturn-left', 'color' => 'info', 'group' => 'billing', 'label' => 'Възстановени'],
    ];

    /** Филтър-групите, показвани като чипове (ред = ред в UI). */
    public const GROUPS = [
        'task' => 'Задачи',
        'team' => 'Екип',
        'knowledge' => 'Знание',
        'run' => 'Изпълнения',
        'billing' => 'Билинг',
        'decision' => 'Решения',
    ];

    /** @return array{icon:string,color:string,group:string,label:string} */
    public static function presentation(string $type): array
    {
        return self::MAP[$type] ?? ['icon' => 'bell-alert', 'color' => 'neutral', 'group' => 'decision', 'label' => $type];
    }

    /**
     * Пълните класове за цветния icon-badge / pill. Работят и за char-* (whitelist-нати),
     * и за семантичните токени (литерали в badge.blade.php) — без интерполация в Blade.
     */
    public static function colorClasses(string $color): string
    {
        return "bg-{$color}-soft text-{$color}-strong";
    }
}
