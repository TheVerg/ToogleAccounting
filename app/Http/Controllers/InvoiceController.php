<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Contact;
use App\Models\DocumentSequence;
use App\Models\Invoice;
use App\Models\TaxRate;
use App\Models\TaxGroup;
use App\Services\AccountingService;
use App\Services\InvoiceTaxCalculator;
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
                ->with(['company:id,name,parent_id', 'company.parent:id,name', 'contact:id,name',
                    'items.account:id,code,name', 'items.taxes', 'items.taxGroup:id,name',
                    'payments', 'taxRate:id,name,rate', 'taxGroup:id,name',
                    'attachments:id,attachable_id,attachable_type,original_name',
                    'fiscalReceipts:id,invoice_id,receipt_type,status,error,verification_url,receipt_global_no'])
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
                ->orderBy('code')->get(['id', 'code', 'name', 'currency']),
            'currencies' => app(\App\Services\ExchangeRateService::class)
                ->knownCurrencies($request->user()->currentCompany),
            'taxRates' => TaxRate::where('is_active', true)
                ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
                ->get(['id', 'name', 'rate', 'is_fiscal_tax']),
            'taxGroups' => TaxGroup::where('company_id', $companyId)->where('is_active', true)
                ->with('rates:id,name,rate,is_fiscal_tax')->orderByDesc('is_default')->orderBy('name')->get(),
            'defaultTaxMode' => $request->user()->currentCompany->default_tax_mode,
            'filters' => [
                'status' => $filters['status'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
        ]);
    }

    public function store(Request $request, AccountingService $accounting, InvoiceTaxCalculator $taxCalculator): RedirectResponse
    {
        $user = $request->user();
        $companyId = $user->current_company_id;

        $data = $request->validate([
            'contact_id' => ['nullable', 'integer',
                Rule::exists('contacts', 'id')->where('company_id', $companyId)],
            'contact_name' => ['nullable', 'string', 'max:255', 'required_without:contact_id'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'tax_rate_id' => ['nullable', 'integer', CompanyRules::taxRate($companyId)],
            'tax_group_id' => ['nullable', 'integer', Rule::exists('tax_groups', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'tax_mode' => ['required', Rule::in(['exclusive', 'inclusive'])],
            'notes' => ['nullable', 'string'],
            'issue' => ['boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.account_id' => ['required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId)->where('type', 'income')],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_group_id' => ['nullable', 'integer', Rule::exists('tax_groups', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'items.*.hs_code' => ['nullable', 'string', 'max:8'],
            'document' => CompanyRules::supportingDocument(required: false),
        ]);

        $invoice = DB::transaction(function () use ($data, $user, $companyId, $taxCalculator) {
            $taxRate = ! empty($data['tax_rate_id']) ? TaxRate::find($data['tax_rate_id']) : null;
            $invoiceGroup = ! empty($data['tax_group_id']) ? TaxGroup::with('rates')->find($data['tax_group_id']) : null;
            $calculatedItems = collect($data['items'])->map(function ($item) use ($invoiceGroup, $taxRate, $data, $taxCalculator) {
                $group = ! empty($item['tax_group_id']) ? TaxGroup::with('rates')->find($item['tax_group_id']) : $invoiceGroup;
                $rates = $group?->rates ?? ($taxRate ? collect([$taxRate]) : collect());
                $entered = round((float) $item['quantity'] * (float) $item['unit_price'], 2);
                return $item + ['tax_group' => $group, 'calculation' => $taxCalculator->calculate($entered, $data['tax_mode'], $rates)];
            });
            $subtotal = round((float) $calculatedItems->sum(fn ($item) => $item['calculation']['net']), 2);
            $taxAmount = round((float) $calculatedItems->sum(fn ($item) => $item['calculation']['tax']), 2);
            $total = round((float) $calculatedItems->sum(fn ($item) => $item['calculation']['gross']), 2);

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
                'tax_mode' => $data['tax_mode'],
                'tax_group_id' => $invoiceGroup?->id,
                'tax_rate_id' => $taxRate?->id,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'currency' => strtoupper($data['currency'] ?? $user->currentCompany->currency),
                'exchange_rate' => $data['exchange_rate'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            // HS code: explicit entry wins, else the income category's
            // default (FDMS wants one per line; the spec has a fallback).
            $defaultHsCodes = Account::whereIn('id', collect($data['items'])->pluck('account_id'))
                ->pluck('default_hs_code', 'id');

            foreach ($calculatedItems as $item) {
                $calculation = $item['calculation'];
                $invoiceItem = $invoice->items()->create([
                    'account_id' => $item['account_id'],
                    'tax_group_id' => $item['tax_group']?->id,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'amount' => $calculation['net'],
                    'tax_amount' => $calculation['tax'],
                    'gross_amount' => $calculation['gross'],
                    'hs_code' => $item['hs_code'] ?? $defaultHsCodes[$item['account_id']] ?? null,
                ]);
                $invoiceItem->taxes()->createMany($calculation['components']);
            }

            return $invoice;
        });

        if ($request->hasFile('document')) {
            Attachment::storeFor($invoice, $request->file('document'), $companyId, $user->id);
        }

        if ($request->boolean('issue')) {
            try {
                $accounting->issueInvoice($invoice->fresh(['items.taxes', 'contact', 'company']), $user->id);
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
            $accounting->issueInvoice($invoice->load(['items.taxes', 'contact', 'company']), $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Revenue issued and posted to the ledger.');
    }

    /**
     * Credit-note a fiscalised invoice: reverse the ledger entry (the
     * invoice is voided) and report a CREDITNOTE receipt to FDMS
     * referencing the original — fiscal receipts are never deleted.
     */
    public function creditNote(Request $request, Invoice $invoice, AccountingService $accounting): RedirectResponse
    {
        abort_unless($invoice->company_id === $request->user()->current_company_id, 403);
        abort_unless($invoice->journal_entry_id, 422, 'Only issued invoices can be credit-noted.');

        if (! $invoice->fiscalReceipts()->where('status', 'submitted')->where('receipt_type', 'FiscalInvoice')->exists()) {
            return back()->with('error', 'This invoice was not fiscalised; reverse it from the Journal instead.');
        }

        try {
            $accounting->reverseEntry($invoice->journalEntry, now()->toDateString(), $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        \App\Jobs\FiscaliseCreditNote::dispatch($invoice);

        return back()->with('success',
            $invoice->invoice_no.' voided; a credit note is being reported to ZIMRA.');
    }

    public function recordPayment(Request $request, Invoice $invoice, AccountingService $accounting): RedirectResponse
    {
        $companyId = $request->user()->current_company_id;
        abort_unless($invoice->company_id === $companyId, 403);

        $data = $request->validate([
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
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
