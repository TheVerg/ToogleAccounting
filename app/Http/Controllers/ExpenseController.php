<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\TaxRate;
use App\Services\AccountingService;
use App\Services\ReportService;
use App\Support\CompanyRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class ExpenseController extends Controller
{
    public function index(Request $request, ReportService $reports): Response
    {
        $companyId = $request->user()->current_company_id;
        $pettyOnly = $request->query('petty') === '1';
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['ordinary', 'petty_cash', 'recurring'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return Inertia::render('Expenses/Index', [
            'pettyBalances' => $pettyOnly
                ? $reports->moneyBalances([$companyId])
                    ->where('subtype', 'petty_cash')->values()
                : [],
            'expenses' => Expense::where('company_id', $companyId)
                ->when($request->query('petty') === '1', fn ($q) => $q->where('is_petty_cash', true))
                ->when($filters['status'] ?? null, function ($query, $status) {
                    match ($status) {
                        'ordinary' => $query->where('is_petty_cash', false)->whereNull('recurring_expense_id'),
                        'petty_cash' => $query->where('is_petty_cash', true),
                        'recurring' => $query->whereNotNull('recurring_expense_id'),
                    };
                })
                ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('date', '>=', $from))
                ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('date', '<=', $to))
                ->with(['contact:id,name', 'account:id,code,name', 'paidFromAccount:id,code,name',
                    'attachments:id,attachable_id,attachable_type,original_name'])
                ->latest('date')->latest('id')
                ->paginate(15)->withQueryString(),
            'expenseAccounts' => Account::where('company_id', $companyId)
                ->where('type', 'expense')->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'moneyAccounts' => Account::where('company_id', $companyId)
                ->whereIn('subtype', ['cash', 'bank', 'petty_cash'])->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name', 'subtype', 'currency']),
            'vendors' => Contact::where('company_id', $companyId)
                ->whereIn('type', ['vendor', 'both'])->where('is_active', true)
                ->orderBy('name')->get(['id', 'name']),
            'taxRates' => TaxRate::where('is_active', true)
                ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
                ->get(['id', 'name', 'rate']),
            'pettyOnly' => $pettyOnly,
            'filters' => [
                'status' => $filters['status'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
        ]);
    }

    public function store(Request $request, AccountingService $accounting): RedirectResponse
    {
        $user = $request->user();
        $companyId = $user->current_company_id;

        $data = $request->validate([
            'date' => ['required', 'date'],
            'account_id' => ['required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId)->where('type', 'expense')],
            'paid_from_account_id' => ['required', 'integer', CompanyRules::moneyAccount($companyId)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'tax_rate_id' => ['nullable', 'integer', CompanyRules::taxRate($companyId)],
            'contact_id' => ['nullable', 'integer',
                Rule::exists('contacts', 'id')->where('company_id', $companyId)],
            'description' => ['nullable', 'string'],
            'document' => CompanyRules::supportingDocument(),
        ]);

        if (! empty($data['tax_rate_id'])) {
            $rate = TaxRate::find($data['tax_rate_id']);
            $data['tax_amount'] = round((float) $data['amount'] * ((float) $rate->rate / 100), 2);
        }

        $paidFrom = Account::find($data['paid_from_account_id']);
        $data['is_petty_cash'] = $paidFrom?->subtype === 'petty_cash';

        try {
            $expense = $accounting->recordExpense($user->currentCompany, $data, $user->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['date' => $e->getMessage()]);
        }

        Attachment::storeFor($expense, $request->file('document'), $companyId, $user->id);

        return back()->with('success', 'Expense recorded.');
    }
}
