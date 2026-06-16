<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Flow;
use Illuminate\Http\Request;

class FlowController extends Controller
{
    public function index()
    {
        $flows = Flow::where('company_id', session('client_company_id'))
            ->runnable()
            ->withCount('flowRuns')
            ->with('latestRun')
            ->latest()
            ->get();

        return view('client.flows.index', compact('flows'));
    }

    public function show(Flow $flow)
    {
        $this->authorizeCompany($flow->company_id);

        $runs = $flow->flowRuns()
            ->latest()
            ->take(20)
            ->get(['id', 'status', 'created_at', 'started_at', 'completed_at']);

        return view('client.flows.show', compact('flow', 'runs'));
    }

    public function updateDescription(Request $request, Flow $flow)
    {
        $this->authorizeCompany($flow->company_id);

        $validated = $request->validate([
            'description' => 'required|string|min:10',
        ]);

        $flow->update(['description' => $validated['description']]);

        return back()->with('success', 'Описанието е обновено.');
    }

    private function authorizeCompany(?int $companyId): void
    {
        abort_unless($companyId === (int) session('client_company_id'), 403);
    }
}
