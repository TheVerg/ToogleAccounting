<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        $company = $request->user()->currentCompany;

        return Inertia::render('Accounts/Index', [
            'accounts' => Account::where('company_id', $company->id)
                ->withCount('journalLines')
                ->orderBy('code')->get(),
            'standardGroups' => Account::STANDARD_GROUPS,
            'standardLines' => Account::STANDARD_LINES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $company = $request->user()->currentCompany;

        $data = $request->validate([
            'code' => ['required', 'string', 'max:20',
                Rule::unique('accounts', 'code')->where('company_id', $company->id)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(Account::TYPES)],
            'subtype' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string'],
            'statement' => ['nullable', Rule::in(Account::STATEMENTS)],
            'statement_group' => ['nullable', 'string', 'max:100'],
            'statement_line' => ['nullable', 'string', 'max:100'],
            'balance_type' => ['nullable', Rule::in(['debit', 'credit'])],
        ]);

        Account::create($data + ['company_id' => $company->id]);

        return back()->with('success', 'Account created.');
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $company = $request->user()->currentCompany;
        abort_unless($account->company_id === $company->id, 403);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:20',
                Rule::unique('accounts', 'code')->where('company_id', $company->id)->ignore($account->id)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(Account::TYPES)],
            'subtype' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'statement' => ['nullable', Rule::in(Account::STATEMENTS)],
            'statement_group' => ['nullable', 'string', 'max:100'],
            'statement_line' => ['nullable', 'string', 'max:100'],
            'balance_type' => ['nullable', Rule::in(['debit', 'credit'])],
        ]);

        if ($account->is_system) {
            // System accounts keep their role in the engine; only cosmetic
            // and presentation (statement metadata) edits.
            $data = array_intersect_key($data, array_flip([
                'name', 'description', 'is_active',
                'statement', 'statement_group', 'statement_line', 'balance_type',
            ]));
        } elseif (($data['type'] !== $account->type || ($data['subtype'] ?? null) !== $account->subtype)
            && $account->journalLines()->exists()) {
            // Reclassifying a transacted account would rewrite every
            // historical report; require a new account instead.
            return back()->withErrors([
                'type' => 'This account has posted transactions; its type and subtype cannot be changed.',
            ]);
        }

        $account->update($data);

        return back()->with('success', 'Account updated.');
    }

    public function destroy(Request $request, Account $account): RedirectResponse
    {
        abort_unless($account->company_id === $request->user()->current_company_id, 403);
        abort_if($account->is_system, 422, 'System accounts cannot be deleted.');
        abort_if($account->journalLines()->exists(), 422, 'Account has transactions; deactivate it instead.');

        $account->delete();

        return back()->with('success', 'Account deleted.');
    }
}
