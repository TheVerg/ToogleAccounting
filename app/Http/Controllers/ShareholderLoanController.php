<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Shareholder;
use App\Models\ShareholderLoan;
use App\Services\AccountingService;
use App\Support\CompanyRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class ShareholderLoanController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = $request->user()->current_company_id;
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'repaid', 'reversed'])],
        ]);

        $loans = ShareholderLoan::where('company_id', $companyId)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->with([
                'moneyAccount:id,code,name',
                'attachments:id,attachable_id,attachable_type,original_name',
                'repayments' => fn ($q) => $q->latest('date')->latest('id')
                    ->with(['moneyAccount:id,code,name',
                        'attachments:id,attachable_id,attachable_type,original_name']),
            ])
            ->latest('date')->latest('id')
            ->paginate(15)->withQueryString();

        $totals = ShareholderLoan::where('company_id', $companyId)
            ->where('status', '!=', 'reversed')
            ->selectRaw('COALESCE(SUM(principal),0) as borrowed')
            ->selectRaw('COALESCE(SUM(total_repaid),0) as repaid')
            ->selectRaw('COALESCE(SUM(outstanding_balance),0) as outstanding')
            ->first();

        return Inertia::render('ShareholderLoans/Index', [
            'loans' => $loans,
            'summary' => [
                'borrowed' => round((float) $totals->borrowed, 2),
                'repaid' => round((float) $totals->repaid, 2),
                'outstanding' => round((float) $totals->outstanding, 2),
            ],
            'moneyAccounts' => Account::where('company_id', $companyId)
                ->whereIn('subtype', Account::MONEY_SUBTYPES)->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'shareholders' => Shareholder::where('company_id', $companyId)
                ->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filters' => ['status' => $filters['status'] ?? ''],
        ]);
    }

    public function store(Request $request, AccountingService $accounting): RedirectResponse
    {
        $user = $request->user();
        $companyId = $user->current_company_id;

        $data = $request->validate([
            'shareholder_id' => ['required', 'integer',
                Rule::exists('shareholders', 'id')->where('company_id', $companyId)
                    ->where('is_active', true)->whereNull('deleted_at')],
            'date' => ['required', 'date'],
            'principal' => ['required', 'numeric', 'min:0.01'],
            'money_account_id' => ['required', 'integer', CompanyRules::moneyAccount($companyId)],
            'description' => ['nullable', 'string'],
            'document' => CompanyRules::supportingDocument(),
        ]);

        try {
            $loan = $accounting->recordShareholderLoan($user->currentCompany, $data, $user->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['date' => $e->getMessage()]);
        }

        Attachment::storeFor($loan, $request->file('document'), $companyId, $user->id);

        return back()->with('success', 'Shareholder loan recorded and posted to the ledger.');
    }

    public function storeRepayment(Request $request, ShareholderLoan $shareholderLoan, AccountingService $accounting): RedirectResponse
    {
        $user = $request->user();
        $companyId = $user->current_company_id;
        abort_unless($shareholderLoan->company_id === $companyId, 403);

        $data = $request->validate([
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'money_account_id' => ['required', 'integer', CompanyRules::moneyAccount($companyId)],
            'reference' => ['nullable', 'string', 'max:255'],
            'document' => CompanyRules::supportingDocument(),
        ]);

        try {
            $repayment = $accounting->recordShareholderLoanRepayment($shareholderLoan, $data, $user->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        Attachment::storeFor($repayment, $request->file('document'), $companyId, $user->id);

        return back()->with('success', 'Loan repayment recorded and posted to the ledger.');
    }
}
