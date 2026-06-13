<?php

namespace App\Http\Controllers;

use App\Jobs\RunFlowEvalJob;
use App\Models\Flow;
use App\Models\FlowEvalCase;
use App\Models\FlowEvalRun;
use App\Models\FlowNode;
use App\Models\NodeRun;
use App\Services\EvalRunnerService;
use App\Support\ModelLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Eval Suite UI + оркестрация:
 *  - cases CRUD (golden test cases per flow)
 *  - run:    пуска матрица (version × level × case) от реални FlowRun-ове
 *  - status: poll на прогреса по session_token
 *  - results: матрица score/цена + scatter + авто-препоръка
 *  - runDetail: критерии + изход + node runs за един eval run
 */
class FlowEvalController extends Controller
{
    private const HEARTBEAT_KEY = 'queue.heartbeat.flows';

    private const LEVELS = ['low', 'medium', 'high', 'ultra', 'god'];

    public function __construct(private EvalRunnerService $runner) {}

    public function index(Flow $flow)
    {
        $flow->load('company');
        $cases = $flow->evalCases()->orderByDesc('id')->get();
        $versions = $flow->versions()->orderByDesc('is_active')->orderByDesc('id')->get(['id', 'name', 'is_active', 'model_level']);

        return view('flows.eval.index', [
            'flow' => $flow,
            'cases' => $cases,
            'versions' => $versions,
            'levels' => self::LEVELS,
            'hasResults' => $flow->evalRuns()->where('status', 'completed')->exists(),
        ]);
    }

    public function create(Flow $flow)
    {
        $flow->load('company');

        return view('flows.eval.form', ['flow' => $flow, 'case' => null]);
    }

    public function store(Request $request, Flow $flow)
    {
        $flow->evalCases()->create($this->casePayload($request));

        return redirect()->route('flows.eval.index', $flow)->with('success', 'Тестът е създаден.');
    }

    public function edit(Flow $flow, FlowEvalCase $case)
    {
        abort_unless($case->flow_id === $flow->id, 404);
        $flow->load('company');

        return view('flows.eval.form', ['flow' => $flow, 'case' => $case]);
    }

    public function update(Request $request, Flow $flow, FlowEvalCase $case)
    {
        abort_unless($case->flow_id === $flow->id, 404);
        $case->update($this->casePayload($request));

        return redirect()->route('flows.eval.index', $flow)->with('success', 'Тестът е обновен.');
    }

    public function destroy(Flow $flow, FlowEvalCase $case)
    {
        abort_unless($case->flow_id === $flow->id, 404);
        $case->delete();

        return redirect()->route('flows.eval.index', $flow)->with('success', 'Тестът е изтрит.');
    }

    /** Стартира eval матрица (version × level × case) — връща token за polling. */
    public function run(Request $request, Flow $flow): JsonResponse
    {
        $request->validate([
            'version_ids' => 'required|array|min:1',
            'version_ids.*' => 'integer',
            'levels' => 'required|array|min:1',
            'levels.*' => Rule::enum(ModelLevel::class),
            'case_ids' => 'nullable|array',
            'case_ids.*' => 'integer',
        ]);

        if (! Cache::has(self::HEARTBEAT_KEY)) {
            return response()->json(['error' => 'Няма активен queue worker (flows). Стартирай composer dev или php artisan horizon.'], 422);
        }

        $versionIds = $flow->versions()->whereIn('id', $request->input('version_ids'))->pluck('id')->all();
        if ($versionIds === []) {
            return response()->json(['error' => 'Няма валидни версии.'], 422);
        }

        $caseQuery = $flow->evalCases()->where('is_active', true);
        if ($request->filled('case_ids')) {
            $caseQuery->whereIn('id', $request->input('case_ids'));
        }
        $caseIds = $caseQuery->pluck('id')->all();
        if ($caseIds === []) {
            return response()->json(['error' => 'Няма активни тестове за пускане.'], 422);
        }

        $token = (string) Str::uuid();
        $total = 0;

        foreach ($versionIds as $versionId) {
            foreach ($request->input('levels') as $level) {
                foreach ($caseIds as $caseId) {
                    RunFlowEvalJob::dispatch((int) $caseId, (int) $versionId, (string) $level, $token);
                    $total++;
                }
            }
        }

        Cache::put("eval_session_{$token}", ['total' => $total, 'flow_id' => $flow->id], now()->addHours(2));

        return response()->json(['token' => $token, 'total' => $total]);
    }

