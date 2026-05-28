<?php

namespace App\Http\Controllers;

use App\Jobs\ExecuteFlowJob;
use App\Models\Flow;
use App\Models\FlowRun;
use Illuminate\Http\Request;

class FlowRunController extends Controller
{
    public function store(Flow $flow)
    {
        $activeAgents = $flow->agents()->where('is_active', true)->count();

        if ($activeAgents === 0) {
            return redirect()->route('flows.show', $flow)
                ->with('error', 'Флоуът няма активни агенти.');
        }

        ExecuteFlowJob::dispatch($flow, 'manual');

        // With sync queue the job runs immediately, so we can redirect to the latest run
        $flowRun = $flow->flowRuns()->latest()->first();

        if ($flowRun) {
            return redirect()->route('runs.show', $flowRun)
                ->with('success', 'Флоуът беше изпълнен.');
        }

        return redirect()->route('flows.show', $flow)
            ->with('success', 'Флоуът беше стартиран.');
    }

    public function show(FlowRun $flowRun)
    {
        $flowRun->load(['flow.company', 'agentRuns.agent']);
        return view('runs.show', compact('flowRun'));
    }
}
