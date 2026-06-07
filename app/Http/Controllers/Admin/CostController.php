<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Flow;
use App\Models\LlmRequest;
use App\Models\NodeRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin "Разходи" — full audit of LLM usage and paid OpenAI / Anthropic spend.
 *
 * Two read-only sources, combined consistently across summary cards, charts,
 * the filterable/paginated table and the detail popup:
 *  - llm_requests — every PAID call (per-call: chat / chat_json / embedding),
 *    with real tokens + USD cost;
 *  - node_runs — free local Ollama runtime executions (local models, $0). Ollama
 *    tokens are not tracked in this app, so they show as "—".
 *
 * Every filter applies to BOTH sources (global filtering). Cost-based stats are
 * paid-only by nature (Ollama is free); volume/count stats include Ollama.
 */
class CostController extends Controller
{
    public function index(Request $r)
    {
        $paidIn = $this->includePaid($r);
        $ollamaIn = $this->includeOllama($r);

        $paidCount = $paidIn ? (int) $this->filtered($r)->count() : 0;
        $ollamaCount = $ollamaIn ? (int) $this->ollamaBase($r)->count() : 0;
        $totalCost = $paidIn ? (float) $this->filtered($r)->sum('cost_usd') : 0.0;

        // ── Summary cards (filter-aware) ───────────────────────────────────
        $summary = [
            'total_cost' => $totalCost,
            'month_cost' => $paidIn ? (float) $this->filtered($r)->where('created_at', '>=', now()->startOfMonth())->sum('cost_usd') : 0.0,
            'today_cost' => $paidIn ? (float) $this->filtered($r)->whereDate('created_at', today())->sum('cost_usd') : 0.0,
            'total_tokens' => $paidIn ? (int) $this->filtered($r)->sum('total_tokens') : 0,
            'total_requests' => $paidCount + $ollamaCount,
            'paid_requests' => $paidCount,
            'ollama_requests' => $ollamaCount,
            'openai_cost' => $paidIn ? (float) $this->filtered($r)->where('provider', 'openai')->sum('cost_usd') : 0.0,
            'anthropic_cost' => $paidIn ? (float) $this->filtered($r)->where('provider', 'anthropic')->sum('cost_usd') : 0.0,
        ];
        $summary['avg_cost'] = $paidCount > 0 ? $totalCost / $paidCount : 0.0;

        // ── Charts ─────────────────────────────────────────────────────────
        $charts = [
            'spendByDay' => $this->spendByDay($r),
            'spendByProvider' => $this->groupSum($r, 'provider'),
            'spendByModel' => $this->groupSum($r, 'model', 8),
            'spendByCompany' => $this->spendByCompany($r),
            'requestsByKind' => $this->requestsByKind($r),
            'volumeByProvider' => $this->volumeByProvider($r),
        ];

        return view('admin.costs.index', [
            'summary' => $summary,
            'charts' => $charts,
            'requests' => $this->tableRows($r, $paidIn, $ollamaIn),
            'filterOptions' => $this->filterOptions(),
            'filters' => $r->only(['provider', 'model', 'company_id', 'flow_id', 'kind', 'status', 'from', 'to']),
        ]);
    }