    public function status(string $token): JsonResponse
    {
        $meta = (array) Cache::get("eval_session_{$token}", []);
        $total = (int) ($meta['total'] ?? 0);

        $runs = FlowEvalRun::where('session_token', $token)
            ->orderBy('id')
            ->get(['id', 'flow_version_id', 'flow_run_id', 'model_level', 'status', 'score']);

        // Брой DAG възли per версия (активни, без qa_verifier — те са inline gate-ове).
        $nodeTotals = FlowNode::whereIn('flow_version_id', $runs->pluck('flow_version_id')->filter()->unique())
            ->where('is_active', true)
            ->where('type', '!=', 'qa_verifier')
            ->selectRaw('flow_version_id, count(*) as c')
            ->groupBy('flow_version_id')
            ->pluck('c', 'flow_version_id');

        // Завършени възли per течащ FlowRun — за прогрес „8/9 възела".
        $runningFlowRunIds = $runs->where('status', 'running')->pluck('flow_run_id')->filter()->values();
        $nodesDone = $runningFlowRunIds->isNotEmpty()
            ? NodeRun::whereIn('flow_run_id', $runningFlowRunIds)
                ->whereIn('status', ['completed', 'skipped'])
                ->selectRaw('flow_run_id, count(*) as c')
                ->groupBy('flow_run_id')
                ->pluck('c', 'flow_run_id')
            : collect();

        $items = [];
        $progressSum = 0.0;
        foreach ($runs as $r) {
            $nodesTotal = (int) ($nodeTotals[$r->flow_version_id] ?? 0);
            $settled = in_array($r->status, ['completed', 'failed'], true);
            $nodesDoneCount = $settled ? $nodesTotal : (int) ($nodesDone[$r->flow_run_id] ?? 0);
            // Течащите се ограничават до <1.0 — judge-ът накрая „доплаща" остатъка.
            $frac = $settled ? 1.0 : ($nodesTotal > 0 ? min(0.95, $nodesDoneCount / $nodesTotal) : 0.0);
            $progressSum += $frac;

            $items[] = [
                'id' => $r->id,
                'level' => $r->model_level,
                'status' => $r->status,
                'score' => $r->score !== null ? (int) round($r->score) : null,
                'nodes_done' => $nodesDoneCount,
                'nodes_total' => $nodesTotal,
            ];
        }

        $done = $runs->whereIn('status', ['completed', 'failed'])->count();

        return response()->json([
            'total' => $total,
            'created' => $runs->count(),
            'done' => $done,
            'failed' => $runs->where('status', 'failed')->count(),
            'running' => $runs->whereIn('status', ['pending', 'running'])->count(),
            'finished' => $total > 0 && $done >= $total,
            // Гладък прогрес 0–1, претеглен по възли (а не само по завършени eval-и).
            'node_progress' => $total > 0 ? round($progressSum / $total, 3) : 0.0,
            'items' => $items,
        ]);
    }

    /** Матрица version × level + scatter точки + авто-препоръка. */
    public function results(Request $request, Flow $flow)
    {
        $flow->load('company');
        $versions = $flow->versions()->orderByDesc('is_active')->orderByDesc('id')->get();
        $cases = $flow->evalCases()->where('is_active', true)->get()->keyBy('id');

        $onlyVersion = $request->integer('version') ?: null;

        // Последният completed eval run per (version, level, case).
        $latest = [];
        $rows = FlowEvalRun::where('flow_id', $flow->id)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->get(['id', 'flow_version_id', 'model_level', 'eval_case_id', 'score', 'cost_usd']);
        foreach ($rows as $r) {
            $latest[$r->flow_version_id][$r->model_level][$r->eval_case_id] ??= $r;
        }

        $matrix = [];
        $points = [];
        foreach ($versions as $version) {
            if ($onlyVersion && $version->id !== $onlyVersion) {
                continue;
            }
            foreach (self::LEVELS as $level) {
                $perCase = $latest[$version->id][$level] ?? [];
                if ($perCase === []) {
                    continue;
                }

                $sumScore = 0.0;
                $sumCost = 0.0;
                $weightTotal = 0.0;
                $runIds = [];
                foreach ($perCase as $caseId => $run) {
                    $weight = (float) ($cases[$caseId]->weight ?? 1.0);
                    $sumScore += (float) $run->score * $weight;
                    $sumCost += (float) $run->cost_usd;
                    $weightTotal += $weight;
                    $runIds[$caseId] = $run->id;
                }
                if ($weightTotal <= 0) {
                    continue;
                }

                $score = round($sumScore / $weightTotal, 1);
                $cost = round($sumCost / max(1, count($perCase)), 4);
                $matrix[$version->id][$level] = [
                    'score' => $score,
                    'cost' => $cost,
                    'cases' => count($perCase),
                    'run_id' => reset($runIds) ?: null,
                ];
                $points[] = [
                    'label' => $version->name.' / '.$level,
                    'version_id' => $version->id,
                    'version_name' => $version->name,
                    'level' => $level,
                    'score' => $score,
                    'cost' => $cost,
                ];
            }
        }

        return view('flows.eval.results', [
            'flow' => $flow,
            'versions' => $versions->when($onlyVersion, fn ($c) => $c->where('id', $onlyVersion)->values()),
            'levels' => self::LEVELS,
            'matrix' => $matrix,
            'points' => $points,
            'recommendation' => $this->runner->recommend($points),
        ]);
    }

