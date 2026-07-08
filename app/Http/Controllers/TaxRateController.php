<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\RecurringExpense;
use App\Models\TaxRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'global' => ['boolean'],
        ]);

        abort_if(
            $request->boolean('global') && ! $request->user()->isSystemAdmin(),
            403,
            'Only system administrators can manage global tax rates.',
        );

        TaxRate::create([
            'company_id' => $request->boolean('global') ? null : $request->user()->current_company_id,
            'name' => $data['name'],
            'rate' => $data['rate'],
            'is_active' => true,
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
            || RecurringExpense::where('tax_rate_id', $taxRate->id)->exists();

        if ($inUse) {
            return back()->with('error', '"'.$taxRate->name.'" has been used on documents. Deactivate it instead of deleting.');
        }

        $taxRate->delete();

        return back()->with('success', 'Tax rate deleted.');
    }
}
