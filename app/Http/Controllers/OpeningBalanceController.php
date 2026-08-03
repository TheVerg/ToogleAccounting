<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * One-time cutover entry for companies moving onto the system with
 * existing balances. System admins only: opening balances rewrite the
 * starting point of every report, so they are deliberately locked down.
 */
class OpeningBalanceController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->isSystemAdmin(), 403);
        $company = $request->user()->currentCompany;

        return Inertia::render('Admin/OpeningBalances', [
            'accounts' => Account::where('company_id', $company->id)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'type', 'currency']),
            'baseCurrency' => $company->currency,
            'equityAccounts' => Account::where('company_id', $company->id)
                ->where('type', 'equity')->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'existingEntry' => JournalEntry::where('company_id', $company->id)
                ->where('reference', 'OPENING')->where('status', 'posted')
                ->first(['id', 'entry_no', 'date', 'description']),
            'lockedBefore' => $company->locked_before?->toDateString(),
        ]);
    }

    public function store(Request $request, AccountingService $accounting): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isSystemAdmin(), 403);
        $company = $user->currentCompany;

        $data = $request->validate([
            'date' => ['required', 'date'],
            'lock_prior_periods' => ['boolean'],
            'balancing_account_id' => ['required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $company->id)
                    ->where('type', 'equity')->where('is_active', true)],
            'lines' => ['required', 'array'],
            'lines.*.account_id' => ['required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $company->id)],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.exchange_rate' => ['nullable', 'numeric', 'gt:0'],
        ]);

        try {
            $entry = $accounting->recordOpeningBalances($company, $data, $user->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['date' => $e->getMessage()]);
        }

        return back()->with('success', "Opening balances posted as {$entry->entry_no}."
            .(! empty($data['lock_prior_periods']) ? ' Periods before the cutover date are now locked.' : ''));
    }
}
