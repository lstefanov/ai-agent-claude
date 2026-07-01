<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CreditLedgerEntry;
use App\Models\CreditReservation;
use App\Models\CreditWallet;
use App\Models\Flow;
use App\Models\FlowRun;
use App\Models\LlmRequest;
use App\Models\OrgMember;
use App\Support\BillingSubjectLabeler;
use App\Support\StatsLabels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Чисти company-scoped SQL агрегации за страницата за статистика.
 * Всяка публична функция приема Company + filters масив и връща данни
 * (без HTTP/Response логика — контролерът е тънкият слой отгоре).
 *
 * Критични правила (от PLAN E):
 * 1. Похарчени кредити = SUM(credit_reservations.spent_credits), НЕ ledger debit сума.
 * 2. Running balance = credit_ledger.wallet_balance_after директно.
 * 3. Per-flow кредити: през credit_reservations.subject (task_run→FlowRun→flow; generation→AssistantTask→flow).
 * 4. Derived/≈ кредити (без резервация): ceil(cost_usd × credit_markup), ясно маркирани.
 * 5. „Ден" в app timezone, UTC граници за WHERE.
 */
class CompanyStatsQueryService
{
    private const EXTERNAL_PROVIDERS = ['perplexity', 'brave', 'google_places'];

    private const KNOWLEDGE_PURPOSES = ['embedding', 'knowledge_synthesis', 'knowledge_fact_harvest', 'knowledge_chat'];

    private const OCR_PROVIDER = 'mistral';

    private const OCR_KIND = 'ocr';

    /** Whitelisted sort колони за Grid.js server mode. */
    public const SORTABLE = ['created_at', 'cost_usd', 'duration_ms', 'total_tokens', 'credits', 'amount', 'call_count'];

    // ─────────────────────────────────────────────────────────────────────
    // Base builder helpers
    // ─────────────────────────────────────────────────────────────────────

    /** Базов builder за llm_requests на тази фирма с приложени филтри. */
    public function llmBase(Company $company, array $filters): Builder
    {
        return LlmRequest::query()
            ->where('llm_requests.company_id', $company->id)
            ->when($filters['provider'] ?? null, fn ($q, $v) => $q->where('llm_requests.provider', $v))
            ->when($filters['context_type'] ?? null, fn ($q, $v) => $q->where('llm_requests.context_type', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('llm_requests.created_at', '>=', $this->dayStart($v)))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('llm_requests.created_at', '<=', $this->dayEnd($v)));
    }

