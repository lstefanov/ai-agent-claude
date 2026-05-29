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
            'name'        => 'required|string|max:255',
            'description' => 'required|string',
            'industry'    => 'required|string|max:255',
            'language'    => 'required|in:bg,en',
        ]);

        $company = Company::create($validated);

        return redirect()->route('companies.show', $company)
            ->with('success', 'Фирмата е добавена успешно.');
    }

    public function show(Company $company)
    {
        $flows         = $company->flows()->withCount('agents')->where('is_archived', false)->latest()->get();
        $archivedFlows = $company->flows()->withCount('agents')->where('is_archived', true)->latest()->get();

        return view('companies.show', compact('company', 'flows', 'archivedFlows'));
    }

    public function edit(Company $company)
    {
        return view('companies.edit', compact('company'));
    }

    public function update(Request $request, Company $company)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'required|string',
            'industry'    => 'required|string|max:255',
            'language'    => 'required|in:bg,en',
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
