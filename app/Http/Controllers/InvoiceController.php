<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Contact;
use App\Models\DocumentSequence;
use App\Models\Invoice;
use App\Models\TaxRate;
use App\Services\AccountingService;
use App\Support\CompanyRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = $request->user()->current_company_id;
        $companyIds = $this->reportingCompanyIds($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'sent', 'partially_paid', 'paid', 'void'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return Inertia::render('Income/Invoices', [
            'invoices' => Invoice::whereIn('company_id', $companyIds)
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('issue_date', '>=', $from))
                ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('issue_date', '<=', $to))
                ->with(['company:id,name,parent_id', 'company.parent:id,name', 'contact:id,name', 'items', 'payments',
                    'attachments:id,attachable_id,attachable_type,original_name'])
                ->latest('issue_date')->latest('id')
                ->paginate(15)->withQueryString(),
            'customers' => Contact::where('company_id', $companyId)
                ->whereIn('type', ['customer', 'both'])->where('is_active', true)
                ->orderBy('name')->get(['id', 'name']),
            'incomeAccounts' => Account::where('company_id', $companyId)
                ->where('type', 'income')->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'moneyAccounts' => Account::where('company_id', $companyId)
                ->whereIn('subtype', ['cash', 'bank', 'petty_cash'])->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'taxRates' => TaxRate::where('is_active', true)
                ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
                ->get(['id', 'name', 'rate']),
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
            'contact_id' => ['nullable', 'integer',
                Rule::exists('contacts', 'id')->where('company_id', $companyId)],
            'contact_name' => ['nullable', 'string', 'max:255', 'required_without:contact_id'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'tax_rate_id' => ['nullable', 'integer', CompanyRules::taxRate($companyId)],
            'notes' => ['nullable', 'string'],
            'issue' => ['boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.account_id' => ['required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId)->where('type', 'income')],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'document' => CompanyRules::supportingDocument(required: false),
        ]);

        $invoice = DB::transaction(function () use ($data, $user, $companyId) {
            $subtotal = 0;
            foreach ($data['items'] as $item) {
                $subtotal += round($item['quantity'] * $item['unit_price'], 2);
            }

            $taxRate = ! empty($data['tax_rate_id']) ? TaxRate::find($data['tax_rate_id']) : null;
            $taxAmount = $taxRate ? round($subtotal * ((float) $taxRate->rate / 100), 2) : 0;

            $contactId = $data['contact_id'] ?? null;
            if (! $contactId && ! empty($data['contact_name'])) {
                $contactId = Contact::create([
                    'company_id' => $companyId,
                    'type' => 'customer',
                    'name' => $data['contact_name'],
                    'is_active' => true,
                ])->id;
            }

            $invoice = Invoice::create([
                'company_id' => $companyId,
                'contact_id' => $contactId,
                'invoice_no' => DocumentSequence::nextNo($companyId, 'INV', 'invoices', 'invoice_no'),
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'] ?? null,
                'status' => 'draft',
                'subtotal' => $subtotal,
                'tax_rate_id' => $taxRate?->id,
                'tax_amount' => $taxAmount,
                'total' => round($subtotal + $taxAmount, 2),
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($data['items'] as $item) {
                $invoice->items()->create([
                    'account_id' => $item['account_id'],
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'amount' => round($item['quantity'] * $item['unit_price'], 2),
                ]);
            }

            return $invoice;
        });

        if ($request->hasFile('document')) {
            Attachment::storeFor($invoice, $request->file('document'), $companyId, $user->id);
        }

        if ($request->boolean('issue')) {
            try {
                $accounting->issueInvoice($invoice->fresh(['items', 'contact', 'company']), $user->id);
            } catch (InvalidArgumentException $e) {
                return back()->with('error',
                    'Revenue '.$invoice->invoice_no.' was saved as a draft but could not be issued: '.$e->getMessage());
            }
        }

        return back()->with('success', 'Revenue '.$invoice->invoice_no.' created.');
    }

    public function issue(Request $request, Invoice $invoice, AccountingService $accounting): RedirectResponse
    {
        abort_unless($invoice->company_id === $request->user()->current_company_id, 403);
        abort_unless($invoice->status === 'draft', 422, 'Only draft revenue records can be issued.');

        try {
            $accounting->issueInvoice($invoice->load(['items', 'contact', 'company']), $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Revenue issued and posted to the ledger.');
    }

    public function recordPayment(Request $request, Invoice $invoice, AccountingService $accounting): RedirectResponse
    {
        $companyId = $request->user()->current_company_id;
        abort_unless($invoice->company_id === $companyId, 403);

        $data = $request->validate([
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'deposit_account_id' => ['required', 'integer', CompanyRules::moneyAccount($companyId)],
            'method' => ['nullable', 'string', 'max:30'],
            'reference' => ['nullable', 'string', 'max:255'],
            'document' => CompanyRules::supportingDocument(),
        ]);

        try {
            $payment = $accounting->recordInvoicePayment($invoice->load('company'), $data, $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        Attachment::storeFor($payment, $request->file('document'), $companyId, $request->user()->id);

        return back()->with('success', 'Payment recorded.');
    }
}
