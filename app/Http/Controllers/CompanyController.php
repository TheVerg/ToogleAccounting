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
            'companies' => Company::with([
                'parent:id,name',
                'fiscalDevices:id,company_id,environment,status',
            ])
                ->withCount('users')
                ->orderByRaw("CASE type WHEN 'holding' THEN 0 ELSE 1 END")
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request, ChartOfAccountsService $chart): RedirectResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20',
                Rule::unique('companies', 'code')->where('tenant_id', $tenantId)],
            'type' => ['required', Rule::in(['holding', 'subsidiary'])],
            'parent_id' => ['nullable', 'integer',
                Rule::exists('companies', 'id')->where('tenant_id', $tenantId)],
            'currency' => ['required', 'string', 'size:3'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string'],
            'tax_number' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:255'],
            'bank_branch_code' => ['nullable', 'string', 'max:255'],
            'bank_swift_code' => ['nullable', 'string', 'max:255'],
            'payment_instructions' => ['nullable', 'string'],
        ]);

        $company = Company::create($data);
        $chart->seedDefaultChart($company);

        return back()->with('success', $company->name.' created with a default chart of accounts.');
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20',
                Rule::unique('companies', 'code')->where('tenant_id', $tenantId)->ignore($company->id)],
            'type' => ['required', Rule::in(['holding', 'subsidiary'])],
            'parent_id' => ['nullable', 'integer', Rule::notIn([$company->id]),
                Rule::exists('companies', 'id')->where('tenant_id', $tenantId)],
            'currency' => ['required', 'string', 'size:3'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string'],
            'tax_number' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:255'],
            'bank_branch_code' => ['nullable', 'string', 'max:255'],
            'bank_swift_code' => ['nullable', 'string', 'max:255'],
            'payment_instructions' => ['nullable', 'string'],
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
