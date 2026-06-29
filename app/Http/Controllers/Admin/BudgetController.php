<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CreditLedgerEntry;
use App\Models\Director;
use App\Models\OrgProposal;
use App\Services\Org\Billing\AutonomousBudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin „Бюджети" — per-company управление на автономния дневен таван + пълна
 * дневна история на харчените кредити. Тънък контролер над AutonomousBudgetService
 * (който е източникът на истината за ефективния cap) и над credit_ledger
 * (append-only журналът за историята).
 */
class BudgetController extends Controller
{
    public function __construct(private AutonomousBudgetService $budget) {}

    /** Списък всички компании с текущ капакс и статус на харчене днес. */
    public function index(Request $r)
    {
        $search = trim((string) $r->query('search', ''));

        $companies = Company::query()
            ->with('creditWallet')
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderByDesc('id')
            ->paginate(25)
            ->appends($r->query());

        $rows = $companies->getCollection()->map(fn (Company $c) => $this->companyRow($c))->all();
        $companies->setCollection(collect($rows));

        return view('admin.budgets.index', [
            'companies' => $companies,
            'globalCredits' => (int) config('organization.autonomous.caps.daily_credits', 0),
            'globalPercent' => (int) config('organization.autonomous.caps.daily_percent_of_balance', 0),
            'search' => $search,
        ]);
    }

    /** Детайл за една компания: текущ бюджет + пълна дневна история. */
    public function show(Request $r, Company $company)
    {
        $days = max(1, min(120, (int) $r->query('days', 30)));
        $history = $this->dailyHistory($company, $days);

        return view('admin.budgets.show', [
            'company' => $company,
            'wallet' => $company->creditWallet,
            'preview' => $this->budget->previewCap($company),
            'history' => $history,
            'days' => $days,
        ]);
    }

    /** AJAX: дневна история като JSON (за live reload-а на detail страницата). */
    public function history(Request $r, Company $company): JsonResponse
    {
        $days = max(1, min(365, (int) $r->query('days', 30)));

        return response()->json([
            'company' => ['id' => $company->id, 'name' => $company->name],
            'preview' => $this->budget->previewCap($company),
            'history' => $this->dailyHistory($company, $days),
        ]);
    }

    /**
     * Запис на per-company override (двете полета заедно). -1 = наследява глобалния,
     * 0 = без таван, >0 = абс./%. Валидация: -1, 0 или положително число.
     */
    public function update(Request $r, Company $company): JsonResponse
    {
        $validated = $r->validate([
            'auton_daily_credits' => ['required', 'integer', 'min:-1'],
            'auton_daily_percent' => ['required', 'integer', 'min:-1', 'max:100'],
        ]);

        $company->update([
            'auton_daily_credits' => (int) $validated['auton_daily_credits'],
            'auton_daily_percent' => (int) $validated['auton_daily_percent'],
        ]);

        return response()->json([
            'ok' => true,
            'preview' => $this->budget->previewCap($company->fresh()),
        ]);
    }

    // ── вътрешни ──────────────────────────────────────────────────────────

    /** Едно поколение за таблицата (без N+1 — spentToday е на 1 заявка/фирма). */
    private function companyRow(Company $company): Company
    {
        $preview = $this->budget->previewCap($company);
        $company->setAttribute('_budget_preview', $preview);
        $company->setAttribute('_pending_proposals', OrgProposal::where('company_id', $company->id)->pending()->count());
        $company->setAttribute('_directors', Director::whereHas('orgVersion', fn ($q) => $q->where('company_id', $company->id))->count());

        return $company;
    }

    /**
     * Дневна история на автономния разход за една компания — групирана по ден
     * от credit_ledger (origin=autonomous), с разбивка по контекст (director_tick,
     * scheduled, ignition, …). context_type живее в credit_reservations, затова LEFT
     * JOIN. Включва и нетния балансов дневен поток (всички origin-и + topup/grant)
     * за пълна одиторна следа.
     *
     * @return array{days: array<int, array{date: string, autonomous_spent: int, by_context: array<string, int>, total_debit: int, total_credit: int, balance_change: int}>, totals: array{autonomous_spent: int, total_debit: int, total_credit: int, days: int}}
     */
    private function dailyHistory(Company $company, int $days): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        // 1) Автономен разход по ден + контекст (credit_ledger.origin = autonomous).
        //    context_type идва от credit_reservations през JOIN.
        $autoRows = DB::table('credit_ledger')
            ->leftJoin('credit_reservations', 'credit_ledger.reservation_id', '=', 'credit_reservations.id')
            ->where('credit_ledger.company_id', $company->id)
            ->where('credit_ledger.origin', 'autonomous')
            ->where('credit_ledger.created_at', '>=', $from)
            ->where('credit_ledger.direction', 'debit')
            ->selectRaw('DATE(credit_ledger.created_at) as d, COALESCE(credit_reservations.context_type, credit_ledger.reason) as ctx, SUM(credit_ledger.amount) as amt')
            ->groupByRaw('DATE(credit_ledger.created_at), COALESCE(credit_reservations.context_type, credit_ledger.reason)')
            ->get();

        // 2) Пълен дневен дебит/кредит (всички origin-и) за балансов поток.
        $flowRows = CreditLedgerEntry::where('company_id', $company->id)
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as d, direction, SUM(amount) as amt')
            ->groupByRaw('DATE(created_at), direction')
            ->get();

        // Изграждаме карта ден → агрегати.
        $map = [];
        for ($i = 0; $i < $days; $i++) {
            $d = now()->subDays($days - 1 - $i)->format('Y-m-d');
            $map[$d] = [
                'date' => $d,
                'autonomous_spent' => 0,
                'by_context' => [],
                'total_debit' => 0,
                'total_credit' => 0,
                'balance_change' => 0,
            ];
        }

        foreach ($autoRows as $row) {
            $d = (string) $row->d;
            if (! isset($map[$d])) {
                continue;
            }
            $ctx = (string) ($row->ctx ?? 'unknown');
            $amt = (int) $row->amt;
            $map[$d]['autonomous_spent'] += $amt;
            $map[$d]['by_context'][$ctx] = ($map[$d]['by_context'][$ctx] ?? 0) + $amt;
        }

        foreach ($flowRows as $row) {
            $d = (string) $row->d;
            if (! isset($map[$d])) {
                continue;
            }
            $amt = (int) $row->amt;
            if ($row->direction === 'debit') {
                $map[$d]['total_debit'] += $amt;
            } else {
                $map[$d]['total_credit'] += $amt;
            }
        }
        foreach ($map as &$day) {
            $day['balance_change'] = $day['total_credit'] - $day['total_debit'];
        }
        unset($day);

        $totals = [
            'autonomous_spent' => array_sum(array_column(array_map(fn ($d) => (array) $d, array_values($map)), 'autonomous_spent')),
            'total_debit' => array_sum(array_column(array_map(fn ($d) => (array) $d, array_values($map)), 'total_debit')),
            'total_credit' => array_sum(array_column(array_map(fn ($d) => (array) $d, array_values($map)), 'total_credit')),
            'days' => $days,
        ];

        return ['days' => array_values($map), 'totals' => $totals];
    }
}
