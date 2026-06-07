<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Flow;
use App\Models\FlowRun;
use App\Models\LlmRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Admin "Разходи" — unified per-call audit of all LLM usage and cost.
 *
 * Single source of truth: llm_requests. Every call is logged there at the
 * provider chokepoints — paid OpenAI/Anthropic (real USD cost) AND free local
 * Ollama (cost 0). Powers summary cards, Chart.js charts, a grouped Grid.js
 * table (generation sessions + run sessions with drill-down) and a detail popup
 * (full prompt + model output per request). All filters apply globally to every
 * stat; cost-based charts naturally show paid only (Ollama is $0).
 */
class CostController extends Controller
{
    public function index(Request $r)
    {
        $totalCost = (float) $this->filtered($r)->sum('cost_usd');
        $paidRequests = (int) $this->filtered($r)->where('provider', '!=', 'ollama')->count();
        $ollamaRequests = (int) $this->filtered($r)->where('provider', 'ollama')->count();

        $summary = [
            'total_cost' => round($totalCost, 2),
            'month_cost' => round((float) $this->filtered($r)->where('created_at', '>=', now()->startOfMonth())->sum('cost_usd'), 2),
            'today_cost' => round((float) $this->filtered($r)->whereDate('created_at', today())->sum('cost_usd'), 2),
            'paid_tokens' => (int) $this->filtered($r)->where('provider', '!=', 'ollama')->sum('total_tokens'),
            'free_tokens' => (int) $this->filtered($r)->where('provider', 'ollama')->sum('total_tokens'),
            'total_requests' => $paidRequests + $ollamaRequests,
            'paid_requests' => $paidRequests,
            'ollama_requests' => $ollamaRequests,
            'openai_cost' => round((float) $this->filtered($r)->where('provider', 'openai')->sum('cost_usd'), 2),
            'anthropic_cost' => round((float) $this->filtered($r)->where('provider', 'anthropic')->sum('cost_usd'), 2),
            'avg_cost' => $paidRequests > 0 ? round($totalCost / $paidRequests, 2) : 0.0,
        ];

        $charts = [
            'spendByDay' => $this->spendByDay($r),
            'spendByProvider' => $this->costByColumn($r, 'provider'),
            'spendByModel' => $this->costByColumn($r, 'model', 8),
            'spendByCompany' => $this->spendByCompany($r),
            'volumeByProvider' => $this->volumeByProvider($r),
        ];

        return view('admin.costs.index', [
            'summary' => $summary,
            'charts' => $charts,
            'rows' => $this->gridRows($r),
            'filterOptions' => $this->filterOptions(),
            'filters' => $r->only(['provider', 'model', 'company_id', 'flow_id', 'status', 'from', 'to']),
        ]);
    }

    /** Full detail for the per-request popup (triggered from the sub-table). */
    public function show(Request $r)
    {
        $req = LlmRequest::with(['company:id,name', 'flow:id,name', 'nodeRun:id,node_key'])
            ->findOrFail((int) $r->query('id'));

        return response()->json([
            'id' => $req->id,
            'created_at' => $req->created_at?->format('Y-m-d H:i:s'),
            'provider' => $req->provider,
            'model' => $req->model,
            'kind' => $req->kind,
            'purpose' => $req->purpose,
            'company' => $req->company?->name,
            'flow' => $req->flow?->name,
            'flow_run_id' => $req->flow_run_id,
            'node_run_id' => $req->node_run_id,
            'node_key' => $req->nodeRun?->node_key,
            'agent_name' => $req->agent_name,
            'agent_type' => $req->agent_type,
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
        ]);
    }

