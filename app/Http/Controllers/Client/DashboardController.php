<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Flow;
use App\Models\FlowRun;

class DashboardController extends Controller
{
    public function index()
    {
        $company = session('client_company_id');

        $recentFlows = Flow::query()
            ->where('company_id', $company)
            ->runnable()
            ->withCount('flowRuns')
            ->with('latestRun')
            ->latest()
            ->take(5)
            ->get();

        $runsQuery = FlowRun::whereHas('flow', fn ($q) => $q->where('company_id', $company));

        $stats = [
            'flows_count' => Flow::where('company_id', $company)->runnable()->count(),
            'runs_count' => (clone $runsQuery)->count(),
            'last_run_at' => (clone $runsQuery)->latest()->value('created_at'),
        ];

        return view('client.dashboard', compact('recentFlows', 'stats'));
    }
}