    public function runDetail(Flow $flow, FlowEvalRun $evalRun)
    {
        abort_unless($evalRun->flow_id === $flow->id, 404);
        $flow->load('company');
        $evalRun->load(['evalCase', 'flowVersion', 'flowRun.nodeRuns' => fn ($q) => $q->orderBy('id')]);

        return view('flows.eval.run-detail', ['flow' => $flow, 'evalRun' => $evalRun]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Валидира + нормализира формата на case-а. input_data + criteria идват
     * като JSON стрингове (Alpine ги сериализира в скрити полета).
     *
     * @return array<string, mixed>
     */
    private function casePayload(Request $request): array
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'prompt' => 'required|string',
            'variables_json' => 'nullable|string',
            'criteria_json' => 'required|string',
            'weight' => 'nullable|numeric',
        ]);

        // Входът на flow-а: задължителна заявка (prompt) + по избор променливи (JSON).
        $inputData = ['prompt' => (string) $request->input('prompt')];
        $rawVars = trim((string) $request->input('variables_json', ''));
        if ($rawVars !== '' && $rawVars !== '{}') {
            $variables = json_decode($rawVars, true);
            if (! is_array($variables)) {
                throw ValidationException::withMessages(['variables_json' => 'Невалиден JSON в „Допълнителни променливи".']);
            }
            $inputData['variables'] = $variables;
        }

        $criteria = json_decode((string) $request->input('criteria_json'), true);
        if (! is_array($criteria) || $criteria === []) {
            throw ValidationException::withMessages(['criteria_json' => 'Добави поне един критерий за качество.']);
        }

        return [
            'name' => (string) $request->input('name'),
            'description' => $request->input('description'),
            'input_data' => $inputData,
            'criteria' => array_values(array_map([$this, 'normalizeCriterion'], $criteria)),
            'weight' => (float) ($request->input('weight') ?: 1.0),
            'is_active' => $request->boolean('is_active', true),
        ];
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function normalizeCriterion(array $c): array
    {
        $type = in_array($c['type'] ?? null, ['llm_judge', 'rule', 'regex'], true) ? $c['type'] : 'llm_judge';
        $label = trim((string) ($c['label'] ?? ''));
        $key = trim((string) ($c['key'] ?? '')) ?: Str::slug($label, '_') ?: 'criterion_'.Str::random(4);

        $out = [
            'key' => $key,
            'label' => $label ?: $key,
            'description' => (string) ($c['description'] ?? ''),
            'weight' => (float) ($c['weight'] ?? 1.0),
            'type' => $type,
        ];

        if ($type === 'rule') {
            $out['rule'] = (string) ($c['rule'] ?? 'word_count');
            foreach (['min', 'max'] as $k) {
                if (isset($c[$k]) && $c[$k] !== '') {
                    $out[$k] = (int) $c[$k];
                }
            }
            if (isset($c['keyword']) && $c['keyword'] !== '') {
                $out['keyword'] = (string) $c['keyword'];
            }
        } elseif ($type === 'regex') {
            $out['pattern'] = (string) ($c['pattern'] ?? '');
            $out['should_match'] = (bool) ($c['should_match'] ?? true);
        }

        return $out;
    }
}
