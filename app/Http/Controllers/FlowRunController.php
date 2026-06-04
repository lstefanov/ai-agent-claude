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

        // Create a pending run immediately, then hand off to the DAG executor,
        // which dispatches the first wave as a Bus::batch onto the `flows` queue.
        // Queue workers process the waves in parallel — the request returns at once.
        $flowRun = FlowRun::create([
            'flow_id' => $flow->id,
            'status' => 'pending',
            'triggered_by' => 'manual',
        ]);

        $executor->run($flow, 'manual', $flowRun);

        // Land back in the Graph Editor, which detects the active run and switches
        // into locked "run" mode with live per-node progress/result/log.
        return redirect()->route('flows.builder', $flow);
    }

    public function show(FlowRun $flowRun)
    {
        $flowRun->load(['flow.company', 'flow.agents', 'flow.nodes', 'agentRuns.agent', 'nodeRuns.flowNode']);

        return view('runs.show', compact('flowRun'));
    }

    public function log(FlowRun $flowRun)
    {
        $logFile = storage_path("logs/run-{$flowRun->id}.log");
        $content = file_exists($logFile) ? file_get_contents($logFile) : 'Log file not found.';

        return response($content, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function poll(FlowRun $flowRun): JsonResponse
    {
        $flowRun->load(['agentRuns.agent', 'nodeRuns']);
        $agentRuns = $flowRun->agentRuns->sortBy('id');
        $attemptCounts = $agentRuns->groupBy('agent_id')->map->count();
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

        // agent_runs: in graph mode, emit node_runs adapted to the same shape
        // so the runs/show Alpine polling works without JS changes.
        $isGraphRun = $flowRun->nodeRuns->isNotEmpty();
        if ($isGraphRun) {
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
        } else {
            $agentRunsData = $agentRuns->map(fn ($r) => [
                'agent_id' => $r->agent_id,
                'status' => $r->status,
                'model_used' => $r->model_used,
                'input' => $r->input,
                'output' => $r->output,
                'raw_output' => $r->raw_output,
                'error' => $r->error,
                'duration_ms' => $r->duration_ms,
                'tokens_used' => $r->tokens_used,
                'attempt_count' => $attemptCounts[$r->agent_id] ?? 1,
                'started_at' => $r->started_at?->format('H:i:s'),
                'completed_at' => $r->completed_at?->format('H:i:s'),
                'started_at_iso' => $r->started_at?->toISOString(),
                'completed_at_iso' => $r->completed_at?->toISOString(),
            ])->values();
        }

        return response()->json([
            'status' => $flowRun->status,
            'started_at_iso' => $flowRun->started_at?->toISOString(),
            'completed_at_iso' => $flowRun->completed_at?->toISOString(),
            'failure_message' => $context['failure_message'] ?? null,
            'final_output' => $flowRun->final_output,
            'step_qa_results' => $context['step_qa_results'] ?? [],
            'progress' => $this->parseRunProgress($flowRun->id),
            'node_runs' => $nodeRunsData,
            'agent_runs' => $agentRunsData,
        ]);
    }

    /**
     * Parse the per-run log file into a structured live-progress snapshot for the
     * currently running agent. The log is written by FlowExecutorService
     * ("STEP n/total: name") and by agents such as DeepResearcherAgent
     * ("[DISCOVERY] открити N страници", "[MAP] {url} → …", "[MERGE] …").
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
