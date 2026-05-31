<?php

namespace App\Http\Controllers;

use App\Models\Flow;
use App\Models\FlowRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlowRunController extends Controller
{
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
                    : ($agent->qa_threshold ?? 75),
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

    public function updateQaThresholds(Request $request, FlowRun $flowRun): JsonResponse
    {
        $validated = $request->validate([
            'agent_id' => 'required|integer',
            'qa_threshold' => 'required|integer|min:0|max:100',
        ]);

        if (in_array($flowRun->status, ['completed', 'failed'], true)) {
            return response()->json(['message' => 'QA прагът не може да се променя след края на run-а.'], 422);
        }

        $context = $flowRun->context ?? [];
        $qaThresholds = $context['qa_thresholds'] ?? [];
        $agentId = (string) $validated['agent_id'];

        if (! array_key_exists($agentId, $qaThresholds)) {
            return response()->json(['message' => 'QA агентът не е част от snapshot-а на този run.'], 422);
        }

        $agent = $flowRun->flow->agents()
            ->where('id', $validated['agent_id'])
            ->where('is_active', true)
            ->where('is_verifier', true)
            ->first();

        if (! $agent) {
            return response()->json(['message' => 'Невалиден QA агент.'], 422);
        }

        $hasStarted = $flowRun->agentRuns()
            ->where('agent_id', $agent->id)
            ->whereIn('status', ['running', 'completed', 'failed'])
            ->exists();

        if ($hasStarted) {
            return response()->json(['message' => 'QA прагът не може да се променя след старта на QA агента.'], 422);
        }

        $qaThresholds[$agentId] = (int) $validated['qa_threshold'];
        $context['qa_thresholds'] = $qaThresholds;

        $flowRun->update(['context' => $context]);

        return response()->json(['qa_thresholds' => $qaThresholds]);
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
            'step_qa_results' => $context['step_qa_results'] ?? [],
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
            ])->values(),
        ]);
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
                'threshold' => (int) ($qa['threshold'] ?? $verifier?->qa_threshold ?? 75),
                'max_retries' => min(10, max(0, (int) ($qa['max_retries'] ?? 3))),
            ];
        }

        return $policies;
    }
}
