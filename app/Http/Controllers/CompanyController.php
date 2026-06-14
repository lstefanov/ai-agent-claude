<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index()
    {
        $companies = Company::withCount('flows')->latest()->get();

        return view('companies.index', compact('companies'));
    }

    public function create()
    {
        return view('companies.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'industry' => 'required|string|max:255',
            'language' => 'required|in:bg,en',
            'website_url' => 'nullable|url|max:2048',
        ]);

        $company = Company::create($validated);

        return redirect()->route('companies.show', $company)
            ->with('success', 'Фирмата е добавена успешно.');
    }

    public function show(Company $company)
    {
        // Each card shows live metrics: active-template node count (count only the
        // active template's nodes, else every version would inflate the number),
        // template count, run outcomes and total runtime cost across all runs.
        $enrich = fn ($query) => $query
            ->withCount([
                'nodes' => fn ($q) => $q->whereHas('version', fn ($v) => $v->where('is_active', true)),
                'versions',
                'flowRuns as successful_runs_count' => fn ($q) => $q->where('status', 'completed'),
                'flowRuns as failed_runs_count' => fn ($q) => $q->where('status', 'failed'),
            ])
            ->withSum('nodeRuns as total_cost_usd', 'cost_usd');

        $flows = $enrich($company->flows())->where('is_archived', false)->latest()->get();
        $archivedFlows = $enrich($company->flows())->where('is_archived', true)->latest()->get();

        $knowledgeStats = [
            'documents' => $company->knowledgeResources()->count(),
            'chunks' => $company->knowledgeChunks()->count(),
            'facts' => $company->knowledgeFacts()->active()->count(),
        ];

        return view('companies.show', compact('company', 'flows', 'archivedFlows', 'knowledgeStats'));
    }

    public function edit(Company $company)
    {
        return view('companies.edit', compact('company'));
    }

    public function update(Request $request, Company $company)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'industry' => 'required|string|max:255',
            'language' => 'required|in:bg,en',
            'website_url' => 'nullable|url|max:2048',
        ]);

        $company->update($validated);

        return redirect()->route('companies.show', $company)
            ->with('success', 'Фирмата е обновена.');
    }

    public function destroy(Company $company)
    {
        $company->delete();

        return redirect()->route('companies.index')
            ->with('success', 'Фирмата е изтрита.');
    }
}
