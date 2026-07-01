<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\CompanyStatsQueryService;
use App\Support\StatsLabels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-company statistics — owner-facing AJAX dashboard.
 *
 * Тънък контролер: route-model-bound Company, is_admin guard,
 * делегира всяка агрегация към CompanyStatsQueryService.
 *
 * Auth: is_admin middleware (прилага се в routes/web.php на групата).
 * Сигурност: всеки detail endpoint верифицира company_id и abort(404) при несъвпадение.
 */
class CompanyStatsController extends Controller
{
    /** Whitelist за sort колони (разширява CostController::SORTABLE). */
    private const SORTABLE = ['created_at', 'cost_usd', 'duration_ms', 'total_tokens', 'credits', 'amount', 'call_count'];

    public function __construct(private readonly CompanyStatsQueryService $stats) {}

    // ─────────────────────────────────────────────────────────────────────
    // Shell view
    // ─────────────────────────────────────────────────────────────────────

    /** Shell view — зарежда само скелета, данните идват lazy по AJAX. */
    public function index(Request $r, Company $company)
    {
        return view('companies.stats.index', [
            'company' => $company,
            'filterOptions' => $this->stats->filterOptions($company),
            'filters' => $r->only(['provider', 'context_type', 'from', 'to']),
            'statsLabels' => StatsLabels::forJs(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // AJAX data endpoints (един на таб/секция)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Преглед: summary карти + provider breakdown + charts + reconciliation.
     * Shape: { summary, providers, charts, reconciliation }
     */
    public function overview(Request $r, Company $company): JsonResponse
    {
        return response()->json(
            $this->stats->overview($company, $this->filters($r))
        );
    }

    /**
     * По услуга: { rows, total, summary }.
     * Shape per row: { service_key, label, icon, color, call_count, tokens, cost_usd, spent_credits, est_credits, credit_type }
     */
    public function services(Request $r, Company $company): JsonResponse
    {
        [$page, $limit, $sort, $dir] = $this->pagination($r);

        return response()->json(
            $this->stats->servicesTable($company, $this->filters($r), $page, $limit, $sort, $dir)
        );
    }

    /**
     * По flow: { rows, total, summary }.
     * Shape per row: { flow_id, flow_name, run_count, call_count, tokens, cost_usd, spent_credits, last_at }
     */
    public function flows(Request $r, Company $company): JsonResponse
    {
        [$page, $limit, $sort, $dir] = $this->pagination($r);

        return response()->json(
            $this->stats->flowsTable($company, $this->filters($r), $page, $limit, $sort, $dir)
        );
    }

    /**
     * Организационна разбивка: { rows, total, summary }.
     * Shape per row: { member_id, member_name, member_kind, spent_credits, cost_usd, by_context }
     */
    public function org(Request $r, Company $company): JsonResponse
    {
        [$page, $limit, $sort, $dir] = $this->pagination($r);

        return response()->json(
            $this->stats->orgBreakdown($company, $this->filters($r), $page, $limit, $sort, $dir)
        );
    }

    /**
     * История на кредитите: { rows, total, summary }.
     * Shape per row: { id, created_at, type, type_label, direction, amount, wallet_balance_after,
     *   reason, origin, origin_label, context_type, service_label, subject_label,
     *   reservation_id, outcome, cost_usd }
     * summary: { granted, topped_up, spent, refunded, overage, balance }
     */
    public function credits(Request $r, Company $company): JsonResponse
    {
        [$page, $limit, $sort, $dir] = $this->pagination($r, 'created_at');

        return response()->json(
            $this->stats->creditHistory($company, $this->filters($r), $page, $limit, $sort, $dir)
        );
    }

    /**
     * Нетаксувани: llm_requests без reservation_id, групирани по operation_id.
     * Shape per row: { op_key, service_label, context_type, purpose, kind, call_count, tokens, cost_usd, est_credits, last_at }
     */
    public function unbilled(Request $r, Company $company): JsonResponse
    {
        [$page, $limit, $sort, $dir] = $this->pagination($r, 'last_at');

        return response()->json(
            $this->stats->unbilledTable($company, $this->filters($r), $page, $limit, $sort, $dir)
        );
    }

    /**
     * Външни API (perplexity/brave/google_places).
     * Shape per row: { id, created_at, provider, provider_label, kind, query, cost_usd, credits, duration_ms, status }
     */
    public function external(Request $r, Company $company): JsonResponse
    {
        [$page, $limit, $sort, $dir] = $this->pagination($r);

        return response()->json(
            $this->stats->externalTable($company, $this->filters($r), $page, $limit, $sort, $dir)
        );
    }

    /**
     * Knowledge/Embeddings.
     * Shape per row: { id, created_at, purpose, purpose_label, provider, model, tokens, cost_usd, duration_ms, status }
     */
    public function knowledge(Request $r, Company $company): JsonResponse
    {
        [$page, $limit, $sort, $dir] = $this->pagination($r);

        return response()->json(
            $this->stats->knowledgeTable($company, $this->filters($r), $page, $limit, $sort, $dir)
        );
    }

    /**
     * OCR (Mistral).
     * Shape per row: { id, created_at, model, document, pages, cost_usd, duration_ms, status }
     */
    public function ocr(Request $r, Company $company): JsonResponse
    {
        [$page, $limit, $sort, $dir] = $this->pagination($r);

        return response()->json(
            $this->stats->ocrTable($company, $this->filters($r), $page, $limit, $sort, $dir)
        );
    }

    /**
     * Суров групиран grid (gen-сесии + flow-runs).
     * Shape per row: { row_type, group_key, created_at, provider, model, flow,
     *   call_count, prompt_tokens, completion_tokens, cost_usd, duration_ms, status }
     */
    public function grid(Request $r, Company $company): JsonResponse
    {
        [$page, $limit, $sort, $dir] = $this->pagination($r);

        return response()->json(
            $this->stats->grid($company, $this->filters($r), $page, $limit, $sort, $dir)
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Drill-downs
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Детайл за един llm_request.
     * company_id проверка — abort(404) ако редът не е на тази фирма.
     */
    public function show(Request $r, Company $company): JsonResponse
    {
        $id = (int) $r->query('id');
        $data = $this->stats->requestDetail($company, $id);

        if (! $data) {
            abort(404);
        }

        return response()->json($data);
    }

    /**
     * Групов детайл: run:{flow_run_id} или gen:{session_id}.
     * company_id проверка вътре в service-а (abort(404) при несъвпадение).
     */
    public function groupDetail(Request $r, Company $company): JsonResponse
    {
        $key = (string) $r->query('key', '');
        $data = $this->stats->groupDetail($company, $key);

        if (isset($data['error']) && $data['error'] === 'not_found') {
            abort(404);
        }

        return response()->json($data);
    }

    /**
     * Детайл на резервация: хедър + ledger + llm_requests.
     * Abort(404) ако reservation не принадлежи на company.
     */
    public function reservationDetail(Request $r, Company $company): JsonResponse
    {
        $resId = (int) $r->query('id');
        $data = $this->stats->reservationDetail($company, $resId);

        if (! $data) {
            abort(404);
        }

        return response()->json($data);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /** Нормализирани филтри от заявката. */
    private function filters(Request $r): array
    {
        return [
            'provider' => $r->filled('provider') ? (string) $r->query('provider') : null,
            'context_type' => $r->filled('context_type') ? (string) $r->query('context_type') : null,
            'from' => $r->filled('from') ? (string) $r->query('from') : null,
            'to' => $r->filled('to') ? (string) $r->query('to') : null,
        ];
    }

    /**
     * Пагинация + сортиране (whitelisted).
     *
     * @return array{0: int, 1: int, 2: string, 3: string}
     */
    private function pagination(Request $r, string $defaultSort = 'created_at'): array
    {
        $page = max((int) $r->query('page', 1), 1);
        $limit = min(max((int) $r->query('limit', 25), 1), 200);
        $sort = (string) $r->query('sort', $defaultSort);
        if (! in_array($sort, self::SORTABLE, true)) {
            $sort = $defaultSort;
        }
        $dir = strtolower((string) $r->query('dir', '')) === 'asc' ? 'asc' : 'desc';

        return [$page, $limit, $sort, $dir];
    }
}