    /** Базов builder за credit_reservations на тази фирма. */
    private function resBase(Company $company, array $filters): Builder
    {
        return CreditReservation::query()
            ->where('company_id', $company->id)
            ->when($filters['context_type'] ?? null, fn ($q, $v) => $q->where('context_type', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $this->dayStart($v)))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $this->dayEnd($v)));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Overview
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Обобщени карти за таб „Преглед":
     * - summary: USD (total/month/today/unbilled), requests, tokens
     * - credits: balance, spent, granted, topped_up, refunded, overage
     * - reconciliation: реален USD, кредити, ориентировъчна стойност, нетаксуван USD
     * - providers: разпределение по провайдър
     * - charts: spendByDay, creditsByDay, costByService, providerBreakdown, modelBreakdown
     */
    public function overview(Company $company, array $filters): array
    {
        return [
            'summary' => $this->summary($company, $filters),
            'providers' => $this->providerBreakdown($company, $filters),
            'charts' => [
                'spendByDay' => $this->spendByDay($company, $filters),
                'creditsByDay' => $this->creditsByDay($company, $filters),
                'costByService' => $this->costByService($company, $filters),
                'byModel' => $this->spendByModel($company, $filters),
            ],
            'reconciliation' => $this->reconciliation($company, $filters),
        ];
    }

    /** Summary карти: USD + Кредити. */
    public function summary(Company $company, array $filters): array
    {
        $base = $this->llmBase($company, $filters);

        $agg = (clone $base)
            ->selectRaw('
                ROUND(SUM(cost_usd), 4) as total_cost,
                COUNT(*) as total_requests,
                COALESCE(SUM(total_tokens), 0) as total_tokens,
                COALESCE(SUM(prompt_tokens), 0) as prompt_tokens,
                COALESCE(SUM(completion_tokens), 0) as completion_tokens
            ')
            ->first();

        $monthStart = $this->dayStart(now()->startOfMonth()->format('Y-m-d'));
        $todayStart = $this->dayStart(now()->format('Y-m-d'));

        $monthCost = (float) (clone $base)->where('llm_requests.created_at', '>=', $monthStart)->sum('cost_usd');
        $todayCost = (float) (clone $base)->where('llm_requests.created_at', '>=', $todayStart)->sum('cost_usd');

        // Нетаксуван USD: company_id зададен, reservation_id NULL
        $unbilledUsd = (float) (clone $base)->whereNull('reservation_id')->sum('cost_usd');

        // Кредити от wallet-а
        $wallet = $company->creditWallet;
        $creditSummary = $this->creditSummary($company, $filters);

        return [
            'total_cost' => round((float) ($agg->total_cost ?? 0), 4),
            'month_cost' => round($monthCost, 4),
            'today_cost' => round($todayCost, 4),
            'unbilled_usd' => round($unbilledUsd, 4),
            'total_requests' => (int) ($agg->total_requests ?? 0),
            'total_tokens' => (int) ($agg->total_tokens ?? 0),
            'prompt_tokens' => (int) ($agg->prompt_tokens ?? 0),
            'completion_tokens' => (int) ($agg->completion_tokens ?? 0),
            'balance' => $wallet ? (int) $wallet->balance : 0,
            'credits' => $creditSummary,
        ];
    }

    /**
     * Кредитно резюме от credit_ledger:
     * - granted: SUM(amount) WHERE type IN (grant)
     * - topped_up: SUM(amount) WHERE type = topup
     * - spent: SUM(spent_credits) от credit_reservations (авторитетното)
     * - refunded: SUM(amount) WHERE type = refund
     * - overage: SUM(amount) WHERE type = overage
     */
    public function creditSummary(Company $company, array $filters): array
    {
        $ledgerBase = CreditLedgerEntry::where('company_id', $company->id);
        if ($from = ($filters['from'] ?? null)) {
            $ledgerBase->where('created_at', '>=', $this->dayStart($from));
        }
        if ($to = ($filters['to'] ?? null)) {
            $ledgerBase->where('created_at', '<=', $this->dayEnd($to));
        }

        $byType = (clone $ledgerBase)
            ->selectRaw('type, COALESCE(SUM(amount), 0) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        // Похарчени кредити: авторитетно от credit_reservations.spent_credits
        $spent = (int) $this->resBase($company, $filters)->sum('spent_credits');

        $wallet = $company->creditWallet;

        return [
            'granted' => (int) ($byType['grant'] ?? 0),
            'topped_up' => (int) ($byType['topup'] ?? 0),
            'spent' => $spent,
            'refunded' => (int) ($byType['refund'] ?? 0),
            'overage' => (int) ($byType['overage'] ?? 0),
            'balance' => $wallet ? (int) $wallet->balance : 0,
        ];
    }

    /**
     * Reconciliation карта:
     * - real_usd: сума llm_requests.cost_usd
     * - external_usd: сума от external провайдъри
     * - spent_credits: Σ spent_credits (авторитетно)
     * - indicative_credit_usd: ориентировъчна стойност (от topup rate ако има, иначе cost / markup)
     * - unbilled_usd: llm_requests с reservation_id IS NULL
     */
    public function reconciliation(Company $company, array $filters): array
    {
        $base = $this->llmBase($company, $filters);
        $markup = (float) config('billing.credit_markup', 3.0);

        $realUsd = (float) (clone $base)->sum('cost_usd');
        $externalUsd = (float) (clone $base)
            ->whereIn('llm_requests.provider', self::EXTERNAL_PROVIDERS)
            ->sum('cost_usd');
        $unbilledUsd = (float) (clone $base)->whereNull('reservation_id')->sum('cost_usd');
        $spentCredits = (int) $this->resBase($company, $filters)->sum('spent_credits');

        // Ориентировъчна стойност: ако markup > 0, cost / markup е груба оценка.
        // Не е гаранция — просто ориентир (star multipliers + flat тарифи = реалното).
        $indicativeUsd = $markup > 0 ? round($realUsd / $markup, 4) : null;

        return [
            'real_usd' => round($realUsd, 4),
            'external_usd' => round($externalUsd, 4),
            'spent_credits' => $spentCredits,
            'indicative_credit_usd' => $indicativeUsd,
            'unbilled_usd' => round($unbilledUsd, 4),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Charts
    // ─────────────────────────────────────────────────────────────────────

    /** USD по ден в app timezone. */
    public function spendByDay(Company $company, array $filters): array
    {
        [$from, $to] = $this->dateRange($company, $filters);

        $rows = $this->llmBase($company, $filters)
            ->whereBetween('llm_requests.created_at', [$from, $to])
            ->selectRaw('DATE(CONVERT_TZ(llm_requests.created_at, "+00:00", ?)) as day, ROUND(SUM(cost_usd), 4) as cost, COALESCE(SUM(total_tokens), 0) as tokens', [$this->tzOffset()])
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $days = $this->fillDays($from, $to, ['cost' => 0.0, 'tokens' => 0]);
        foreach ($rows as $row) {
            if (isset($days[$row->day])) {
                $days[$row->day] = ['cost' => (float) $row->cost, 'tokens' => (int) $row->tokens];
            }
        }

        return [
            'labels' => array_keys($days),
            'cost' => array_values(array_map(fn ($d) => $d['cost'], $days)),
            'tokens' => array_values(array_map(fn ($d) => $d['tokens'], $days)),
        ];
    }

    /**
     * Кредитни движения по ден (от credit_ledger).
     * Само баланс-движещи типове: reserve (debit), refund (credit), topup (credit),
     * grant (credit), overage (debit). `settle` е информативен (баланс ефект 0).
     */
    public function creditsByDay(Company $company, array $filters): array
    {
        [$from, $to] = $this->dateRange($company, $filters);

        $rows = CreditLedgerEntry::where('company_id', $company->id)
            ->whereIn('type', ['reserve', 'refund', 'topup', 'grant', 'overage'])
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('
                DATE(CONVERT_TZ(created_at, "+00:00", ?)) as day,
                type,
                COALESCE(SUM(amount), 0) as total
            ', [$this->tzOffset()])
            ->groupBy('day', 'type')
            ->orderBy('day')
            ->get();

        $days = $this->fillDays($from, $to, []);
        foreach ($rows as $row) {
            if (isset($days[$row->day])) {
                $days[$row->day][$row->type] = (int) $row->total;
            }
        }

        $types = ['reserve', 'refund', 'topup', 'grant', 'overage'];

        return [
            'labels' => array_keys($days),
            'datasets' => array_map(fn ($type) => [
                'type' => $type,
                'label' => StatsLabels::ledgerType($type),
                'data' => array_values(array_map(fn ($d) => $d[$type] ?? 0, $days)),
            ], $types),
        ];
    }

    /** USD + Кредити по услуга (context_type grouping). */
    public function costByService(Company $company, array $filters): array
    {
        $markup = (float) config('billing.credit_markup', 3.0);

        $llmRows = $this->llmBase($company, $filters)
            ->selectRaw('
                context_type,
                purpose,
                COUNT(*) as call_count,
                COALESCE(SUM(total_tokens), 0) as tokens,
                ROUND(SUM(cost_usd), 4) as cost_usd
            ')
            ->groupBy('context_type', 'purpose')
            ->get();

        $spentByContext = $this->resBase($company, $filters)
            ->selectRaw('context_type, COALESCE(SUM(spent_credits), 0) as spent')
            ->groupBy('context_type')
            ->pluck('spent', 'context_type');

        $grouped = [];
        foreach ($llmRows as $row) {
            $key = $row->context_type ?? $row->purpose ?? 'other';
            $svc = StatsLabels::service($row->context_type, $row->purpose);
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'key' => $key,
                    'label' => $svc['label'],
                    'color' => $svc['color'] ?? 'gray',
                    'icon' => $svc['icon'] ?? 'ellipsis-horizontal',
                    'call_count' => 0,
                    'tokens' => 0,
                    'cost_usd' => 0.0,
                    'spent_credits' => 0,
                    'est_credits' => 0,
                ];
            }
            $grouped[$key]['call_count'] += (int) $row->call_count;
            $grouped[$key]['tokens'] += (int) $row->tokens;
            $grouped[$key]['cost_usd'] += (float) $row->cost_usd;
            if ($row->context_type && isset($spentByContext[$row->context_type])) {
                $grouped[$key]['spent_credits'] = (int) $spentByContext[$row->context_type];
            }
        }

        // Derived/≈ кредити за редовете без реални резервации
        foreach ($grouped as &$g) {
            if ($g['spent_credits'] === 0 && $markup > 0) {
                $g['est_credits'] = (int) ceil($g['cost_usd'] * $markup);
            }
        }
        unset($g);

        $result = array_values($grouped);
        usort($result, fn ($a, $b) => $b['cost_usd'] <=> $a['cost_usd']);

        return [
            'labels' => array_column($result, 'label'),
            'cost' => array_column($result, 'cost_usd'),
            'credits' => array_map(fn ($r) => $r['spent_credits'] > 0 ? $r['spent_credits'] : null, $result),
            'est_credits' => array_map(fn ($r) => $r['est_credits'] > 0 ? $r['est_credits'] : null, $result),
            'rows' => $result,
        ];
    }

    /** USD по провайдър (doughnut/bar). */
    public function providerBreakdown(Company $company, array $filters): array
    {
        $rows = $this->llmBase($company, $filters)
            ->selectRaw('provider, COUNT(*) as cnt, ROUND(SUM(cost_usd), 4) as cost')
            ->groupBy('provider')
            ->orderByDesc('cost')
            ->get();

        return $rows->map(fn ($r) => [
            'provider' => $r->provider ?? '—',
            'label' => StatsLabels::externalName($r->provider ?? ''),
            'requests' => (int) $r->cnt,
            'cost' => (float) $r->cost,
        ])->values()->all();
    }

    /** USD по модел (top 10). */
    public function spendByModel(Company $company, array $filters): array
    {
        $rows = $this->llmBase($company, $filters)
            ->selectRaw('model, COUNT(*) as cnt, ROUND(SUM(cost_usd), 4) as cost')
            ->groupBy('model')
            ->havingRaw('SUM(cost_usd) > 0')
            ->orderByDesc('cost')
            ->limit(10)
            ->get();

        return [
            'labels' => $rows->pluck('model')->map(fn ($m) => $m ?: '—')->values()->all(),
            'data' => $rows->pluck('cost')->map(fn ($c) => round((float) $c, 4))->values()->all(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Services table
    // ─────────────────────────────────────────────────────────────────────

    /** Таблица по услуга (context_type / purpose) с пагинация. */
    public function servicesTable(Company $company, array $filters, int $page, int $limit, string $sort, string $dir): array
    {
        $markup = (float) config('billing.credit_markup', 3.0);

        $rows = $this->llmBase($company, $filters)
            ->selectRaw('
                context_type,
                purpose,
                COUNT(*) as call_count,
                COALESCE(SUM(total_tokens), 0) as tokens,
                ROUND(SUM(cost_usd), 6) as cost_usd
            ')
            ->groupBy('context_type', 'purpose')
            ->get();

        // Merge per context_type reservations (авторитетни кредити)
        $spentByContext = $this->resBase($company, $filters)
            ->selectRaw('context_type, COALESCE(SUM(spent_credits), 0) as spent')
            ->groupBy('context_type')
            ->pluck('spent', 'context_type');

        $grouped = [];
        foreach ($rows as $row) {
            $key = $row->context_type ?? $row->purpose ?? 'other';
            if (! isset($grouped[$key])) {
                $svc = StatsLabels::service($row->context_type, $row->purpose);
                $grouped[$key] = [
                    'service_key' => $key,
                    'label' => $svc['label'],
                    'icon' => $svc['icon'] ?? null,
                    'color' => $svc['color'] ?? 'gray',
                    'call_count' => 0,
                    'tokens' => 0,
                    'cost_usd' => 0.0,
                    'spent_credits' => (int) ($spentByContext[$key] ?? 0),
                    'est_credits' => 0,
                    'credit_type' => 'real',
                ];
            }
            $grouped[$key]['call_count'] += (int) $row->call_count;
            $grouped[$key]['tokens'] += (int) $row->tokens;
            $grouped[$key]['cost_usd'] += (float) $row->cost_usd;
        }

        foreach ($grouped as &$g) {
            $g['cost_usd'] = round($g['cost_usd'], 4);
            if ($g['spent_credits'] === 0 && $markup > 0) {
                $g['est_credits'] = (int) ceil($g['cost_usd'] * $markup);
                $g['credit_type'] = 'estimated';
            }
        }
        unset($g);

        $result = array_values($grouped);

        // Сортиране
        $sortMap = ['cost_usd' => 'cost_usd', 'credits' => 'spent_credits', 'call_count' => 'call_count', 'tokens' => 'tokens'];
        $sortKey = $sortMap[$sort] ?? 'cost_usd';
        usort($result, function ($a, $b) use ($sortKey, $dir) {
            return $dir === 'asc' ? ($a[$sortKey] <=> $b[$sortKey]) : ($b[$sortKey] <=> $a[$sortKey]);
        });

        $total = count($result);
        $paged = array_slice($result, ($page - 1) * $limit, $limit);

        $summary = [
            'call_count' => array_sum(array_column($result, 'call_count')),
            'tokens' => array_sum(array_column($result, 'tokens')),
            'cost_usd' => round(array_sum(array_column($result, 'cost_usd')), 4),
            'spent_credits' => array_sum(array_column($result, 'spent_credits')),
        ];

        return ['rows' => $paged, 'total' => $total, 'summary' => $summary];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Flows table
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Таблица по flow: runs, llm cost, кредити (от резервации subject=FlowRun/AssistantTask→flow).
     * Правило: кредитите идват от credit_reservations.subject, НЕ от ledger.flow_run_id.
     */
    public function flowsTable(Company $company, array $filters, int $page, int $limit, string $sort, string $dir): array
    {
        // llm_requests агрегирани по flow_id (за компанията)
        $llmRows = $this->llmBase($company, $filters)
            ->whereNotNull('llm_requests.flow_id')
            ->selectRaw('
                llm_requests.flow_id,
                COUNT(*) as call_count,
                COALESCE(SUM(llm_requests.total_tokens), 0) as tokens,
                ROUND(SUM(llm_requests.cost_usd), 4) as cost_usd,
                MAX(llm_requests.created_at) as last_request_at
            ')
            ->groupBy('llm_requests.flow_id')
            ->get()
            ->keyBy('flow_id');

        // Runs по flow
        $flowIds = $llmRows->keys()->all();
        $runStats = FlowRun::whereIn('flow_id', $flowIds)
            ->selectRaw('flow_id, COUNT(*) as run_count, MAX(created_at) as last_run_at')
            ->groupBy('flow_id')
            ->get()
            ->keyBy('flow_id');

        // Кредити: task_run subject=FlowRun→flow; generation subject=AssistantTask→flow или Flow директно
        $creditsByFlow = $this->creditsByFlow($company, $filters, $flowIds);

        // Flow имена
        $flows = Flow::where('company_id', $company->id)
            ->whereIn('id', $flowIds)
            ->get(['id', 'name'])
            ->keyBy('id');

        $result = [];
        foreach ($flowIds as $flowId) {
            $llm = $llmRows[$flowId];
            $result[] = [
                'flow_id' => $flowId,
                'flow_name' => $flows[$flowId]?->name ?? "Flow #{$flowId}",
                'run_count' => (int) ($runStats[$flowId]?->run_count ?? 0),
                'call_count' => (int) $llm->call_count,
                'tokens' => (int) $llm->tokens,
                'cost_usd' => (float) $llm->cost_usd,
                'spent_credits' => $creditsByFlow[$flowId] ?? 0,
                'last_at' => $llm->last_request_at,
            ];
        }

        // Сортиране
        $sortKey = in_array($sort, ['cost_usd', 'run_count', 'call_count', 'tokens', 'credits']) ? ($sort === 'credits' ? 'spent_credits' : $sort) : 'cost_usd';
        usort($result, fn ($a, $b) => $dir === 'asc' ? ($a[$sortKey] <=> $b[$sortKey]) : ($b[$sortKey] <=> $a[$sortKey]));

        $total = count($result);
        $paged = array_slice($result, ($page - 1) * $limit, $limit);

        $summary = [
            'run_count' => array_sum(array_column($result, 'run_count')),
            'cost_usd' => round(array_sum(array_column($result, 'cost_usd')), 4),
            'spent_credits' => array_sum(array_column($result, 'spent_credits')),
        ];

        return ['rows' => $paged, 'total' => $total, 'summary' => $summary];
    }

    /**
     * Кредити по flow_id — дерайва от credit_reservations.subject:
     * - task_run: subject_type=FlowRun → join flows
     * - generation: subject_type=AssistantTask → join assistant_tasks.flow_id; или subject_type=Flow директно
     *
     * @param  array<int>  $flowIds
     * @return array<int, int>
     */
    private function creditsByFlow(Company $company, array $filters, array $flowIds): array
    {
        if (empty($flowIds)) {
            return [];
        }

        $result = [];

        // task_run: subject=FlowRun
        $taskRunCredits = CreditReservation::where('credit_reservations.company_id', $company->id)
            ->where('credit_reservations.context_type', 'task_run')
            ->where('credit_reservations.subject_type', 'App\\Models\\FlowRun')
            ->join('flow_runs', 'flow_runs.id', '=', 'credit_reservations.subject_id')
            ->whereIn('flow_runs.flow_id', $flowIds)
            ->selectRaw('flow_runs.flow_id, COALESCE(SUM(credit_reservations.spent_credits), 0) as credits')
            ->groupBy('flow_runs.flow_id')
            ->pluck('credits', 'flow_id');

        foreach ($taskRunCredits as $fid => $credits) {
            $result[$fid] = ($result[$fid] ?? 0) + (int) $credits;
        }

        // generation: subject=AssistantTask → assistant_tasks.flow_id
        $genTaskCredits = CreditReservation::where('credit_reservations.company_id', $company->id)
            ->where('credit_reservations.context_type', 'generation')
            ->where('credit_reservations.subject_type', 'App\\Models\\AssistantTask')
            ->join('assistant_tasks', 'assistant_tasks.id', '=', 'credit_reservations.subject_id')
            ->whereIn('assistant_tasks.flow_id', $flowIds)
            ->selectRaw('assistant_tasks.flow_id, COALESCE(SUM(credit_reservations.spent_credits), 0) as credits')
            ->groupBy('assistant_tasks.flow_id')
            ->pluck('credits', 'flow_id');

        foreach ($genTaskCredits as $fid => $credits) {
            $result[$fid] = ($result[$fid] ?? 0) + (int) $credits;
        }

        // generation: subject=Flow директно
        $genFlowCredits = CreditReservation::where('credit_reservations.company_id', $company->id)
            ->where('credit_reservations.context_type', 'generation')
            ->where('credit_reservations.subject_type', 'App\\Models\\Flow')
            ->whereIn('credit_reservations.subject_id', $flowIds)
            ->selectRaw('credit_reservations.subject_id as flow_id, COALESCE(SUM(credit_reservations.spent_credits), 0) as credits')
            ->groupBy('credit_reservations.subject_id')
            ->pluck('credits', 'flow_id');

        foreach ($genFlowCredits as $fid => $credits) {
            $result[$fid] = ($result[$fid] ?? 0) + (int) $credits;
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Org breakdown
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Организационен разбивка: общо org разходи + по член + по задача.
     */
    public function orgBreakdown(Company $company, array $filters, int $page, int $limit, string $sort, string $dir): array
    {
        $orgContexts = ['org_planning', 'org_digest', 'director_tick', 'member_chat', 'interview', 'research'];

        // По member (subject=OrgMember)
        $memberRows = CreditReservation::where('credit_reservations.company_id', $company->id)
            ->whereIn('credit_reservations.context_type', $orgContexts)
            ->where('credit_reservations.subject_type', 'App\\Models\\OrgMember')
            ->selectRaw('credit_reservations.subject_id as member_id, credit_reservations.context_type, COALESCE(SUM(credit_reservations.spent_credits), 0) as credits')
            ->groupBy('credit_reservations.subject_id', 'credit_reservations.context_type')
            ->get();

        $memberIds = $memberRows->pluck('member_id')->unique()->all();
        $members = OrgMember::whereIn('id', $memberIds)->get(['id', 'display_name', 'kind'])->keyBy('id');

        $byMember = [];
        foreach ($memberRows as $row) {
            $mid = $row->member_id;
            if (! isset($byMember[$mid])) {
                $m = $members[$mid] ?? null;
                $byMember[$mid] = [
                    'member_id' => $mid,
                    'member_name' => $m?->display_name ?? "Member #{$mid}",
                    'member_kind' => $m?->kind ?? '—',
                    'spent_credits' => 0,
                    'by_context' => [],
                ];
            }
            $byMember[$mid]['spent_credits'] += (int) $row->credits;
            $byMember[$mid]['by_context'][$row->context_type] = (int) $row->credits;
        }

        // USD по member від llm_requests (context + subject)
        $llmByMember = $this->llmBase($company, $filters)
            ->whereIn('context_type', $orgContexts)
            ->where('subject_type', 'App\\Models\\OrgMember')
            ->selectRaw('subject_id as member_id, ROUND(SUM(cost_usd), 4) as cost_usd')
            ->groupBy('subject_id')
            ->pluck('cost_usd', 'member_id');

        foreach ($byMember as &$m) {
            $m['cost_usd'] = (float) ($llmByMember[$m['member_id']] ?? 0);
        }
        unset($m);

        $result = array_values($byMember);
        usort($result, fn ($a, $b) => $dir === 'asc' ? ($a['spent_credits'] <=> $b['spent_credits']) : ($b['spent_credits'] <=> $a['spent_credits']));

        $total = count($result);
        $paged = array_slice($result, ($page - 1) * $limit, $limit);

        $orgTotal = [
            'spent_credits' => array_sum(array_column($result, 'spent_credits')),
            'cost_usd' => round(array_sum(array_column($result, 'cost_usd')), 4),
        ];

        return ['rows' => $paged, 'total' => $total, 'summary' => $orgTotal];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Credit history
    // ─────────────────────────────────────────────────────────────────────

    /**
     * История на кредитите — ЕДИН ред на операция (резервация), плюс самостоятелните
     * зареждания/грантове. Вместо 3 ledger реда (reserve/settle/refund) показваме обобщения
     * ред „Похарчено N кр."; разбивката (резервирано/похарчено/върнато) е в drawer-а при клик.
     * „Баланс след" се ИЗЧИСЛЯВА (running balance, анкер = текущ баланс) → попълнен е за всеки
     * ред, вкл. стари записи без wallet_balance_after.
     */
    public function creditHistory(Company $company, array $filters, int $page, int $limit, string $sort, string $dir): array
    {
        $from = ($filters['from'] ?? null) ? $this->dayStart($filters['from']) : null;
        $to = ($filters['to'] ?? null) ? $this->dayEnd($filters['to']) : null;

        // Източник 1 — резервации (всяка = 1 операция).
        $resQ = CreditReservation::where('company_id', $company->id);
        if ($from) {
            $resQ->where('created_at', '>=', $from);
        }
        if ($to) {
            $resQ->where('created_at', '<=', $to);
        }
        $reservations = $resQ->get([
            'id', 'context_type', 'model_level', 'origin', 'subject_type', 'subject_id',
            'estimated_credits', 'spent_credits', 'status', 'outcome', 'settled_at', 'refunded_at', 'created_at',
        ]);

        // Източник 2 — самостоятелни ledger редове (зареждане/грант, без резервация).
        $ledQ = CreditLedgerEntry::where('company_id', $company->id)
            ->whereNull('reservation_id')
            ->whereIn('type', ['topup', 'grant']);
        if ($from) {
            $ledQ->where('created_at', '>=', $from);
        }
        if ($to) {
            $ledQ->where('created_at', '<=', $to);
        }
        $standalone = $ledQ->get(['id', 'type', 'origin', 'amount', 'reason', 'created_at']);

        // Batch: subject етикети + реален USD по резервация.
        $subjectLabels = BillingSubjectLabeler::labels(
            $reservations->map(fn ($r) => [$r->subject_type, (int) $r->subject_id])->all()
        );
        $resIds = $reservations->pluck('id')->all();
        $costByRes = $resIds
            ? LlmRequest::whereIn('reservation_id', $resIds)
                ->selectRaw('reservation_id, ROUND(SUM(cost_usd), 4) as cost')
                ->groupBy('reservation_id')
                ->pluck('cost', 'reservation_id')
            : collect();

        // Унифициран списък. `net` = ефект върху портфейла (за running balance).
        $items = [];
        foreach ($reservations as $r) {
            $spent = (int) $r->spent_credits;
            $estimated = (int) $r->estimated_credits;
            $open = $r->status === 'reserved';
            $held = $open ? $estimated : $spent;           // колко реално е свалено от портфейла
            $tsRaw = $r->settled_at ?? $r->refunded_at ?? $r->created_at;
            $subjectKey = $r->subject_type ? class_basename($r->subject_type).'#'.$r->subject_id : null;

            $typeLabel = $open ? 'Резервирано' : (($spent > 0) ? 'Похарчено' : 'Върнато');

            $items[] = [
                'ts' => $tsRaw?->getTimestamp() ?? 0,
                'net' => -$held,                            // дебит към портфейла
                'row' => [
                    'id' => 'res-'.$r->id,
                    'created_at' => $tsRaw?->format('Y-m-d H:i:s'),
                    'type' => 'reservation',
                    'type_label' => $typeLabel,
                    'direction' => 'debit',
                    'amount' => $held,
                    'reserved' => $estimated,
                    'spent' => $spent,
                    'refunded' => $open ? 0 : max(0, $estimated - $spent),
                    'origin' => $r->origin,
                    'origin_label' => StatsLabels::origin($r->origin),
                    'context_type' => $r->context_type,
                    'service_label' => StatsLabels::label($r->context_type),
                    'subject_label' => $subjectKey ? ($subjectLabels[$subjectKey] ?? null) : null,
                    'reservation_id' => $r->id,
                    'status' => $r->status,
                    'outcome' => $r->outcome,
                    'cost_usd' => (float) ($costByRes[$r->id] ?? 0),
                ],
            ];
        }
        foreach ($standalone as $e) {
            $items[] = [
                'ts' => $e->created_at?->getTimestamp() ?? 0,
                'net' => (int) $e->amount,                  // кредит към портфейла
                'row' => [
                    'id' => 'led-'.$e->id,
                    'created_at' => $e->created_at?->format('Y-m-d H:i:s'),
                    'type' => $e->type,
                    'type_label' => StatsLabels::ledgerType($e->type),
                    'direction' => 'credit',
                    'amount' => (int) $e->amount,
                    'reserved' => null, 'spent' => null, 'refunded' => null,
                    'origin' => $e->origin,
                    'origin_label' => StatsLabels::origin($e->origin),
                    'context_type' => null,
                    'service_label' => StatsLabels::ledgerType($e->type),
                    'subject_label' => null,
                    'reservation_id' => null,
                    'status' => null, 'outcome' => null,
                    'cost_usd' => null,
                ],
            ];
        }

        // Канонично newest-first, после running balance (анкер = текущ баланс).
        usort($items, fn ($a, $b) => $b['ts'] <=> $a['ts'] ?: ($b['row']['id'] <=> $a['row']['id']));
        $balance = (int) (CreditWallet::where('company_id', $company->id)->value('balance') ?? 0);
        $cumulative = 0;
        foreach ($items as &$it) {
            $it['row']['wallet_balance_after'] = $balance - $cumulative;   // балансът СЛЕД тази операция
            $cumulative += $it['net'];
        }
        unset($it);

        if ($dir === 'asc') {
            $items = array_reverse($items);
        }

        $total = count($items);
        $paged = array_slice($items, ($page - 1) * $limit, $limit);

        return [
            'rows' => array_map(fn ($it) => $it['row'], $paged),
            'total' => $total,
            'summary' => $this->creditSummary($company, $filters),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Unbilled
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Нетаксувани llm_requests: company_id=this AND reservation_id IS NULL,
     * групирани по operation_id → context_type/purpose/kind.
     */
    public function unbilledTable(Company $company, array $filters, int $page, int $limit, string $sort, string $dir): array
    {
        $base = $this->llmBase($company, $filters)->whereNull('reservation_id');

        $sub = (clone $base)
            ->selectRaw('
                COALESCE(operation_id, CAST(id AS CHAR)) as op_key,
                COALESCE(context_type, purpose, kind, ?) as service_key,
                context_type,
                purpose,
                kind,
                COUNT(*) as call_count,
                COALESCE(SUM(total_tokens), 0) as tokens,
                ROUND(SUM(cost_usd), 4) as cost_usd,
                MAX(llm_requests.created_at) as last_at
            ', ['other'])
            ->groupBy('op_key', 'context_type', 'purpose', 'kind');

        $outer = DB::query()->fromSub($sub, 'u');
        $total = (int) (clone $outer)->count();

        $validSort = ['cost_usd', 'call_count', 'tokens', 'last_at'];
        $sortCol = in_array($sort, $validSort) ? $sort : 'last_at';
        $rows = $outer->orderBy($sortCol, $dir)->limit($limit)->offset(($page - 1) * $limit)->get();

        $markup = (float) config('billing.credit_markup', 3.0);

        $mapped = collect($rows)->map(function ($row) use ($markup) {
            $svc = StatsLabels::service($row->context_type ?? null, $row->purpose ?? null);

            return [
                'op_key' => $row->op_key,
                'service_key' => $row->service_key,
                'service_label' => $svc['label'],
                'context_type' => $row->context_type,
                'purpose' => $row->purpose,
                'kind' => $row->kind,
                'call_count' => (int) $row->call_count,
                'tokens' => (int) $row->tokens,
                'cost_usd' => (float) $row->cost_usd,
                'est_credits' => $markup > 0 ? (int) ceil($row->cost_usd * $markup) : 0,
                'last_at' => $row->last_at,
            ];
        })->values()->all();

        $summary = [
            'cost_usd' => round((float) (clone $base)->sum('cost_usd'), 4),
            'call_count' => (int) (clone $base)->count(),
        ];

        return ['rows' => $mapped, 'total' => $total, 'summary' => $summary];
    }

    // ─────────────────────────────────────────────────────────────────────
    // External / Knowledge / OCR tables
    // ─────────────────────────────────────────────────────────────────────

    /** Esterni API (perplexity/brave/google_places) за тази фирма. */
    public function externalTable(Company $company, array $filters, int $page, int $limit, string $sort, string $dir): array
    {
        $base = $this->llmBase($company, $filters)
            ->whereIn('llm_requests.provider', self::EXTERNAL_PROVIDERS);

        $total = (int) (clone $base)->count();
        $col = in_array($sort, self::SORTABLE) ? $sort : 'created_at';

        $rows = (clone $base)
            ->orderBy("llm_requests.{$col}", $dir)
            ->limit($limit)->offset(($page - 1) * $limit)
            ->get(['id', 'created_at', 'provider', 'kind', 'cost_usd', 'duration_ms', 'status', 'user_message', 'context_type', 'reservation_id']);

        $markup = (float) config('billing.credit_markup', 3.0);
        $flatCosts = config('billing.flat_costs', []);

        $mapped = $rows->map(function (LlmRequest $req) use ($markup, $flatCosts) {
            $flatKey = match ($req->provider) {
                'brave' => 'brave_search',
                'google_places' => 'places',
                'perplexity' => 'perplexity',
                default => null,
            };
            $credits = $flatKey && isset($flatCosts[$flatKey]) ? (int) $flatCosts[$flatKey] : (int) ceil((float) $req->cost_usd * $markup);

            return [
                'id' => $req->id,
                'created_at' => $req->created_at?->format('Y-m-d H:i:s'),
                'provider' => $req->provider,
                'provider_label' => StatsLabels::externalName($req->provider ?? ''),
                'kind' => $req->kind,
                'query' => mb_substr((string) $req->user_message, 0, 120),
                'cost_usd' => (float) $req->cost_usd,
                'credits' => $credits,
                'duration_ms' => (int) $req->duration_ms,
                'status' => $req->status,
                'reservation_id' => $req->reservation_id,
            ];
        })->values()->all();

        $summary = [
            'total_cost' => round((float) (clone $base)->sum('cost_usd'), 4),
            'call_count' => $total,
        ];

        return ['rows' => $mapped, 'total' => $total, 'summary' => $summary];
    }

    /** Knowledge/Embeddings за тази фирма. */
    public function knowledgeTable(Company $company, array $filters, int $page, int $limit, string $sort, string $dir): array
    {
        $base = $this->llmBase($company, $filters)
            ->whereIn('purpose', self::KNOWLEDGE_PURPOSES);

        $total = (int) (clone $base)->count();
        $col = in_array($sort, self::SORTABLE) ? $sort : 'created_at';

        $rows = (clone $base)
            ->orderBy("llm_requests.{$col}", $dir)
            ->limit($limit)->offset(($page - 1) * $limit)
            ->get(['id', 'created_at', 'purpose', 'provider', 'model', 'total_tokens', 'cost_usd', 'duration_ms', 'status']);

        $mapped = $rows->map(fn (LlmRequest $req) => [
            'id' => $req->id,
            'created_at' => $req->created_at?->format('Y-m-d H:i:s'),
            'purpose' => $req->purpose,
            'purpose_label' => StatsLabels::label(null, $req->purpose),
            'provider' => $req->provider,
            'model' => $req->model,
            'tokens' => (int) $req->total_tokens,
            'cost_usd' => (float) $req->cost_usd,
            'duration_ms' => (int) $req->duration_ms,
            'status' => $req->status,
        ])->values()->all();

        $summary = ['total_cost' => round((float) (clone $base)->sum('cost_usd'), 4), 'call_count' => $total];

        return ['rows' => $mapped, 'total' => $total, 'summary' => $summary];
    }

    /** OCR (Mistral) за тази фирма. */
    public function ocrTable(Company $company, array $filters, int $page, int $limit, string $sort, string $dir): array
    {
        $base = $this->llmBase($company, $filters)
            ->where('llm_requests.provider', self::OCR_PROVIDER)
            ->where('llm_requests.kind', self::OCR_KIND);

        $total = (int) (clone $base)->count();
        $col = in_array($sort, self::SORTABLE) ? $sort : 'created_at';

        $rows = (clone $base)
            ->orderBy("llm_requests.{$col}", $dir)
            ->limit($limit)->offset(($page - 1) * $limit)
            ->get(['id', 'created_at', 'model', 'user_message', 'cost_usd', 'duration_ms', 'status', 'options']);

        $mapped = $rows->map(fn (LlmRequest $req) => [
            'id' => $req->id,
            'created_at' => $req->created_at?->format('Y-m-d H:i:s'),
            'model' => $req->model,
            'document' => mb_substr((string) $req->user_message, 0, 160),
            'pages' => (int) ($req->options['pages'] ?? 0),
            'cost_usd' => (float) $req->cost_usd,
            'duration_ms' => (int) $req->duration_ms,
            'status' => $req->status,
        ])->values()->all();

        $pages = (int) (clone $base)->get(['options'])->sum(fn ($r) => (int) ($r->options['pages'] ?? 0));
        $summary = ['total_cost' => round((float) (clone $base)->sum('cost_usd'), 4), 'documents' => $total, 'pages' => $pages];

        return ['rows' => $mapped, 'total' => $total, 'summary' => $summary];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Main grid (UNION: gen sessions + flow runs)
    // ─────────────────────────────────────────────────────────────────────

    /** Суров групиран UNION (gen-сесии + flow-runs), company-scoped. */
    public function grid(Company $company, array $filters, int $page, int $limit, string $sort, string $dir): array
    {
        $cols = 'COUNT(*) as call_count, '
            ."GROUP_CONCAT(DISTINCT provider ORDER BY provider SEPARATOR ', ') as provider, "
            ."GROUP_CONCAT(DISTINCT model ORDER BY model SEPARATOR ', ') as model, "
            .'COALESCE(SUM(prompt_tokens),0) as prompt_tokens, '
            .'COALESCE(SUM(completion_tokens),0) as completion_tokens, '
            .'ROUND(SUM(cost_usd),4) as cost_usd, '
            .'COALESCE(SUM(duration_ms),0) as duration_ms, '
            .'MAX(created_at) as created_at, '
            .'MIN(status) as status';

        $nonGenPurposes = ['assistant', 'embedding', 'knowledge_synthesis', 'knowledge_fact_harvest', 'knowledge_chat', 'knowledge_ocr'];

        $gen = $this->llmBase($company, $filters)
            ->whereNull('flow_run_id')
            ->whereNotNull('session_id')
            ->where(fn ($q) => $q->whereNull('purpose')->orWhereNotIn('purpose', $nonGenPurposes))
            ->selectRaw("'gen' as row_type, session_id as gkey, MAX(flow_id) as flow_id, {$cols}")
            ->groupBy('session_id');

        $run = $this->llmBase($company, $filters)
            ->whereNotNull('flow_run_id')
            ->selectRaw("'run' as row_type, CAST(flow_run_id AS CHAR) as gkey, flow_id, {$cols}")
            ->groupByRaw('flow_run_id, flow_id');

        $outer = DB::query()->fromSub($gen->unionAll($run), 'g');
        $total = (int) (clone $outer)->count();

        $validGridSort = ['created_at', 'cost_usd', 'duration_ms', 'call_count'];
        $col = in_array($sort, $validGridSort) ? $sort : 'created_at';

        $rows = collect($outer->orderBy($col, $dir)->limit($limit)->offset(($page - 1) * $limit)->get());

        $flowNames = Flow::where('company_id', $company->id)
            ->whereIn('id', $rows->pluck('flow_id')->filter()->unique())
            ->pluck('name', 'id');

        $mapped = $rows->map(function ($row) use ($flowNames) {
            $isGen = $row->row_type === 'gen';

            return [
                'row_type' => $row->row_type,
                'group_key' => ($isGen ? 'gen:' : 'run:').$row->gkey,
                'created_at' => $row->created_at ? Carbon::parse($row->created_at)->format('Y-m-d H:i:s') : null,
                'provider' => $row->provider,
                'model' => $row->model,
                'flow' => $flowNames[$row->flow_id] ?? '—',
                'call_count' => (int) $row->call_count,
                'prompt_tokens' => (int) $row->prompt_tokens ?: null,
                'completion_tokens' => (int) $row->completion_tokens ?: null,
                'cost_usd' => (float) $row->cost_usd,
                'duration_ms' => (int) $row->duration_ms,
                'status' => $row->status,
            ];
        })->values()->all();

        return ['rows' => $mapped, 'total' => $total];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Drill-downs
    // ─────────────────────────────────────────────────────────────────────

    /** Детайл за един llm_request (ако company_id съвпада). */
    public function requestDetail(Company $company, int $id): ?array
    {
        $req = LlmRequest::where('company_id', $company->id)
            ->with(['flow:id,name', 'nodeRun:id,node_key'])
            ->find($id);

        if (! $req) {
            return null;
        }

        return [
            'id' => $req->id,
            'created_at' => $req->created_at?->format('Y-m-d H:i:s'),
            'provider' => $req->provider,
            'model' => $req->model,
            'kind' => $req->kind,
            'purpose' => $req->purpose,
            'context_type' => $req->context_type,
            'flow' => $req->flow?->name,
            'flow_run_id' => $req->flow_run_id,
            'node_run_id' => $req->node_run_id,
            'node_key' => $req->nodeRun?->node_key,
            'prompt_tokens' => $req->prompt_tokens,
            'completion_tokens' => $req->completion_tokens,
            'total_tokens' => $req->total_tokens,
            'cost_usd' => (float) $req->cost_usd,
            'duration_ms' => $req->duration_ms,
            'status' => $req->status,
            'error' => $req->error,
            'options' => $req->options,
            'system_prompt' => $req->system_prompt,
            'user_message' => $req->user_message,
            'response_text' => $req->response_text,
            'reservation_id' => $req->reservation_id,
        ];
    }

    /**
     * Групов детайл: run:{flow_run_id} или gen:{session_id}, company-guarded.
     */
    public function groupDetail(Company $company, string $key): array
    {
        $meta = null;

        if (str_starts_with($key, 'run:')) {
            $flowRunId = (int) substr($key, 4);

            // Сигурност: verify company_id чрез flow
            $run = FlowRun::with('flow:id,name,company_id')->find($flowRunId);
            if (! $run || $run->flow?->company_id !== $company->id) {
                return ['meta' => null, 'rows' => [], 'error' => 'not_found'];
            }

            $rows = LlmRequest::where('flow_run_id', $flowRunId)
                ->where('company_id', $company->id)
                ->orderBy('created_at')
                ->get(['id', 'created_at', 'provider', 'model', 'purpose', 'agent_name', 'agent_type',
                    'prompt_tokens', 'completion_tokens', 'cost_usd', 'duration_ms', 'status']);

            $agg = LlmRequest::where('flow_run_id', $flowRunId)
                ->selectRaw('COUNT(*) c, ROUND(SUM(cost_usd),4) cost, COALESCE(SUM(total_tokens),0) tokens, COALESCE(SUM(duration_ms),0) dur')
                ->first();

            $meta = [
                'flow' => $run->flow?->name,
                'flow_run_id' => $flowRunId,
                'status' => $run->status,
                'started_at' => $run->started_at?->format('Y-m-d H:i:s'),
                'completed_at' => $run->completed_at?->format('Y-m-d H:i:s'),
                'agents' => (int) ($agg->c ?? 0),
                'cost_usd' => (float) ($agg->cost ?? 0),
                'tokens' => (int) ($agg->tokens ?? 0),
                'duration_ms' => (int) ($agg->dur ?? 0),
            ];
        } elseif (str_starts_with($key, 'gen:')) {
            $sessionId = substr($key, 4);

            $rows = LlmRequest::where('session_id', $sessionId)
                ->where('company_id', $company->id)
                ->whereNull('flow_run_id')
                ->orderBy('created_at')
                ->get(['id', 'created_at', 'provider', 'model', 'purpose', 'agent_name', 'agent_type',
                    'prompt_tokens', 'completion_tokens', 'cost_usd', 'duration_ms', 'status']);
        } else {
            return ['meta' => null, 'rows' => []];
        }

        return [
            'meta' => $meta,
            'rows' => isset($rows) ? $rows->map(fn (LlmRequest $req) => [
                'id' => $req->id,
                'created_at' => $req->created_at?->format('Y-m-d H:i:s'),
                'provider' => $req->provider,
                'model' => $req->model,
                'purpose' => $req->purpose,
                'agent' => $req->agent_name
                    ? $req->agent_name.($req->agent_type ? ' ('.$req->agent_type.')' : '')
                    : '—',
                'prompt_tokens' => $req->prompt_tokens,
                'completion_tokens' => $req->completion_tokens,
                'cost_usd' => (float) $req->cost_usd,
                'duration_ms' => $req->duration_ms,
                'status' => $req->status,
            ])->values()->all() : [],
        ];
    }

    /**
     * Детайл на резервация: хедър + ledger редове + llm_requests.
     * Задължително проверява company_id.
     */
    public function reservationDetail(Company $company, int $reservationId): ?array
    {
        $res = CreditReservation::where('company_id', $company->id)->find($reservationId);
        if (! $res) {
            return null;
        }

        $subjectLabel = BillingSubjectLabeler::label($res->subject_type, (int) $res->subject_id);

        $ledger = CreditLedgerEntry::where('reservation_id', $reservationId)
            ->orderBy('created_at')
            ->get(['id', 'type', 'origin', 'direction', 'amount', 'wallet_balance_after', 'reason', 'created_at']);

        $requests = LlmRequest::where('reservation_id', $reservationId)
            ->orderBy('created_at')
            ->get(['id', 'created_at', 'provider', 'model', 'purpose', 'prompt_tokens', 'completion_tokens', 'cost_usd', 'duration_ms', 'status']);

        return [
            'reservation' => [
                'id' => $res->id,
                'context_type' => $res->context_type,
                'context_label' => StatsLabels::label($res->context_type),
                'status' => $res->status,
                'outcome' => $res->outcome,
                'origin' => $res->origin,
                'origin_label' => StatsLabels::origin($res->origin),
                'model_level' => $res->model_level,
                'estimated_credits' => (int) $res->estimated_credits,
                'spent_credits' => (int) $res->spent_credits,
                'subject_label' => $subjectLabel,
                'billing_meta' => $res->billing_meta,
                'settled_at' => $res->settled_at?->format('Y-m-d H:i:s'),
                'refunded_at' => $res->refunded_at?->format('Y-m-d H:i:s'),
                'failed_at' => $res->failed_at?->format('Y-m-d H:i:s'),
                'created_at' => $res->created_at?->format('Y-m-d H:i:s'),
            ],
            'ledger' => $ledger->map(fn (CreditLedgerEntry $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'type_label' => StatsLabels::ledgerType($e->type),
                'origin' => $e->origin,
                'direction' => $e->direction,
                'amount' => (int) $e->amount,
                'wallet_balance_after' => $e->wallet_balance_after !== null ? (int) $e->wallet_balance_after : null,
                'reason' => $e->reason,
                'created_at' => $e->created_at?->format('Y-m-d H:i:s'),
            ])->values()->all(),
            'requests' => $requests->map(fn (LlmRequest $req) => [
                'id' => $req->id,
                'created_at' => $req->created_at?->format('Y-m-d H:i:s'),
                'provider' => $req->provider,
                'model' => $req->model,
                'purpose' => $req->purpose,
                'prompt_tokens' => $req->prompt_tokens,
                'completion_tokens' => $req->completion_tokens,
                'cost_usd' => (float) $req->cost_usd,
                'duration_ms' => $req->duration_ms,
                'status' => $req->status,
            ])->values()->all(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Filter options (scoped to this company)
    // ─────────────────────────────────────────────────────────────────────

    /** Dropdown опции, само тези, намерени в данните на тази фирма. */
    public function filterOptions(Company $company): array
    {
        $providers = LlmRequest::where('company_id', $company->id)
            ->distinct()->pluck('provider')->filter()->sort()->values();

        $contextTypes = LlmRequest::where('company_id', $company->id)
            ->whereNotNull('context_type')->distinct()->pluck('context_type')->sort()->values();

        $flows = Flow::where('company_id', $company->id)->orderBy('name')->get(['id', 'name']);

        return [
            'providers' => $providers,
            'context_types' => $contextTypes,
            'flows' => $flows,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Timezone helpers
    // ─────────────────────────────────────────────────────────────────────

    /** UTC начало на деня (в app timezone). */
    private function dayStart(string $date): string
    {
        $tz = config('app.timezone', 'UTC');

        return Carbon::parse($date, $tz)->startOfDay()->utc()->toDateTimeString();
    }

    /** UTC край на деня (в app timezone). */
    private function dayEnd(string $date): string
    {
        $tz = config('app.timezone', 'UTC');

        return Carbon::parse($date, $tz)->endOfDay()->utc()->toDateTimeString();
    }

    /**
     * Timezone offset string за CONVERT_TZ (напр. "+02:00").
     * Ако app.timezone е стандартен IANA tz (Europ/Sofia), изчислява реалния offset.
     */
    private function tzOffset(): string
    {
        $tz = config('app.timezone', 'UTC');
        if ($tz === 'UTC') {
            return '+00:00';
        }
        $offset = now($tz)->getTimezone()->getOffset(new \DateTime('now', new \DateTimeZone('UTC')));
        $sign = $offset >= 0 ? '+' : '-';
        $abs = abs($offset);
        $h = intdiv($abs, 3600);
        $m = intdiv($abs % 3600, 60);

        return sprintf('%s%02d:%02d', $sign, $h, $m);
    }

    /**
     * Дата-диапазон за charts: ако не е зададен в филтрите, взима от данните.
     * Без явен филтър винаги показва поне 30 дни (нули за дни без разход).
     * Ограничен до 370 дни.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function dateRange(Company $company, array $filters): array
    {
        $tz = config('app.timezone', 'UTC');
        $hasExplicitRange = ($filters['from'] ?? null) || ($filters['to'] ?? null);

        $from = ($filters['from'] ?? null)
            ? Carbon::parse($filters['from'], $tz)->startOfDay()->utc()
            : null;
        $to = ($filters['to'] ?? null)
            ? Carbon::parse($filters['to'], $tz)->endOfDay()->utc()
            : null;

        if (! $from || ! $to) {
            $base = $this->llmBase($company, $filters);
            $min = $base->min('llm_requests.created_at');
            $max = $base->max('llm_requests.created_at');
            $from = $from ?? ($min ? Carbon::parse($min)->startOfDay() : now()->subDays(29)->startOfDay());
            $to = $to ?? ($max ? Carbon::parse($max)->endOfDay() : now()->endOfDay());
        }

        if (! $hasExplicitRange && $from->diffInDays($to) + 1 < 30) {
            $from = $to->copy()->subDays(29)->startOfDay();
        }

        if (abs($from->diffInDays($to)) > 370) {
            $from = $to->copy()->subDays(369)->startOfDay();
        }

        return [$from, $to];
    }

    /**
     * Запълва масив с всички дни между from и to.
     *
     * @return array<string, mixed>
     */
    private function fillDays(Carbon $from, Carbon $to, mixed $emptyVal): array
    {
        $days = [];
        $tz = config('app.timezone', 'UTC');
        $cur = $from->copy()->setTimezone($tz)->startOfDay();
        $end = $to->copy()->setTimezone($tz)->endOfDay();

        while ($cur <= $end) {
            $days[$cur->format('Y-m-d')] = $emptyVal;
            $cur->addDay();
        }

        return $days;
    }
}
