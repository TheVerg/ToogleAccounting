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
            'currency' => ['nullable', 'string', 'size:3'],
            'default_hs_code' => ['nullable', 'string', 'max:8'],
            'description' => ['nullable', 'string'],
            'statement' => ['nullable', Rule::in(Account::STATEMENTS)],
            'statement_group' => ['nullable', 'string', 'max:100'],
            'statement_line' => ['nullable', 'string', 'max:100'],
            'balance_type' => ['nullable', Rule::in(['debit', 'credit'])],
        ]);

        // Only money accounts carry a currency (mirrors reality: banks
        // hold FCA balances separately); default to the base currency.
        $isMoney = in_array($data['subtype'] ?? null, Account::MONEY_SUBTYPES, true);
        $data['currency'] = $isMoney
            ? strtoupper($data['currency'] ?? $company->currency)
            : null;

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
            'currency' => ['nullable', 'string', 'size:3'],
            'default_hs_code' => ['nullable', 'string', 'max:8'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'statement' => ['nullable', Rule::in(Account::STATEMENTS)],
            'statement_group' => ['nullable', 'string', 'max:100'],
            'statement_line' => ['nullable', 'string', 'max:100'],
            'balance_type' => ['nullable', Rule::in(['debit', 'credit'])],
        ]);

        if (array_key_exists('currency', $data)) {
            $data['currency'] = $data['currency'] ? strtoupper($data['currency']) : null;
        }

        if ($account->is_system) {
            // System accounts keep their role in the engine; only cosmetic
            // and presentation (statement metadata) edits — plus currency
            // on money accounts, guarded below like any other account.
            $data = array_intersect_key($data, array_flip([
                'name', 'description', 'is_active', 'currency', 'default_hs_code',
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

        // A currency change on a transacted money account would silently
        // redefine what its posted currency amounts mean.
        if (($data['currency'] ?? $account->currency) !== $account->currency
            && $account->isMoneyAccount()
            && $account->journalLines()->exists()) {
            return back()->withErrors([
                'currency' => 'This account has posted transactions; open a new account for the other currency instead.',
            ]);
        }
        if (! $account->isMoneyAccount() && $account->subtype !== 'tax') {
            unset($data['currency']);
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
