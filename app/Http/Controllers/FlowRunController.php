<?php

namespace App\Http\Controllers;

use App\Models\Flow;
use App\Models\FlowRun;
use App\Services\FlowVersionService;
use App\Services\GraphFlowExecutor;
use App\Support\PaidModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FlowRunController extends Controller
{
    public function store(Request $request, Flow $flow, GraphFlowExecutor $executor, FlowVersionService $versions)
    {
        // Run with a specific template (run-popup dropdown): a non-active
        // choice is activated first, which materializes its graph. Forbidden
        // while another run is in flight — execution reads the materialized
        // flow_nodes, so swapping templates mid-run would corrupt it.
        if ($request->filled('version_id')) {
            $version = $flow->versions()->find($request->integer('version_id'));

            if (! $version) {
                return redirect()->route('flows.builder', $flow)
                    ->with('error', 'Избраният шаблон не е намерен.');
            }

            if (! $version->is_active) {
                $runInFlight = $flow->flowRuns()->whereIn('status', ['pending', 'running'])->exists();
                if ($runInFlight) {
                    return redirect()->route('flows.builder', $flow)
                        ->with('error', 'Шаблонът не може да се сменя, докато тече изпълнение.');
                }

                try {
                    $versions->activate($version);
                } catch (ValidationException $e) {
                    return redirect()->route('flows.builder', $flow)
                        ->with('error', collect($e->errors())->flatten()->implode(' '));
                }
            }
        }

        $activeNodes = $flow->nodes()->where('is_active', true)->count();

        if ($activeNodes === 0) {
            return redirect()->route('flows.builder', $flow)
                ->with('error', 'Графът няма активни възли.');
        }

        // Per-run inputs ({{topic}} + arbitrary placeholders) — one flow can serve
        // many inputs without editing the graph. Kept as plain strings; the seed
        // (GraphFlowExecutor::buildSeed) merges them over the flow defaults.
        $inputs = collect((array) $request->input('inputs', []))
            ->filter(fn ($v) => is_scalar($v) && (string) $v !== '')
            ->map(fn ($v) => (string) $v)
            ->all();

        // Create a pending run immediately, then hand off to the DAG executor,
        // which dispatches the first wave as a Bus::batch onto the `flows` queue.
        // Queue workers process the waves in parallel — the request returns at once.
        $flowRun = FlowRun::create([
            'flow_id' => $flow->id,
            'flow_version_id' => $flow->activeVersion()->value('id'),
            'status' => 'pending',
            'triggered_by' => 'manual',
            'context' => $inputs ? ['inputs' => $inputs] : [],
        ]);

        $executor->run($flow, 'manual', $flowRun);

        // Land back in the Graph Editor, which detects the active run and switches
        // into locked "run" mode with live per-node progress/result/log.
        return redirect()->route('flows.builder', [$flow, 'run' => $flowRun->id]);
    }

    public function show(FlowRun $flowRun)
    {
        $flowRun->load(['flow.company', 'flow.nodes', 'nodeRuns.flowNode']);

        return view('runs.show', compact('flowRun'));
    }

    public function log(FlowRun $flowRun)
    {
        $logFile = storage_path("logs/run-{$flowRun->id}.log");
        $content = file_exists($logFile) ? file_get_contents($logFile) : 'Log file not found.';

        return response($content, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * Persist a SUCCEEDED Фаза-3 revision into the flow itself (with the
     * user's explicit confirmation from the run viewer): updates both the
     * flow_nodes row and the Drawflow graph_layout so the builder shows the
     * revised prompts on next open.
     */
    public function applyRevision(Request $request, FlowRun $flowRun): JsonResponse
    {
        $nodeKey = (string) $request->input('node_key');

        $entries = $flowRun->context['replan'][$nodeKey] ?? [];
        $payload = collect($entries)
            ->filter(fn ($e) => ($e['succeeded'] ?? false) && ! empty($e['payload']))
            ->last()['payload'] ?? null;

        if (! is_array($payload)) {
            return response()->json(['ok' => false, 'error' => 'Няма успешна ревизия за този възел.'], 422);
        }

        $flow = $flowRun->flow;
        $node = $flow->nodes()->where('node_key', $nodeKey)->first();

        if (! $node) {
            return response()->json(['ok' => false, 'error' => 'Възелът вече не съществува в графа.'], 404);
        }

        $config = $node->config ?? [];
        $config['temperature'] = $payload['temperature'];

        $node->update([
            'system_prompt' => $payload['system_prompt'],
            'prompt_template' => $payload['prompt_template'],
            'model' => $payload['model'],
            'config' => $config,
        ]);

        // Keep the Drawflow layout in sync — the builder loads from graph_layout.
        $layout = $flow->graph_layout;
        if (is_array($layout)) {
            foreach ($layout['drawflow'] ?? [] as $moduleName => $module) {
                if (isset($module['data'][$nodeKey]['data'])) {
                    $data = $module['data'][$nodeKey]['data'];
                    $data['system_prompt'] = $payload['system_prompt'];
                    $data['prompt_template'] = $payload['prompt_template'];
                    $data['model'] = $payload['model'];
                    $data['config'] = array_merge((array) ($data['config'] ?? []), ['temperature' => $payload['temperature']]);
                    $layout['drawflow'][$moduleName]['data'][$nodeKey]['data'] = $data;
                }
            }
            $flow->update(['graph_layout' => $layout]);
        }

        // Mark as applied in the run context so the button collapses.
        $context = $flowRun->context ?? [];
        foreach ($context['replan'][$nodeKey] ?? [] as $i => $entry) {
            if (($entry['succeeded'] ?? false) && ! empty($entry['payload'])) {
                $context['replan'][$nodeKey][$i]['applied_at'] = now()->toISOString();
            }
        }
        $flowRun->update(['context' => $context]);

        return response()->json(['ok' => true]);
    }

    public function poll(FlowRun $flowRun): JsonResponse
    {
        $context = $flowRun->context ?? [];

        // METADATA ONLY: the longText payloads (input/output/raw_output/params)
        // never hydrate here — at a 1-2s poll interval they are the whole
        // payload. Both run views fetch full content on demand via nodeDetail().
        // One array serves both keying schemes: node_key (builder) and
        // agent_id (runs/show).
        $lengthFn = $flowRun->nodeRuns()->getQuery()->getConnection()->getDriverName() === 'mysql'
            ? 'CHAR_LENGTH'
            : 'LENGTH';

        $nodeRunsData = $flowRun->nodeRuns()
            ->orderBy('id')
            ->get([
                'id', 'flow_node_id', 'node_key', 'status', 'model_used', 'tokens_used',
                'duration_ms', 'error', 'started_at', 'completed_at',
                DB::raw('SUBSTR(output, 1, 300) as output_preview'),
                DB::raw("{$lengthFn}(output) as output_chars"),
            ])
            ->map(fn ($r) => [
                'node_key' => $r->node_key,
                'agent_id' => $r->flow_node_id,
                'status' => $r->status,
                'model_used' => $r->model_used,
                'tokens_used' => $r->tokens_used,
                'duration_ms' => $r->duration_ms,
                'error' => $r->error,
                'output_preview' => $r->output_preview,
                'output_chars' => (int) $r->output_chars,
                'attempt_count' => 1,
                'started_at' => $r->started_at?->format('H:i:s'),
                'completed_at' => $r->completed_at?->format('H:i:s'),
                'started_at_iso' => $r->started_at?->toISOString(),
                'completed_at_iso' => $r->completed_at?->toISOString(),
            ])
            ->values();

        // Total paid-provider cost for this run (openai/* nodes + revisions).
        $costUsd = (float) $flowRun->nodeRuns()->sum('cost_usd');

        return response()->json([
            'status' => $flowRun->status,
            'started_at_iso' => $flowRun->started_at?->toISOString(),
            'completed_at_iso' => $flowRun->completed_at?->toISOString(),
            'failure_message' => $context['failure_message'] ?? null,
            'final_output' => $flowRun->final_output,
            'step_qa_results' => $context['step_qa_results'] ?? [],
            'replan' => $context['replan'] ?? [],
            'delivery' => $context['delivery'] ?? null,
            'cost_usd' => $costUsd > 0 ? round($costUsd, 4) : null,
            'progress' => $this->parseRunProgress($flowRun->id),
            'node_runs' => $nodeRunsData,
        ]);
    }

    /**
     * Full payload for ONE node run — fetched on demand when the user opens a
     * result/log panel, instead of shipping every longText field on every poll.
     */
    public function nodeDetail(FlowRun $flowRun, string $nodeKey): JsonResponse
    {
        $run = $flowRun->nodeRuns()->where('node_key', $nodeKey)->orderByDesc('id')->first();

        if (! $run) {
            return response()->json(['error' => 'Няма изпълнение за този възел.'], 404);
        }

        return response()->json([
            'node_key' => $run->node_key,
            'agent_id' => $run->flow_node_id,
            'status' => $run->status,
            'model_used' => $run->model_used,
            'params' => $run->params_snapshot,
            'input' => $run->input,
            'output' => $run->output,
            'raw_output' => $run->raw_output,
            'error' => $run->error,
            'tokens_used' => $run->tokens_used,
            'prompt_tokens' => $run->prompt_tokens,
            'completion_tokens' => $run->completion_tokens,
            'cost_usd' => $run->cost_usd,
            'duration_ms' => $run->duration_ms,
        ]);
    }

    /**
     * Тест на агент: an ad-hoc, transient re-generation of one node — same (or
     * edited) prompts on a user-chosen model. Persists NOTHING (no NodeRun, no
     * flow changes); runs as a detached artisan process and reports through a
     * cache token, mirroring FlowController::generateAgents.
     */
    public function nodeTest(Request $request, FlowRun $flowRun, string $nodeKey): JsonResponse
    {
        $run = $flowRun->nodeRuns()->where('node_key', $nodeKey)->orderByDesc('id')->first();

        if (! $run) {
            return response()->json(['error' => 'Няма изпълнение за този възел.'], 404);
        }

        $validated = $request->validate([
            'model' => ['required', 'string', 'max:200', 'regex:'.FlowController::MODEL_PATTERN],
            'system_prompt' => ['nullable', 'string', 'max:60000'],
            'user_message' => ['required', 'string', 'max:200000'],
            'options' => ['nullable', 'array'],
            'options.temperature' => ['nullable', 'numeric', 'between:0,2'],
            'options.num_predict' => ['nullable', 'integer', 'min:-1', 'max:32768'],
        ]);

        $provider = PaidModel::provider($validated['model']);
        if ($provider && ! PaidModel::available($provider)) {
            return response()->json(['error' => "Провайдърът {$provider} няма конфигуриран API ключ."], 503);
        }

        // Whitelist numeric sampler options — the snapshot may carry anything.
        $options = collect((array) ($validated['options'] ?? []))
            ->only(['temperature', 'top_p', 'top_k', 'repeat_penalty', 'num_predict', 'num_ctx', 'seed'])
            ->filter(fn ($v) => $v !== null && $v !== '' && is_numeric($v))
            ->map(fn ($v) => $v + 0)
            ->all();

        $token = (string) Str::uuid();

        Cache::put("node_test_request_{$token}", [
            'flow_run_id' => $flowRun->id,
            'node_key' => $nodeKey,
            'model' => $validated['model'],
            'system_prompt' => (string) ($validated['system_prompt'] ?? ''),
            'user_message' => $validated['user_message'],
            'options' => $options,
        ], now()->addMinutes(60));

        Cache::put("node_test_{$token}", [
            'status' => 'pending',
            'output' => null,
            'error' => null,
            'updated_at' => now()->timestamp,
        ], now()->addMinutes(60));

        // Detached background process — won't be killed by the web timeout.
        $php = env('PHP_CLI_BINARY', PHP_BINARY);
        $artisan = base_path('artisan');
        $tok = escapeshellarg($token);
        exec("{$php} {$artisan} flows:test-node {$tok} >> ".escapeshellarg(storage_path('logs/node-test.log')).' 2>&1 &');

        return response()->json(['token' => $token]);
    }

    /**
     * Poll endpoint for an ad-hoc node test token.
     */
    public function nodeTestStatus(string $token): JsonResponse
    {
        $result = Cache::get("node_test_{$token}");

        if (! $result) {
            return response()->json(['status' => 'expired', 'error' => 'Токенът е изтекъл. Опитай отново.'], 404);
        }

        return response()->json($result);
    }

    /**
     * Persist a winning test attempt onto the flow's CURRENT node (with the
     * user's explicit confirmation from the test popup): model always, system
     * prompt only when it was edited. The user message is never persisted —
     * it is the rendered template, not the template itself.
     */
    public function applyTest(Request $request, FlowRun $flowRun, string $nodeKey): JsonResponse
    {
        $validated = $request->validate([
            'model' => ['required', 'string', 'max:200', 'regex:'.FlowController::MODEL_PATTERN],
            'system_prompt' => ['nullable', 'string', 'max:60000'],
        ]);

        $flow = $flowRun->flow;
        $node = $flow->nodes()->where('node_key', $nodeKey)->first();

        if (! $node) {
            return response()->json(['ok' => false, 'error' => 'Възелът вече не съществува в графа.'], 404);
        }

        // The snapshot system prompt carries the auto-appended output block;
        // BaseAgent re-appends it on every run, so strip before persisting.
        $system = preg_replace('/\n\n---\nOUTPUT REQUIREMENTS:\n.*$/s', '', (string) ($validated['system_prompt'] ?? ''));

        $updates = ['model' => $validated['model']];
        if (trim($system) !== '') {
            $updates['system_prompt'] = $system;
        }
        $node->update($updates);

        // Keep the Drawflow layout in sync — the builder loads from graph_layout.
        $layout = $flow->graph_layout;
        if (is_array($layout)) {
            foreach ($layout['drawflow'] ?? [] as $moduleName => $module) {
                if (isset($module['data'][$nodeKey]['data'])) {
                    $data = $module['data'][$nodeKey]['data'];
                    $data['model'] = $validated['model'];
                    if (isset($updates['system_prompt'])) {
                        $data['system_prompt'] = $updates['system_prompt'];
                    }
                    $layout['drawflow'][$moduleName]['data'][$nodeKey]['data'] = $data;
                }
            }
            $flow->update(['graph_layout' => $layout]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Parse the per-run log file into a structured live-progress snapshot for the
     * currently running agent. The log is written by agents such as
     * DeepResearcherAgent ("[DISCOVERY] открити N страници", "[MAP] {url} → …",
     * "[MERGE] …"); "STEP n/total: name" markers are honoured when present.
     *
     * Returns null when there is no log yet. The "current agent slice" is every
     * line after the last STEP marker, so counts reflect only the running agent.
     */
    private function parseRunProgress(int $flowRunId): ?array
    {
        $file = storage_path("logs/run-{$flowRunId}.log");
        if (! is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        // Keep memory bounded — only the last ~400 lines matter for live progress.
        $lines = preg_split('/\r?\n/', rtrim($raw)) ?: [];
        if (count($lines) > 400) {
            $lines = array_slice($lines, -400);
        }

        // Locate the last "STEP n/total: name" marker → start of current agent slice.
        $stepIndex = null;
        $currentStep = null;
        $totalSteps = null;
        $agentName = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/STEP\s+(\d+)\/(\d+):\s*(.+)$/u', $line, $m)) {
                $stepIndex = $i;
                $currentStep = (int) $m[1];
                $totalSteps = (int) $m[2];
                $agentName = trim($m[3]);
            }
        }

        $slice = $stepIndex === null ? $lines : array_slice($lines, $stepIndex);

        // Derive phase + page counters from the current agent slice.
        $hasDiscovery = false;
        $pagesTotal = null;
        $pagesDone = 0;
        $pagesFailed = 0;
        foreach ($slice as $line) {
            if (str_contains($line, '[DISCOVERY]')) {
                $hasDiscovery = true;
                if (preg_match('/открити\s+(\d+)/u', $line, $m)) {
                    $pagesTotal = (int) $m[1];
                }
            } elseif (str_contains($line, '[MAP]')) {
                $pagesDone++;
                if (stripos($line, 'FAILED') !== false || str_contains($line, 'неуспешни')) {
                    $pagesFailed++;
                }
            }
        }

        $hasMap = $pagesDone > 0;
        $hasMerge = false;
        foreach ($slice as $line) {
            if (str_contains($line, '[MERGE]')) {
                $hasMerge = true;
                break;
            }
        }

        $phase = 'running';
        if ($hasMerge) {
            $phase = 'merge';
        } elseif ($hasMap) {
            $phase = 'map';
        } elseif ($hasDiscovery) {
            $phase = 'discovery';
        }

        // Last non-empty line = current activity; tail = universal live feed.
        $nonEmpty = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));
        $lastLine = end($nonEmpty) ?: null;
        $tail = array_slice($nonEmpty, -20);

        return [
            'current_step' => $currentStep,
            'total_steps' => $totalSteps,
            'agent_name' => $agentName,
            'phase' => $phase,
            'pages_total' => $pagesTotal,
            'pages_done' => $pagesDone,
            'pages_failed' => $pagesFailed,
            'last_line' => $lastLine,
            'tail' => array_values($tail),
        ];
    }
}
