<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\ChartOfAccountsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Companies/Index', [
            'companies' => Company::with('parent:id,name')
                ->withCount('users')
                ->orderByRaw("CASE type WHEN 'holding' THEN 0 ELSE 1 END")
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request, ChartOfAccountsService $chart): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20', 'unique:companies,code'],
            'type' => ['required', Rule::in(['holding', 'subsidiary'])],
            'parent_id' => ['nullable', 'integer', 'exists:companies,id'],
            'currency' => ['required', 'string', 'size:3'],
        ]);

        $company = Company::create($data);
        $chart->seedDefaultChart($company);

        return back()->with('success', $company->name.' created with a default chart of accounts.');
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20', Rule::unique('companies', 'code')->ignore($company->id)],
            'type' => ['required', Rule::in(['holding', 'subsidiary'])],
            'parent_id' => ['nullable', 'integer', 'exists:companies,id', Rule::notIn([$company->id])],
            'currency' => ['required', 'string', 'size:3'],
            'is_active' => ['boolean'],
            'locked_before' => ['nullable', 'date'],
        ]);

        $company->update($data);

        return back()->with('success', 'Company updated.');
    }

    public function destroy(Request $request, Company $company): RedirectResponse
    {
        if ($company->id === $request->user()->current_company_id) {
            return back()->with('error', 'You are currently working in '.$company->name.'. Switch to another company before deleting it.');
        }

        if ($company->subsidiaries()->exists()) {
            return back()->with('error', $company->name.' has subsidiaries. Delete or reassign its children first.');
        }

        if ($company->journalEntries()->exists()) {
            return back()->with('error', $company->name.' has posted books. Companies with ledger history cannot be deleted — deactivate it instead.');
        }

        $company->delete();

        return back()->with('success', $company->name.' deleted.');
    }
}
