<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Contact;
use App\Models\Payment;
use App\Services\AccountingService;
use App\Support\CompanyRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class PaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = $request->user()->current_company_id;
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['outgoing', 'transfer'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return Inertia::render('Payments/Index', [
            'payments' => Payment::where('company_id', $companyId)
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('kind', $status))
                ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('date', '>=', $from))
                ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('date', '<=', $to))
                ->with(['contact:id,name', 'fromAccount:id,code,name',
                    'toAccount:id,code,name', 'categoryAccount:id,code,name',
                    'attachments:id,attachable_id,attachable_type,original_name'])
                ->latest('date')->latest('id')
                ->paginate(15)->withQueryString(),
            'moneyAccounts' => Account::where('company_id', $companyId)
                ->whereIn('subtype', ['cash', 'bank', 'petty_cash'])->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'categoryAccounts' => Account::where('company_id', $companyId)
                ->whereIn('type', ['expense', 'liability', 'asset'])
                ->whereNotIn('subtype', ['cash', 'bank', 'petty_cash'])
                ->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'contacts' => Contact::where('company_id', $companyId)
                ->where('is_active', true)->orderBy('name')->get(['id', 'name']),
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
            'kind' => ['required', Rule::in(['outgoing', 'transfer'])],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'from_account_id' => ['required', 'integer', CompanyRules::moneyAccount($companyId)],
            'to_account_id' => ['required_if:kind,transfer', 'nullable', 'integer', 'different:from_account_id',
                CompanyRules::moneyAccount($companyId)],
            'category_account_id' => ['required_if:kind,outgoing', 'nullable', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId)
                    ->whereIn('type', ['expense', 'liability', 'asset'])
                    ->whereNotIn('subtype', Account::MONEY_SUBTYPES)
                    ->where('is_active', true)],
            'contact_id' => ['nullable', 'integer',
                Rule::exists('contacts', 'id')->where('company_id', $companyId)],
            'method' => ['nullable', 'string', 'max:30'],
            'reference' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'document' => CompanyRules::supportingDocument(),
        ]);

        try {
            $payment = $accounting->recordPayment($user->currentCompany, $data, $user->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['date' => $e->getMessage()]);
        }

        Attachment::storeFor($payment, $request->file('document'), $companyId, $user->id);

        return back()->with('success', $data['kind'] === 'transfer' ? 'Transfer recorded.' : 'Payment recorded.');
    }
}
