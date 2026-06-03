<?php

namespace App\Http\Controllers;

use App\Models\Flow;
use App\Models\FlowRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlowRunController extends Controller
{
    private const DEFAULT_QA_THRESHOLD = 60;

    public function store(Request $request, Flow $flow)
    {
        $activeAgents = $flow->agents()->where('is_active', true)->count();

        if ($activeAgents === 0) {
            return redirect()->route('flows.show', $flow)
                ->with('error', 'Флоуът няма активни агенти.');
        }

        $verifiers = $flow->agents()
            ->where('is_active', true)
            ->where('is_verifier', true)
            ->get(['id', 'qa_threshold']);

        $validator = validator($request->all(), [
            'qa_thresholds' => 'nullable|array',
            'qa_thresholds.*' => 'nullable|integer|min:0|max:100',
        ]);

        $allowedVerifierIds = $verifiers->pluck('id')->map(fn ($id) => (string) $id)->all();
        $validator->after(function ($validator) use ($request, $allowedVerifierIds) {
            foreach (array_keys((array) $request->input('qa_thresholds', [])) as $agentId) {
                if (! in_array((string) $agentId, $allowedVerifierIds, true)) {
                    $validator->errors()->add("qa_thresholds.{$agentId}", 'QA праг може да се задава само за активен QA verifier агент.');
                }
            }
        });

        $validated = $validator->validate();

        $postedThresholds = $validated['qa_thresholds'] ?? [];
        $qaThresholds = $verifiers->mapWithKeys(function ($agent) use ($postedThresholds) {
            return [
                (string) $agent->id => isset($postedThresholds[$agent->id])
                    ? (int) $postedThresholds[$agent->id]
                    : ($agent->qa_threshold ?? self::DEFAULT_QA_THRESHOLD),
            ];
        })->all();
        $stepQaPolicies = $this->buildStepQaPolicySnapshot($flow);

        // Create a pending run immediately so we can redirect to its page
        $flowRun = FlowRun::create([
            'flow_id' => $flow->id,
            'status' => 'pending',
            'triggered_by' => 'manual',
            'context' => [
                'qa_thresholds' => $qaThresholds,
                'step_qa_policies' => $stepQaPolicies,
            ],
            'started_at' => null,
        ]);

        // Launch execution as a background process (avoids MAMP 30s timeout)
        $php = env('PHP_CLI_BINARY', '/opt/homebrew/bin/php');
        $artisan = base_path('artisan');
        $logFile = storage_path("logs/run-{$flowRun->id}.log");

        exec("{$php} {$artisan} flows:execute {$flowRun->id} >> {$logFile} 2>&1 &");

        return redirect()->route('flow-runs.show', $flowRun);
    }

    public function show(FlowRun $flowRun)
    {
        $flowRun->load(['flow.company', 'flow.agents', 'agentRuns.agent']);

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
        $flowRun->load(['agentRuns.agent']);
        $agentRuns = $flowRun->agentRuns->sortBy('id');
        $attemptCounts = $agentRuns->groupBy('agent_id')->map->count();
        $context = $flowRun->context ?? [];

        return response()->json([
            'status' => $flowRun->status,
            'started_at_iso' => $flowRun->started_at?->toISOString(),
            'completed_at_iso' => $flowRun->completed_at?->toISOString(),
            'failure_message' => $context['failure_message'] ?? null,
            'final_output' => $flowRun->final_output,
            'step_qa_results' => $context['step_qa_results'] ?? [],
            'progress' => $this->parseRunProgress($flowRun->id),
            'agent_runs' => $agentRuns->map(fn ($r) => [
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
            ])->values(),
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

    private function buildStepQaPolicySnapshot(Flow $flow): array
    {
        $agents = $flow->agents()
            ->where('is_active', true)
            ->orderBy('order')
            ->get();

        $verifierIds = $agents
            ->where('is_verifier', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $policies = [];

        foreach ($agents as $agent) {
            if ($agent->is_verifier) {
                continue;
            }

            $qa = ($agent->config ?? [])['qa'] ?? [];
            if (! filter_var($qa['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $verifierId = (int) ($qa['verifier_agent_id'] ?? 0);
            if (! in_array($verifierId, $verifierIds, true)) {
                continue;
            }

            $verifier = $agents->firstWhere('id', $verifierId);

            $policies[(string) $agent->id] = [
                'verifier_agent_id' => $verifierId,
                'threshold' => (int) ($qa['threshold'] ?? $verifier?->qa_threshold ?? self::DEFAULT_QA_THRESHOLD),
                'max_retries' => min(10, max(0, (int) ($qa['max_retries'] ?? 3))),
                'custom_prompt' => trim($qa['custom_prompt'] ?? ''),
            ];
        }

        return $policies;
    }
}
