<?php

namespace App\Http\Controllers;

use App\Models\Flow;
use App\Models\FlowRun;
use App\Services\GraphFlowExecutor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlowRunController extends Controller
{
    public function store(Request $request, Flow $flow, GraphFlowExecutor $executor)
    {
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
            'status' => 'pending',
            'triggered_by' => 'manual',
            'context' => $inputs ? ['inputs' => $inputs] : [],
        ]);

        $executor->run($flow, 'manual', $flowRun);

        // Land back in the Graph Editor, which detects the active run and switches
        // into locked "run" mode with live per-node progress/result/log.
        return redirect()->route('flows.builder', $flow);
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
        $flowRun->load('nodeRuns');
        $context = $flowRun->context ?? [];

        // Per-node runs for the graph builder's live node coloring.
        // sortBy('id') guarantees the same order as $agentRunsData below — the
        // builder merges these two arrays by index.
        $nodeRunsData = $flowRun->nodeRuns->sortBy('id')->map(fn ($r) => [
            'node_key' => $r->node_key,
            'status' => $r->status,
            'duration_ms' => $r->duration_ms,
            'started_at_iso' => $r->started_at?->toISOString(),
            'completed_at_iso' => $r->completed_at?->toISOString(),
            'error' => $r->error,
        ])->values();

        // agent_runs: node_runs adapted to the unified shape the runs/show
        // Alpine polling consumes.
        $agentRunsData = $flowRun->nodeRuns->sortBy('id')->map(fn ($r) => [
            'agent_id' => $r->flow_node_id,
            'status' => $r->status,
            'model_used' => $r->model_used,
            'params' => $r->params_snapshot,
            'input' => $r->input,
            'output' => $r->output,
            'raw_output' => $r->raw_output,
            'error' => $r->error,
            'duration_ms' => $r->duration_ms,
            'tokens_used' => $r->tokens_used,
            'attempt_count' => 1,
            'started_at' => $r->started_at?->format('H:i:s'),
            'completed_at' => $r->completed_at?->format('H:i:s'),
            'started_at_iso' => $r->started_at?->toISOString(),
            'completed_at_iso' => $r->completed_at?->toISOString(),
        ])->values();

        // Total paid-provider cost for this run (openai/* nodes + revisions).
        $costUsd = (float) $flowRun->nodeRuns->sum('cost_usd');

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
            'agent_runs' => $agentRunsData,
        ]);
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
