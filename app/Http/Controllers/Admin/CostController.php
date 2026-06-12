<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentGenerationLog;
use App\Models\AssistantMessage;
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
 * provider chokepoints — paid providers (OpenAI/Anthropic/DeepSeek/Gemini/xAI/
 * Qwen, real USD cost) AND free local Ollama (cost 0). Powers summary cards,
 * per-provider model-breakdown boxes, Chart.js charts, a grouped Grid.js
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
            'avg_cost' => $paidRequests > 0 ? round($totalCost / $paidRequests, 2) : 0.0,
        ];

        $charts = [
            'spendByDay' => $this->spendByDay($r),
            'spendByProvider' => $this->costByColumn($r, 'provider'),
            'spendByModel' => $this->costByColumn($r, 'model', 8),
            'spendByCompany' => $this->spendByCompany($r),
            'volumeByProvider' => $this->volumeByProvider($r),
        ];

        // Resolve the configured Builder Copilot provider + model
        // (mirrors BuilderAssistantService::providerModel() without importing the service)
        $validProviders = ['openai', 'anthropic', 'deepseek', 'gemini', 'xai', 'qwen'];
        $chatProvider = (string) config('services.builder_assistant.provider', '');
        if ($chatProvider === '' || $chatProvider === 'ollama' || ! in_array($chatProvider, $validProviders, true)) {
            $genProv = (string) config('services.generator.provider', 'openai');
            $chatProvider = ($genProv !== 'ollama' && in_array($genProv, $validProviders, true)) ? $genProv : 'openai';
        }
        $chatModel = (string) config('services.builder_assistant.model', '') ?: (string) config("services.{$chatProvider}.model", '');

        return view('admin.costs.index', [
            'summary' => $summary,
            'providers' => $this->providerBreakdown($r),
            'charts' => $charts,
            'rows' => $this->gridRows($r),
            'filterOptions' => $this->filterOptions(),
            'filters' => $r->only(['provider', 'model', 'company_id', 'flow_id', 'status', 'from', 'to']),
            'chatProvider' => $chatProvider,
            'chatModel' => $chatModel,
            'chatSummary' => $this->chatSummary($r),
            'chatByModel' => $this->chatByModel($r),
            'chatSessions' => $this->chatSessions($r),
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
            ->where(fn ($q) => $q->where('purpose', '!=', 'assistant')->orWhereNull('purpose'))
            ->selectRaw('
                session_id,
                MAX(flow_id) as flow_id,
                MAX(company_id) as company_id,
                COUNT(*) as call_count,
                GROUP_CONCAT(DISTINCT provider ORDER BY provider SEPARATOR \', \') as provider,
                GROUP_CONCAT(DISTINCT model ORDER BY model SEPARATOR \', \') as model,
                COALESCE(SUM(prompt_tokens), 0) as prompt_tokens,
                COALESCE(SUM(completion_tokens), 0) as completion_tokens,
                ROUND(SUM(cost_usd), 6) as cost_usd,
                COALESCE(SUM(duration_ms), 0) as duration_ms,
                MAX(created_at) as created_at,
                MIN(status) as status
            ')
            ->groupBy('session_id')
            ->get();

        // Resolve agent_generation_logs IDs for generation sessions (token = session_id)
        $sessionIds = $genRows->pluck('session_id')->filter()->values()->all();
        $genLogIds = $sessionIds
            ? AgentGenerationLog::whereIn('token', $sessionIds)
                ->selectRaw('token, MIN(id) as log_id')
                ->groupBy('token')
                ->pluck('log_id', 'token')
            : collect();

        // ── Run sessions (runtime agent calls — grouped by flow_run_id) ──
        $runRows = $this->filtered($r)
            ->whereNotNull('flow_run_id')
            ->selectRaw('
                flow_run_id, flow_id, company_id,
                COUNT(*) as call_count,
                GROUP_CONCAT(DISTINCT provider ORDER BY provider SEPARATOR \', \') as provider,
                GROUP_CONCAT(DISTINCT model ORDER BY model SEPARATOR \', \') as model,
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
            $logId = $genLogIds[$row->session_id] ?? null;
            $combined[] = [
                'ref_id' => $logId ? 'G'.$logId : null,
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
                'ref_id' => 'R'.$row->flow_run_id,
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

    /**
     * One entry per provider with per-model cost/request breakdown.
     *
     * Paid providers (anthropic, openai, deepseek, gemini, xai, qwen) are
     * always shown, sorted by total spend. Each provider lists ALL models from
     * its pricing config — used ones first (by cost), unused ones greyed out
     * below. Ollama is appended last (only DB-used models, no static list).
     */
    private function providerBreakdown(Request $r): array
    {
        $rows = $this->filtered($r)
            ->selectRaw('provider, model, COUNT(*) as cnt, COALESCE(SUM(cost_usd),0) as cost')
            ->groupBy('provider', 'model')
            ->orderByDesc('cost')
            ->get();

        $dbByProvider = $rows->groupBy('provider');

        $knownProviders = ['anthropic', 'openai', 'deepseek', 'gemini', 'xai', 'qwen'];

        // Respect provider filter: if a specific provider is selected, show only that box.
        $providerFilter = $r->input('provider');
        if ($providerFilter) {
            $knownProviders = in_array($providerFilter, $knownProviders) ? [$providerFilter] : [];
        }

        $result = [];

        foreach ($knownProviders as $provider) {
            $pricing = config("services.{$provider}.pricing", []);

            $dbGroup = $dbByProvider->get($provider, collect());
            $total = round((float) $dbGroup->sum('cost'), 6);
            $requests = (int) $dbGroup->sum('cnt');

            // Start with DB-used models
            $modelMap = $dbGroup->keyBy('model')->map(fn ($m) => [
                'model' => $m->model,
                'cost' => (float) $m->cost,
                'requests' => (int) $m->cnt,
            ])->all();

            // Merge all models defined in pricing config (unused → 0 cost, 0 requests)
            foreach (array_keys($pricing) as $model) {
                if (! isset($modelMap[$model])) {
                    $modelMap[$model] = ['model' => $model, 'cost' => 0.0, 'requests' => 0];
                }
            }

            // Used models first (by cost desc), then unused (preserve config order)
            uasort($modelMap, function ($a, $b) {
                $aUsed = $a['requests'] > 0;
                $bUsed = $b['requests'] > 0;
                if ($aUsed !== $bUsed) {
                    return $bUsed <=> $aUsed;
                }

                return $b['cost'] <=> $a['cost'];
            });

            $result[] = [
                'provider' => $provider,
                'total' => $total,
                'requests' => $requests,
                'models' => array_values($modelMap),
            ];
        }

        // Sort paid providers by total spend descending
        usort($result, fn ($a, $b) => $b['total'] <=> $a['total']);

        // Ollama always last — only DB-used models (no static catalogue)
        $showOllama = ! $providerFilter || $providerFilter === 'ollama';
        if ($showOllama && $dbByProvider->has('ollama')) {
            $ollamaGroup = $dbByProvider->get('ollama');
            $ollamaModels = $ollamaGroup->map(fn ($m) => [
                'model' => $m->model,
                'cost' => (float) $m->cost,
                'requests' => (int) $m->cnt,
            ])->sortByDesc('cnt')->values()->all();

            $result[] = [
                'provider' => 'ollama',
                'total' => round((float) $ollamaGroup->sum('cost'), 6),
                'requests' => (int) $ollamaGroup->sum('cnt'),
                'models' => $ollamaModels,
            ];
        }

        return $result;
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

    /**
     * Full chat transcript for one copilot session.
     *
     * Returns { session, meta, messages } where messages are the
     * assistant_messages rows (Q/A pairs) and meta comes from the
     * llm_requests (provider, model, tokens, cost) for that session.
     */
    public function chatDetail(Request $r): JsonResponse
    {
        $session = (string) $r->query('session', '');
        if ($session === '') {
            return response()->json(['error' => 'session required'], 400);
        }

        $messages = AssistantMessage::where('session', $session)
            ->where('status', '!=', 'pending')
            ->orderBy('id')
            ->get(['id', 'role', 'content', 'cost_usd', 'status', 'error', 'ops', 'created_at']);

        $metaRow = LlmRequest::where('session_id', $session)
            ->where('purpose', 'assistant')
            ->selectRaw('
                MAX(flow_id) as flow_id,
                MAX(company_id) as company_id,
                GROUP_CONCAT(DISTINCT model ORDER BY model SEPARATOR \', \') as models,
                GROUP_CONCAT(DISTINCT provider ORDER BY provider SEPARATOR \', \') as providers,
                COALESCE(SUM(total_tokens), 0) as total_tokens,
                COALESCE(SUM(prompt_tokens), 0) as prompt_tokens,
                COALESCE(SUM(completion_tokens), 0) as completion_tokens,
                ROUND(SUM(cost_usd), 6) as cost_usd,
                COUNT(*) as call_count,
                MIN(created_at) as first_at,
                MAX(created_at) as last_at
            ')
            ->first();

        $flowId = $metaRow?->flow_id;
        $companyId = $metaRow?->company_id;

        // Fall back to assistant_messages for flow context when llm_requests has no entry
        if (! $flowId) {
            $flowId = AssistantMessage::where('session', $session)->whereNotNull('flow_id')->value('flow_id');
        }

        $flow = $flowId ? Flow::with('company:id,name')->find($flowId, ['id', 'name', 'company_id']) : null;
        $company = $companyId
            ? Company::find($companyId, ['id', 'name'])
            : $flow?->company;

        return response()->json([
            'session' => $session,
            'meta' => [
                'flow' => $flow?->name,
                'flow_id' => $flow?->id,
                'company' => $company?->name,
                'models' => $metaRow?->models ?: '—',
                'providers' => $metaRow?->providers ?: '—',
                'total_tokens' => (int) ($metaRow?->total_tokens ?? 0),
                'prompt_tokens' => (int) ($metaRow?->prompt_tokens ?? 0),
                'completion_tokens' => (int) ($metaRow?->completion_tokens ?? 0),
                'cost_usd' => (float) ($metaRow?->cost_usd ?? 0),
                'call_count' => (int) ($metaRow?->call_count ?? 0),
                'first_at' => $metaRow?->first_at ? Carbon::parse($metaRow->first_at)->format('Y-m-d H:i:s') : null,
                'last_at' => $metaRow?->last_at ? Carbon::parse($metaRow->last_at)->format('Y-m-d H:i:s') : null,
            ],
            'messages' => $messages->map(fn (AssistantMessage $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'cost_usd' => $m->cost_usd,
                'status' => $m->status,
                'error' => $m->error,
                'has_ops' => ! empty($m->ops),
                'created_at' => $m->created_at?->format('Y-m-d H:i:s'),
            ])->values(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Chat assistant helpers (Builder Copilot)
    // ──────────────────────────────────────────────────────────────────────

    /** Filtered llm_requests scoped to Builder Copilot turns only. */
    private function assistantBase(Request $r): Builder
    {
        return $this->filtered($r)->where('purpose', 'assistant');
    }

    /** Summary stat cards for the chat section. */
    private function chatSummary(Request $r): array
    {
        $agg = $this->assistantBase($r)
            ->selectRaw('
                COUNT(DISTINCT session_id) as sessions,
                COUNT(*) as calls,
                COALESCE(SUM(total_tokens), 0) as total_tokens,
                ROUND(SUM(cost_usd), 6) as total_cost
            ')
            ->first();

        $sessions = (int) ($agg->sessions ?? 0);
        $totalCost = (float) ($agg->total_cost ?? 0);

        $topModel = $this->assistantBase($r)
            ->selectRaw('model, COUNT(*) as cnt')
            ->groupBy('model')
            ->orderByDesc('cnt')
            ->value('model') ?? '—';

        $sessionIds = $this->assistantBase($r)->whereNotNull('session_id')->distinct()->pluck('session_id');
        $msgCount = $sessionIds->isNotEmpty()
            ? AssistantMessage::whereIn('session', $sessionIds)->where('status', '!=', 'pending')->count()
            : 0;

        return [
            'sessions' => $sessions,
            'calls' => (int) ($agg->calls ?? 0),
            'messages' => $msgCount,
            'total_tokens' => (int) ($agg->total_tokens ?? 0),
            'total_cost' => round($totalCost, 4),
            'avg_cost' => $sessions > 0 ? round($totalCost / $sessions, 4) : 0.0,
            'top_model' => $topModel,
        ];
    }

    /** Cost by chat model — dataset for the mini doughnut chart. */
    private function chatByModel(Request $r): array
    {
        $rows = $this->assistantBase($r)
            ->selectRaw('model as label, SUM(cost_usd) as total')
            ->groupBy('model')
            ->havingRaw('SUM(cost_usd) > 0')
            ->orderByDesc('total')
            ->limit(6)
            ->get();

        return [
            'labels' => $rows->pluck('label')->map(fn ($l) => $l ?: '—')->values(),
            'data' => $rows->pluck('total')->map(fn ($t) => round((float) $t, 4))->values(),
        ];
    }

    /**
     * One row per chat session for the Grid.js table, sorted newest-first.
     * Augmented with message counts from assistant_messages.
     */
    private function chatSessions(Request $r): array
    {
        $rows = $this->assistantBase($r)
            ->whereNotNull('session_id')
            ->selectRaw('
                session_id,
                MAX(flow_id) as flow_id,
                MAX(company_id) as company_id,
                GROUP_CONCAT(DISTINCT provider ORDER BY provider SEPARATOR \', \') as provider,
                GROUP_CONCAT(DISTINCT model ORDER BY model SEPARATOR \', \') as model,
                COUNT(*) as call_count,
                COALESCE(SUM(total_tokens), 0) as total_tokens,
                ROUND(SUM(cost_usd), 6) as cost_usd,
                MAX(created_at) as last_at,
                MIN(status) as status
            ')
            ->groupBy('session_id')
            ->get();

        $companyIds = $rows->pluck('company_id')->filter()->unique();
        $flowIds = $rows->pluck('flow_id')->filter()->unique();
        $companyNames = Company::whereIn('id', $companyIds)->pluck('name', 'id');
        $flowNames = Flow::whereIn('id', $flowIds)->pluck('name', 'id');

        $sessionUuids = $rows->pluck('session_id')->filter()->all();
        $msgCounts = $sessionUuids
            ? AssistantMessage::whereIn('session', $sessionUuids)
                ->where('status', '!=', 'pending')
                ->selectRaw('session, COUNT(*) as cnt')
                ->groupBy('session')
                ->pluck('cnt', 'session')
            : collect();

        $result = $rows->map(fn ($row) => [
            'session_id' => $row->session_id,
            'created_at' => $row->last_at ? Carbon::parse($row->last_at)->format('Y-m-d H:i:s') : null,
            'provider' => $row->provider,
            'model' => $row->model,
            'company' => $companyNames[$row->company_id] ?? '—',
            'flow' => $flowNames[$row->flow_id] ?? '—',
            'call_count' => (int) $row->call_count,
            'total_tokens' => (int) $row->total_tokens,
            'cost_usd' => (float) $row->cost_usd,
            'msg_count' => (int) ($msgCounts[$row->session_id] ?? 0),
            'status' => $row->status,
        ])->sortByDesc('created_at')->values()->all();

        return array_slice($result, 0, 500);
    }

    /** Filter dropdown options (only values that actually appear in llm_requests). */
    private function filterOptions(): array
    {
        $usedCompanyIds = LlmRequest::query()->distinct()->pluck('company_id')->filter();
        $usedFlowIds = LlmRequest::query()->distinct()->pluck('flow_id')->filter();

        $pairs = LlmRequest::query()->distinct()->orderBy('provider')->orderBy('model')->get(['provider', 'model'])
            ->filter(fn ($p) => $p->provider && $p->model);

        return [
            'providers' => $pairs->pluck('provider')->unique()->values(),
            'models' => $pairs->pluck('model')->unique()->values(),
            'modelsByProvider' => $pairs->groupBy('provider')->map(fn ($g) => $g->pluck('model')->unique()->values()),
            'companies' => Company::whereIn('id', $usedCompanyIds)->orderBy('name')->get(['id', 'name']),
            'flows' => Flow::whereIn('id', $usedFlowIds)->orderBy('name')->get(['id', 'name']),
        ];
    }
}