    /**
     * Full detail for the popup. `source` decides the table:
     *  - paid   → llm_requests (per-call prompt/response, tokens, cost);
     *  - ollama → node_runs (input/output; system prompt from the flow node).
     */
    public function show(Request $r)
    {
        $id = (int) $r->query('id');

        if ($r->query('source') === 'ollama') {
            $nr = NodeRun::with(['flowNode', 'flowRun.flow.company'])->findOrFail($id);
            $flow = $nr->flowRun?->flow;

            return response()->json([
                'id' => $nr->id,
                'created_at' => $nr->created_at?->format('Y-m-d H:i:s'),
                'provider' => 'ollama',
                'model' => $nr->model_used,
                'kind' => 'chat',
                'purpose' => 'runtime',
                'company' => $flow?->company?->name,
                'flow' => $flow?->name,
                'flow_run_id' => $nr->flow_run_id,
                'node_run_id' => $nr->id,
                'node_key' => $nr->node_key,
                'agent_name' => $nr->flowNode?->name,
                'agent_type' => $nr->flowNode?->type,
                'prompt_tokens' => $nr->prompt_tokens,
                'completion_tokens' => $nr->completion_tokens,
                'total_tokens' => $nr->tokens_used,
                'cost_usd' => 0.0,
                'duration_ms' => $nr->duration_ms,
                'status' => $nr->status,
                'error' => $nr->error,
                'options' => $nr->params_snapshot,
                'system_prompt' => $nr->flowNode?->system_prompt,
                'user_message' => $nr->input,
                'response_text' => $nr->output ?: $nr->raw_output,
            ]);
        }

        $req = LlmRequest::with(['company:id,name', 'flow:id,name', 'nodeRun:id,node_key'])->findOrFail($id);

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

    // ──────────────────────────────────────────────────────────────────────
    // Source gating — which sources a given filter set includes
    // ──────────────────────────────────────────────────────────────────────

    /** Paid (llm_requests) unless the provider filter pins Ollama. */
    private function includePaid(Request $r): bool
    {
        return ! $r->filled('provider') || in_array($r->provider, ['openai', 'anthropic'], true);
    }

    /** Ollama (node_runs) only when provider is unset/ollama AND kind is unset/chat. */
    private function includeOllama(Request $r): bool
    {
        return (! $r->filled('provider') || $r->provider === 'ollama')
            && (! $r->filled('kind') || $r->kind === 'chat');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Filtered base queries (one per source)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Paid base — llm_requests with filters applied. Columns are table-qualified
     * so the query stays unambiguous when a caller joins another table.
     */
    private function filtered(Request $r): Builder
    {
        return LlmRequest::query()
            ->when($r->filled('provider'), fn ($q) => $q->where('llm_requests.provider', $r->provider))
            ->when($r->filled('model'), fn ($q) => $q->where('llm_requests.model', $r->model))
            ->when($r->filled('company_id'), fn ($q) => $q->where('llm_requests.company_id', $r->company_id))
            ->when($r->filled('flow_id'), fn ($q) => $q->where('llm_requests.flow_id', $r->flow_id))
            ->when($r->filled('kind'), fn ($q) => $q->where('llm_requests.kind', $r->kind))
            ->when($r->filled('status'), fn ($q) => $q->where('llm_requests.status', $r->status))
            ->when($r->filled('from'), fn ($q) => $q->whereDate('llm_requests.created_at', '>=', $r->from))
            ->when($r->filled('to'), fn ($q) => $q->whereDate('llm_requests.created_at', '<=', $r->to));
    }

    /** Ollama base — node_runs (local models only) joined to flow/company, filters applied. */
    private function ollamaBase(Request $r): \Illuminate\Database\Query\Builder
    {
        return DB::table('node_runs')
            ->join('flow_runs', 'node_runs.flow_run_id', '=', 'flow_runs.id')
            ->join('flows', 'flow_runs.flow_id', '=', 'flows.id')
            ->leftJoin('flow_nodes', 'node_runs.flow_node_id', '=', 'flow_nodes.id')
            ->whereNotNull('node_runs.model_used')
            ->where('node_runs.model_used', 'not like', 'openai/%')
            ->where('node_runs.model_used', 'not like', 'anthropic/%')
            ->when($r->filled('model'), fn ($q) => $q->where('node_runs.model_used', $r->model))
            ->when($r->filled('company_id'), fn ($q) => $q->where('flows.company_id', $r->company_id))
            ->when($r->filled('flow_id'), fn ($q) => $q->where('flows.id', $r->flow_id))
            ->when($r->filled('status'), fn ($q) => $q->where('node_runs.status', $r->status))
            ->when($r->filled('from'), fn ($q) => $q->whereDate('node_runs.created_at', '>=', $r->from))
            ->when($r->filled('to'), fn ($q) => $q->whereDate('node_runs.created_at', '<=', $r->to));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Table (paid ∪ ollama, paginated)
    // ──────────────────────────────────────────────────────────────────────

    private function tableRows(Request $r, bool $paidIn, bool $ollamaIn): LengthAwarePaginator
    {
        $subs = [];

        if ($paidIn) {
            $subs[] = $this->filtered($r)->toBase()->selectRaw(
                "'paid' as source, id as ref_id, created_at, provider, model, kind, purpose, ".
                'company_id, flow_id, agent_name, agent_type, prompt_tokens, completion_tokens, '.
                'total_tokens, cost_usd, duration_ms, status'
            );
        }

        if ($ollamaIn) {
            $subs[] = $this->ollamaBase($r)->selectRaw(
                "'ollama' as source, node_runs.id as ref_id, node_runs.created_at as created_at, ".
                "'ollama' as provider, node_runs.model_used as model, 'chat' as kind, 'runtime' as purpose, ".
                'flows.company_id as company_id, flow_runs.flow_id as flow_id, '.
                'flow_nodes.name as agent_name, flow_nodes.type as agent_type, '.
                'node_runs.prompt_tokens as prompt_tokens, node_runs.completion_tokens as completion_tokens, '.
                'node_runs.tokens_used as total_tokens, 0 as cost_usd, node_runs.duration_ms as duration_ms, '.
                'node_runs.status as status'
            );
        }

        if (empty($subs)) {
            return new LengthAwarePaginator([], 0, 30, null, [
                'path' => $r->url(),
                'query' => $r->query(),
            ]);
        }

        $union = array_shift($subs);
        foreach ($subs as $s) {
            $union->unionAll($s);
        }

        return DB::query()
            ->fromSub($union, 'u')
            ->leftJoin('companies', 'u.company_id', '=', 'companies.id')
            ->leftJoin('flows', 'u.flow_id', '=', 'flows.id')
            ->selectRaw('u.*, companies.name as company_name, flows.name as flow_name')
            ->orderByDesc('u.created_at')
            ->paginate(30)
            ->withQueryString()
            ->through(fn ($row) => [
                'source' => $row->source,
                'id' => $row->ref_id,
                'created_at' => $row->created_at ? Carbon::parse($row->created_at)->format('Y-m-d H:i:s') : null,
                'provider' => $row->provider,
                'model' => $row->model,
                'kind' => $row->kind,
                'purpose' => $row->purpose,
                'company' => $row->company_name,
                'flow' => $row->flow_name,
                'agent_name' => $row->agent_name,
                'agent_type' => $row->agent_type,
                'prompt_tokens' => $row->prompt_tokens,
                'completion_tokens' => $row->completion_tokens,
                'total_tokens' => $row->total_tokens,
                'cost_usd' => (float) $row->cost_usd,
                'duration_ms' => $row->duration_ms,
                'status' => $row->status,
            ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Charts
    // ──────────────────────────────────────────────────────────────────────

    /** Cost + tokens per calendar day (paid only; defaults to the FULL period). */
    private function spendByDay(Request $r): array
    {
        $empty = ['labels' => [], 'cost' => [], 'promptTokens' => [], 'completionTokens' => []];
        if (! $this->includePaid($r)) {
            return $empty;
        }

        $from = $r->filled('from') ? Carbon::parse($r->from)->startOfDay() : null;
        $to = $r->filled('to') ? Carbon::parse($r->to)->endOfDay() : null;

        if (! $from || ! $to) {
            $min = $this->filtered($r)->min('llm_requests.created_at');
            $max = $this->filtered($r)->max('llm_requests.created_at');
            $from = $from ?: ($min ? Carbon::parse($min)->startOfDay() : now()->subDays(29)->startOfDay());
            $to = $to ?: ($max ? Carbon::parse($max)->endOfDay() : now()->endOfDay());
        }

        // Guard against an outlier creating thousands of day buckets.
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
            'cost' => array_map(fn ($d) => round($d['cost'], 4), array_values($days)),
            'promptTokens' => array_map(fn ($d) => $d['prompt'], array_values($days)),
            'completionTokens' => array_map(fn ($d) => $d['completion'], array_values($days)),
        ];
    }

    /** SUM(cost_usd) grouped by a plain column (provider/model) — paid only. */
    private function groupSum(Request $r, string $column, ?int $limit = null): array
    {
        if (! $this->includePaid($r)) {
            return ['labels' => [], 'data' => []];
        }

        $q = $this->filtered($r)
            ->selectRaw("{$column} as label, SUM(cost_usd) as total")
            ->groupBy($column)
            ->orderByDesc('total');

        if ($limit) {
            $q->limit($limit);
        }

        $rows = $q->get();

        return [
            'labels' => $rows->pluck('label')->map(fn ($l) => $l ?: '—')->values(),
            'data' => $rows->pluck('total')->map(fn ($t) => round((float) $t, 4))->values(),
        ];
    }

    /** SUM(cost_usd) by company name (top 8) — paid only. */
    private function spendByCompany(Request $r): array
    {
        if (! $this->includePaid($r)) {
            return ['labels' => [], 'data' => []];
        }

        $rows = $this->filtered($r)
            ->leftJoin('companies', 'llm_requests.company_id', '=', 'companies.id')
            ->selectRaw('companies.name as label, SUM(llm_requests.cost_usd) as total')
            ->groupBy('companies.name')
            ->orderByDesc('total')
            ->limit(8)
            ->get();

        return [
            'labels' => $rows->pluck('label')->map(fn ($l) => $l ?: '— (без фирма)')->values(),
            'data' => $rows->pluck('total')->map(fn ($t) => round((float) $t, 4))->values(),
        ];
    }

    /** Request COUNT by kind (paid kinds + Ollama runtime under "chat"). */
    private function requestsByKind(Request $r): array
    {
        $map = [];

        if ($this->includePaid($r)) {
            foreach ($this->filtered($r)->selectRaw('kind as label, COUNT(*) as cnt')->groupBy('kind')->get() as $row) {
                $label = $row->label ?: '—';
                $map[$label] = ($map[$label] ?? 0) + (int) $row->cnt;
            }
        }

        if ($this->includeOllama($r)) {
            $cnt = (int) $this->ollamaBase($r)->count();
            if ($cnt > 0) {
                $map['chat'] = ($map['chat'] ?? 0) + $cnt;
            }
        }

        arsort($map);

        return ['labels' => array_keys($map), 'data' => array_values($map)];
    }

    /** Paid-vs-free volume: request count + tokens per provider (incl. Ollama). */
    private function volumeByProvider(Request $r): array
    {
        $labels = [];
        $requests = [];
        $tokens = [];

        if ($this->includePaid($r)) {
            foreach ($this->filtered($r)->selectRaw('provider as label, COUNT(*) as cnt, COALESCE(SUM(total_tokens),0) as tokens')->groupBy('provider')->get() as $row) {
                $labels[] = $row->label;
                $requests[] = (int) $row->cnt;
                $tokens[] = (int) $row->tokens;
            }
        }

        if ($this->includeOllama($r)) {
            $o = $this->ollamaBase($r)
                ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(node_runs.tokens_used),0) as tokens')
                ->first();

            if ($o && (int) $o->cnt > 0) {
                $labels[] = 'ollama (безпл.)';
                $requests[] = (int) $o->cnt;
                $tokens[] = (int) $o->tokens;
            }
        }

        return compact('labels', 'requests', 'tokens');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Filter dropdown options (only values that actually appear, both sources)
    // ──────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function filterOptions(): array
    {
        $localNode = fn () => NodeRun::query()
            ->whereNotNull('model_used')
            ->where('model_used', 'not like', 'openai/%')
            ->where('model_used', 'not like', 'anthropic/%');

        $providers = LlmRequest::query()->distinct()->pluck('provider')->filter();
        if ($localNode()->exists()) {
            $providers->push('ollama');
        }

        $models = LlmRequest::query()->distinct()->pluck('model')->filter()
            ->merge($localNode()->distinct()->pluck('model_used'))
            ->unique();

        $ollamaFlowIds = $localNode()
            ->join('flow_runs', 'node_runs.flow_run_id', '=', 'flow_runs.id')
            ->distinct()->pluck('flow_runs.flow_id')->filter();
        $ollamaCompanyIds = Flow::whereIn('id', $ollamaFlowIds)->distinct()->pluck('company_id')->filter();

        $companyIds = LlmRequest::query()->distinct()->pluck('company_id')->filter()->merge($ollamaCompanyIds)->unique();
        $flowIds = LlmRequest::query()->distinct()->pluck('flow_id')->filter()->merge($ollamaFlowIds)->unique();

        $kinds = LlmRequest::query()->distinct()->pluck('kind')->filter()->push('chat')->unique();

        return [
            'providers' => $providers->unique()->sort()->values(),
            'models' => $models->sort()->values(),
            'kinds' => $kinds->sort()->values(),
            'companies' => Company::whereIn('id', $companyIds)->orderBy('name')->get(['id', 'name']),
            'flows' => Flow::whereIn('id', $flowIds)->orderBy('name')->get(['id', 'name']),
        ];
    }
}