    /**
     * Individual call rows within a grouped session, for the drill-down sub-table.
     *
     * group_key formats:
     *   run:{flow_run_id} — agents (node calls) in one flow execution; meta = run details
     *   gen:{session_id}  — planner phases of one "auto agent creator" run
     *
     * Returns { meta, rows }. meta is the run header (only for run:* keys).
     */
    public function groupDetail(Request $r): JsonResponse
    {
        $key = (string) $r->query('key', '');
        $meta = null;

        if (str_starts_with($key, 'run:')) {
            $flowRunId = (int) substr($key, 4);

            $rows = LlmRequest::where('flow_run_id', $flowRunId)
                ->orderBy('created_at')
                ->get(['id', 'created_at', 'provider', 'model', 'purpose', 'agent_name', 'agent_type',
                    'prompt_tokens', 'completion_tokens', 'cost_usd', 'duration_ms', 'status']);

            $run = FlowRun::with('flow:id,name,company_id')->find($flowRunId);
            $agg = LlmRequest::where('flow_run_id', $flowRunId)
                ->selectRaw('COUNT(*) c, COALESCE(SUM(cost_usd),0) cost, COALESCE(SUM(total_tokens),0) tokens, COALESCE(SUM(duration_ms),0) dur')
                ->first();

            $meta = [
                'flow' => $run?->flow?->name,
                'flow_run_id' => $flowRunId,
                'status' => $run?->status,
                'started_at' => $run?->started_at?->format('Y-m-d H:i:s'),
                'completed_at' => $run?->completed_at?->format('Y-m-d H:i:s'),
                'agents' => (int) ($agg->c ?? 0),
                'cost_usd' => round((float) ($agg->cost ?? 0), 6),
                'tokens' => (int) ($agg->tokens ?? 0),
                'duration_ms' => (int) ($agg->dur ?? 0),
            ];

        } elseif (str_starts_with($key, 'gen:')) {
            $sessionId = substr($key, 4);
            $rows = LlmRequest::where('session_id', $sessionId)
                ->whereNull('flow_run_id')
                ->orderBy('created_at')
                ->get(['id', 'created_at', 'provider', 'model', 'purpose', 'agent_name', 'agent_type',
                    'prompt_tokens', 'completion_tokens', 'cost_usd', 'duration_ms', 'status']);
        } else {
            return response()->json(['meta' => null, 'rows' => []]);
        }

        return response()->json([
            'meta' => $meta,
            'rows' => $rows->map(fn (LlmRequest $req) => [
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
            ])->values(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Internals
    // ──────────────────────────────────────────────────────────────────────

    /** A fresh llm_requests query with the request's filters applied (table-qualified). */
    private function filtered(Request $r): Builder
    {
        return LlmRequest::query()
            ->when($r->filled('provider'), fn ($q) => $q->where('llm_requests.provider', $r->provider))
            ->when($r->filled('model'), fn ($q) => $q->where('llm_requests.model', $r->model))
            ->when($r->filled('company_id'), fn ($q) => $q->where('llm_requests.company_id', $r->company_id))
            ->when($r->filled('flow_id'), fn ($q) => $q->where('llm_requests.flow_id', $r->flow_id))
            ->when($r->filled('status'), fn ($q) => $q->where('llm_requests.status', $r->status))
            ->when($r->filled('from'), fn ($q) => $q->whereDate('llm_requests.created_at', '>=', $r->from))
            ->when($r->filled('to'), fn ($q) => $q->whereDate('llm_requests.created_at', '<=', $r->to));
    }

    /**
     * Grouped rows for the main Grid.js table — one row per planning session
     * or flow execution (not per individual API call). Clicking a row opens a
     * sub-table with the individual calls via groupDetail().
     */
    private function gridRows(Request $r): array
    {
        // ── "Auto agent creator" sessions — one row per planner run ──
        // A planning session fires several phases that share the planner's
        // session token (stored in session_id); group on it, NOT on the flow.
        $genRows = $this->filtered($r)
            ->whereNull('flow_run_id')
            ->whereNotNull('session_id')
            ->selectRaw('
                session_id,
                MAX(flow_id) as flow_id,
                MAX(company_id) as company_id,
                COUNT(*) as call_count,
                MAX(provider) as provider,
                MAX(model) as model,
                COALESCE(SUM(prompt_tokens), 0) as prompt_tokens,
                COALESCE(SUM(completion_tokens), 0) as completion_tokens,
                ROUND(SUM(cost_usd), 6) as cost_usd,
                COALESCE(SUM(duration_ms), 0) as duration_ms,
                MAX(created_at) as created_at,
                MIN(status) as status
            ')
            ->groupBy('session_id')
            ->get();

        // ── Run sessions (runtime agent calls — grouped by flow_run_id) ──
        $runRows = $this->filtered($r)
            ->whereNotNull('flow_run_id')
            ->selectRaw('
                flow_run_id, flow_id, company_id,
                COUNT(*) as call_count,
                MAX(provider) as provider,
                MAX(model) as model,
                COALESCE(SUM(prompt_tokens), 0) as prompt_tokens,
                COALESCE(SUM(completion_tokens), 0) as completion_tokens,
                ROUND(SUM(cost_usd), 6) as cost_usd,
                COALESCE(SUM(duration_ms), 0) as duration_ms,
                MAX(created_at) as created_at,
                MIN(status) as status
            ')
            ->groupByRaw('flow_run_id, flow_id, company_id')
            ->get();

        // ── Resolve company / flow names in bulk (2 queries total) ──
        $allCompanyIds = $genRows->pluck('company_id')->merge($runRows->pluck('company_id'))->filter()->unique();
        $allFlowIds = $genRows->pluck('flow_id')->merge($runRows->pluck('flow_id'))->filter()->unique();
        $companyNames = Company::whereIn('id', $allCompanyIds)->pluck('name', 'id');
        $flowNames = Flow::whereIn('id', $allFlowIds)->pluck('name', 'id');

        $combined = [];

        foreach ($genRows as $row) {
            $pt = (int) $row->prompt_tokens;
            $ct = (int) $row->completion_tokens;
            $combined[] = [
                'row_type' => 'generation',
                'group_key' => 'gen:'.$row->session_id,
                'created_at' => $row->created_at ? Carbon::parse($row->created_at)->format('Y-m-d H:i:s') : null,
                'provider' => $row->provider,
                'model' => $row->model,
                'company' => $companyNames[$row->company_id] ?? '—',
                'flow' => $flowNames[$row->flow_id] ?? '—',
                'call_count' => (int) $row->call_count,
                'prompt_tokens' => $pt > 0 ? $pt : null,
                'completion_tokens' => $ct > 0 ? $ct : null,
                'cost_usd' => (float) $row->cost_usd,
                'duration_ms' => (int) $row->duration_ms,
                'status' => $row->status,
            ];
        }

        foreach ($runRows as $row) {
            $pt = (int) $row->prompt_tokens;
            $ct = (int) $row->completion_tokens;
            $combined[] = [
                'row_type' => 'run',
                'group_key' => 'run:'.$row->flow_run_id,
                'created_at' => $row->created_at ? Carbon::parse($row->created_at)->format('Y-m-d H:i:s') : null,
                'provider' => $row->provider,
                'model' => $row->model,
                'company' => $companyNames[$row->company_id] ?? '—',
                'flow' => $flowNames[$row->flow_id] ?? '—',
                'call_count' => (int) $row->call_count,
                'prompt_tokens' => $pt > 0 ? $pt : null,
                'completion_tokens' => $ct > 0 ? $ct : null,
                'cost_usd' => (float) $row->cost_usd,
                'duration_ms' => (int) $row->duration_ms,
                'status' => $row->status,
            ];
        }

        // Sort newest-first and cap at 500 groups
        usort($combined, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return array_slice($combined, 0, 500);
    }

    /** Cost + tokens per calendar day (defaults to the FULL period). */
    private function spendByDay(Request $r): array
    {
        $from = $r->filled('from') ? Carbon::parse($r->from)->startOfDay() : null;
        $to = $r->filled('to') ? Carbon::parse($r->to)->endOfDay() : null;

        if (! $from || ! $to) {
            $min = $this->filtered($r)->min('llm_requests.created_at');
            $max = $this->filtered($r)->max('llm_requests.created_at');
            $from = $from ?: ($min ? Carbon::parse($min)->startOfDay() : now()->subDays(29)->startOfDay());
            $to = $to ?: ($max ? Carbon::parse($max)->endOfDay() : now()->endOfDay());
        }

        if (abs($from->diffInDays($to)) > 370) {
            $from = $to->copy()->subDays(369)->startOfDay();
        }

        $rows = $this->filtered($r)
            ->whereBetween('llm_requests.created_at', [$from, $to])
            ->get(['created_at', 'cost_usd', 'prompt_tokens', 'completion_tokens']);

        $days = [];
        for ($d = $from->copy(); $d <= $to; $d->addDay()) {
            $days[$d->format('Y-m-d')] = ['cost' => 0.0, 'prompt' => 0, 'completion' => 0];
        }
        foreach ($rows as $row) {
            $key = $row->created_at->format('Y-m-d');
            if (! isset($days[$key])) {
                $days[$key] = ['cost' => 0.0, 'prompt' => 0, 'completion' => 0];
            }
            $days[$key]['cost'] += (float) $row->cost_usd;
            $days[$key]['prompt'] += (int) $row->prompt_tokens;
            $days[$key]['completion'] += (int) $row->completion_tokens;
        }
        ksort($days);

        return [
            'labels' => array_keys($days),
            'cost' => array_map(fn ($d) => round($d['cost'], 2), array_values($days)),
            'promptTokens' => array_map(fn ($d) => $d['prompt'], array_values($days)),
            'completionTokens' => array_map(fn ($d) => $d['completion'], array_values($days)),
        ];
    }

    /** SUM(cost_usd) grouped by a plain column (provider/model); paid only (>0). */
    private function costByColumn(Request $r, string $column, ?int $limit = null): array
    {
        $q = $this->filtered($r)
            ->selectRaw("{$column} as label, SUM(cost_usd) as total")
            ->groupBy($column)
            ->havingRaw('SUM(cost_usd) > 0')
            ->orderByDesc('total');

        if ($limit) {
            $q->limit($limit);
        }

        $rows = $q->get();

        return [
            'labels' => $rows->pluck('label')->map(fn ($l) => $l ?: '—')->values(),
            'data' => $rows->pluck('total')->map(fn ($t) => round((float) $t, 2))->values(),
        ];
    }

    /** SUM(cost_usd) by company name (top 8, paid only). */
    private function spendByCompany(Request $r): array
    {
        $rows = $this->filtered($r)
            ->leftJoin('companies', 'llm_requests.company_id', '=', 'companies.id')
            ->selectRaw('companies.name as label, SUM(llm_requests.cost_usd) as total')
            ->groupBy('companies.name')
            ->havingRaw('SUM(llm_requests.cost_usd) > 0')
            ->orderByDesc('total')
            ->limit(8)
            ->get();

        return [
            'labels' => $rows->pluck('label')->map(fn ($l) => $l ?: '— (без фирма)')->values(),
            'data' => $rows->pluck('total')->map(fn ($t) => round((float) $t, 2))->values(),
        ];
    }

    /** Volume per provider: request count + tokens (paid + free Ollama). */
    private function volumeByProvider(Request $r): array
    {
        $rows = $this->filtered($r)
            ->selectRaw('provider as label, COUNT(*) as cnt, COALESCE(SUM(total_tokens),0) as tokens')
            ->groupBy('provider')
            ->orderByDesc('cnt')
            ->get();

        return [
            'labels' => $rows->pluck('label')->map(fn ($l) => $l ?: '—')->values(),
            'requests' => $rows->pluck('cnt')->map(fn ($c) => (int) $c)->values(),
            'tokens' => $rows->pluck('tokens')->map(fn ($t) => (int) $t)->values(),
        ];
    }

    /** Filter dropdown options (only values that actually appear in llm_requests). */
    private function filterOptions(): array
    {
        $usedCompanyIds = LlmRequest::query()->distinct()->pluck('company_id')->filter();
        $usedFlowIds = LlmRequest::query()->distinct()->pluck('flow_id')->filter();

        return [
            'providers' => LlmRequest::query()->distinct()->orderBy('provider')->pluck('provider')->filter()->values(),
            'models' => LlmRequest::query()->distinct()->orderBy('model')->pluck('model')->filter()->values(),
            'companies' => Company::whereIn('id', $usedCompanyIds)->orderBy('name')->get(['id', 'name']),
            'flows' => Flow::whereIn('id', $usedFlowIds)->orderBy('name')->get(['id', 'name']),
        ];
    }
}
