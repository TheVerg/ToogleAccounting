<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\RecurringExpense;
use App\Models\TaxRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TaxRateController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = $request->user()->current_company_id;

        return Inertia::render('Taxes/Rates', [
            'taxRates' => TaxRate::whereNull('company_id')
                ->orWhere('company_id', $companyId)
                ->orderBy('name')->get(),
            'taxGroups' => $request->user()->currentCompany->taxGroups()->with('rates')->orderBy('name')->get(),
            'defaultTaxMode' => $request->user()->currentCompany->default_tax_mode,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if(
            $request->boolean('global') && ! $request->user()->isSystemAdmin(),
            403,
            'Only system administrators can manage global tax rates.',
        );

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'global' => ['boolean'],
            'zimra_tax_id' => ['nullable', 'integer', 'min:0'],
            'zimra_tax_code' => ['nullable', 'string', 'max:10'],
            'default_price_mode' => ['nullable', 'in:exclusive,inclusive'],
            'calculation_order' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'is_compound' => ['boolean'],
            'is_fiscal_tax' => ['boolean'],
        ]);

        TaxRate::create([
            'company_id' => $request->boolean('global') ? null : $request->user()->current_company_id,
            'name' => $data['name'],
            'rate' => $data['rate'],
            'is_active' => true,
            'zimra_tax_id' => $data['zimra_tax_id'] ?? null,
            'zimra_tax_code' => $data['zimra_tax_code'] ?? null,
            'default_price_mode' => $data['default_price_mode'] ?? null,
            'calculation_order' => $data['calculation_order'] ?? 1,
            'is_compound' => $request->boolean('is_compound'),
            'is_fiscal_tax' => $request->has('is_fiscal_tax') ? $request->boolean('is_fiscal_tax') : true,
        ]);

        return back()->with('success', 'Tax rate created.');
    }

    public function update(Request $request, TaxRate $taxRate): RedirectResponse
    {
        abort_unless(
            $taxRate->company_id === null
                ? $request->user()->isSystemAdmin()
                : $taxRate->company_id === $request->user()->current_company_id,
            403,
        );

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['boolean'],
            'zimra_tax_id' => ['nullable', 'integer', 'min:0'],
            'zimra_tax_code' => ['nullable', 'string', 'max:10'],
            'default_price_mode' => ['nullable', 'in:exclusive,inclusive'],
            'calculation_order' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'is_compound' => ['boolean'],
            'is_fiscal_tax' => ['boolean'],
        ]);

        $taxRate->update($data);

        return back()->with('success', 'Tax rate updated.');
    }

    public function destroy(Request $request, TaxRate $taxRate): RedirectResponse
    {
        abort_unless(
            $taxRate->company_id === null
                ? $request->user()->isSystemAdmin()
                : $taxRate->company_id === $request->user()->current_company_id,
            403,
        );

        $inUse = Invoice::where('tax_rate_id', $taxRate->id)->exists()
            || Expense::where('tax_rate_id', $taxRate->id)->exists()
            || RecurringExpense::where('tax_rate_id', $taxRate->id)->exists()
            || DB::table('invoice_item_taxes')->where('tax_rate_id', $taxRate->id)->exists()
            || DB::table('tax_group_rates')->where('tax_rate_id', $taxRate->id)->exists();

        if ($inUse) {
            return back()->with('error', '"'.$taxRate->name.'" has been used on documents. Deactivate it instead of deleting.');
        }

        $taxRate->delete();

        return back()->with('success', 'Tax rate deleted.');
    }
}
