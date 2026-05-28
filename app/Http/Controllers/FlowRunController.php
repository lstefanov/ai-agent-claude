<?php

namespace App\Http\Controllers;

use App\Models\Flow;
use App\Models\FlowRun;
use Illuminate\Http\Request;

class FlowRunController extends Controller
{
    public function store(Flow $flow)
    {
        // Full execution in Phase 3 — placeholder redirect for now
        return redirect()->route('flows.show', $flow)
            ->with('error', 'Изпълнението на агенти ще бъде добавено в Фаза 3.');
    }

    public function show(FlowRun $flowRun)
    {
        $flowRun->load(['flow.company', 'agentRuns.agent']);
        return view('runs.show', compact('flowRun'));
    }
}
