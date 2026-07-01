<?php

namespace App\Services\Org\Billing;

use App\Models\Company;
use App\Models\CreditLedgerEntry;
use App\Models\CreditWallet;

/**
 * Дневен таван върху АВТОНОМНИЯ разход (директорски ticks, ревюта, scheduled, ignition).
 * Pre-flight гейт: питан ПРЕДИ всяко автономно резервиране. Ръчните (origin=manual) пътища
 * никога не го викат → човекът никога не се ограничава. При достигнат таван автономното
 * спира тихо (извикващият логва ≤1 org_event), без да хвърля.
 */
class AutonomousBudgetService
{
    /** Нетен автономен разход днес = reserve + overage − refund (origin=autonomous). */
    public function spentToday(int $companyId): int
    {
        $start = now()->startOfDay();

        $base = CreditLedgerEntry::where('company_id', $companyId)
            ->where('origin', 'autonomous')
            ->where('created_at', '>=', $start);

        $reserved = (int) (clone $base)->where('type', 'reserve')->sum('amount');
        $overage = (int) (clone $base)->where('type', 'overage')->sum('amount');
        $refunded = (int) (clone $base)->where('type', 'refund')->sum('amount');

        return max(0, $reserved + $overage - $refunded);
    }

    /**
     * Позволено ли е ново автономно харчене в този контекст. `ignition` е освободен по
     * подразбиране (потребителят току-що е натиснал „Одобри"). Иначе: под ефективния таван.
     */
    public function allows(Company $company, string $context = 'autonomous'): bool
    {
        if ($context === 'ignition' && (bool) config('organization.autonomous.caps.ignition_exempt', true)) {
            return true;
        }

        $cap = $this->effectiveCap($company);
        if ($cap === null) {
            return true;   // без включен таван
        }

        return $this->spentToday($company->id) < $cap;
    }

    /**
     * Минимумът от включените тавани (абс. кредити + процент от баланса); null = без таван.
     * Per-company override-ите имат приоритет над глобалния config (sentinel -1 = наследява).
     */
    private function effectiveCap(Company $company): ?int
    {
        $caps = [];

        // Per-company override на абсолютния кредитен таван (-1 = наследява глобалния).
        $dailyOverride = (int) $company->auton_daily_credits;
        $daily = $dailyOverride >= 0
            ? $dailyOverride                       // 0 = изключен, >0 = абсолютен
            : (int) config('organization.autonomous.caps.daily_credits', 0);
        if ($daily > 0) {
            $caps[] = $daily;
        }

        // Per-company override на процентния таван (-1 = наследява глобалния).
        $pctOverride = (int) $company->auton_daily_percent;
        $pct = $pctOverride >= 0
            ? $pctOverride
            : (int) config('organization.autonomous.caps.daily_percent_of_balance', 0);
        if ($pct > 0) {
            $balance = (int) (CreditWallet::where('company_id', $company->id)->value('balance') ?? 0);
            $caps[] = (int) floor($balance * $pct / 100);
        }

        return $caps === [] ? null : min($caps);
    }

    /**
     * Ефективният таван за preview (admin UI): връща [credits, percent, source] за една
     * компания — какво ще се приложи при следващо автономно харчене. `source` е
     * 'company' при явен override или 'global' при наследяване от config.
     *
     * @return array{credits: ?int, percent: ?int, source: string, balance: int, spent_today: int}
     */
    public function previewCap(Company $company): array
    {
        $dailyOverride = (int) $company->auton_daily_credits;
        $pctOverride = (int) $company->auton_daily_percent;
        $balance = (int) (CreditWallet::where('company_id', $company->id)->value('balance') ?? 0);

        $credits = null;
        $creditsSource = 'global';
        if ($dailyOverride >= 0) {
            $credits = $dailyOverride > 0 ? $dailyOverride : null;
            $creditsSource = 'company';
        } else {
            $global = (int) config('organization.autonomous.caps.daily_credits', 0);
            $credits = $global > 0 ? $global : null;
        }

        $percent = null;
        $percentSource = 'global';
        if ($pctOverride >= 0) {
            $percent = $pctOverride > 0 ? $pctOverride : null;
            $percentSource = 'company';
        } else {
            $global = (int) config('organization.autonomous.caps.daily_percent_of_balance', 0);
            $percent = $global > 0 ? $global : null;
        }

        $cap = $this->effectiveCap($company);

        return [
            'credits' => $credits,
            'percent' => $percent,
            'source' => ($creditsSource === 'company' || $percentSource === 'company') ? 'company' : 'global',
            'balance' => $balance,
            'effective_cap' => $cap,
            'spent_today' => $this->spentToday($company->id),
        ];
    }
}
