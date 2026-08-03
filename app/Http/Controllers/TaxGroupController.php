<?php

namespace App\Http\Controllers;

use App\Models\TaxGroup;
use App\Support\CompanyRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaxGroupController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $company = $request->user()->currentCompany;
        $data = $this->validateGroup($request, $company->id);
        $group = $company->taxGroups()->create([
            'name' => $data['name'], 'is_default' => $request->boolean('is_default'), 'is_active' => true,
        ]);
        $this->syncRates($group, $data['rates']);
        if ($group->is_default) $company->taxGroups()->whereKeyNot($group->id)->update(['is_default' => false]);
        return back()->with('success', 'Tax group created.');
    }

    public function update(Request $request, TaxGroup $taxGroup): RedirectResponse
    {
        abort_unless($taxGroup->company_id === $request->user()->current_company_id, 403);
        $data = $this->validateGroup($request, $taxGroup->company_id, true);
        $taxGroup->update([
            'name' => $data['name'], 'is_default' => $request->boolean('is_default'),
            'is_active' => $request->boolean('is_active'),
        ]);
        $this->syncRates($taxGroup, $data['rates']);
        if ($taxGroup->is_default) $taxGroup->company->taxGroups()->whereKeyNot($taxGroup->id)->update(['is_default' => false]);
        return back()->with('success', 'Tax group updated.');
    }

    public function updateCompanyDefaults(Request $request): RedirectResponse
    {
        $data = $request->validate(['default_tax_mode' => ['required', Rule::in(['exclusive', 'inclusive'])]]);
        $request->user()->currentCompany->update($data);
        return back()->with('success', 'Default sales tax treatment updated.');
    }

    protected function validateGroup(Request $request, int $companyId, bool $editing = false): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'], 'is_default' => ['boolean'],
            'is_active' => [$editing ? 'required' : 'nullable', 'boolean'],
            'rates' => ['required', 'array', 'min:1'],
            'rates.*.tax_rate_id' => ['required', 'integer', CompanyRules::taxRate($companyId), 'distinct'],
            'rates.*.calculation_order' => ['required', 'integer', 'min:1', 'max:100'],
            'rates.*.is_compound' => ['boolean'],
        ]);
    }

    protected function syncRates(TaxGroup $group, array $rates): void
    {
        $group->rates()->sync(collect($rates)->mapWithKeys(fn ($rate) => [
            $rate['tax_rate_id'] => ['calculation_order' => $rate['calculation_order'], 'is_compound' => (bool) ($rate['is_compound'] ?? false)],
        ])->all());
    }
}
