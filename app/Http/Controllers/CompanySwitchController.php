<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CompanySwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
        ]);

        $company = Company::findOrFail($validated['company_id']);

        abort_unless($request->user()->canAccessCompany($company), 403);

        $request->user()->forceFill(['current_company_id' => $company->id])->save();

        return back()->with('success', 'Switched to '.$company->name);
    }
}
