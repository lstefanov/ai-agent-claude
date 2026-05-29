<?php

namespace App\Http\Controllers;

use App\Models\Flow;
use App\Models\FlowRun;
use Illuminate\Http\JsonResponse;

class FlowRunController extends Controller
{
    public function store(Flow $flow)
    {
        $activeAgents = $flow->agents()->where('is_active', true)->count();

        if ($activeAgents === 0) {
            return redirect()->route('flows.show', $flow)
                ->with('error', 'Флоуът няма активни агенти.');
        }

        // Create a pending run immediately so we can redirect to its page
        $flowRun = FlowRun::create([
            'flow_id'      => $flow->id,
            'status'       => 'pending',
            'triggered_by' => 'manual',
            'started_at'   => null,
        ]);

        // Launch execution as a background process (avoids MAMP 30s timeout)
        $php     = env('PHP_CLI_BINARY', '/opt/homebrew/bin/php');
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

        return response()->json([
            'status'          => $flowRun->status,
            'started_at_iso'  => $flowRun->started_at?->toISOString(),
            'completed_at_iso' => $flowRun->completed_at?->toISOString(),
            'agent_runs'      => $flowRun->agentRuns->map(fn ($r) => [
                'agent_id'    => $r->agent_id,
                'status'      => $r->status,
                'model_used'  => $r->model_used,
                'input'       => $r->input,
                'output'      => $r->output,
                'error'       => $r->error,
                'duration_ms' => $r->duration_ms,
                'tokens_used' => $r->tokens_used,
                'started_at'  => $r->started_at?->format('H:i:s'),
                'completed_at' => $r->completed_at?->format('H:i:s'),
            ])->values(),
        ]);
    }
}
