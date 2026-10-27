<?php

namespace App\Services\Compliance;

use App\Models\Company;
use App\Models\ComplianceReturn;
use App\Models\Expense;
use App\Models\FiscalReceipt;
use App\Models\Invoice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ComplianceWorkspaceService
{
    public function build(Company $company, string $from, string $to, ?ComplianceReturn $return = null): array
    {
        // Explicit day boundaries throughout: SQLite stores date columns as
        // "Y-m-d 00:00:00", so a bare "Y-m-d" upper bound silently drops
        // everything dated on the last day of the period. MariaDB truncates to
        // a real DATE and is unaffected, but the two must not disagree.
        $invoices = Invoice::query()
            ->where('company_id', $company->id)
            ->whereBetween('issue_date', [$from.' 00:00:00', $to.' 23:59:59'])
            ->whereNot('status', 'draft')
            ->with(['taxRate', 'items.taxes', 'fiscalReceipts'])
            ->orderBy('issue_date')->get();

        $expenses = Expense::query()
            ->where('company_id', $company->id)
            ->whereBetween('date', [$from.' 00:00:00', $to.' 23:59:59'])
            ->with(['taxRate', 'contact', 'attachments'])
            ->orderBy('date')->get();

        $exceptions = $this->exceptions($company, $invoices, $expenses, $from, $to);
        // Voided invoices are reversed in the ledger and their accepted FDMS credit notes
        // cancel the original receipt, so they must not remain in VAT sales totals.
        $reportableInvoices = $invoices->where('status', '!=', 'void');
        $adjustments = $return?->adjustments()->get() ?? collect();
        $currencies = collect(['USD', 'ZWG'])
            ->merge($reportableInvoices->pluck('currency'))->merge($expenses->pluck('currency'))
            ->merge($adjustments->pluck('currency'))->filter()->map(fn ($c) => strtoupper($c))->unique()->sort();

        $schedules = $currencies->map(function (string $currency) use ($reportableInvoices, $expenses, $adjustments) {
            $sales = $reportableInvoices->where('currency', $currency);
            $purchases = $expenses->where('currency', $currency);
            $bucket = fn (Collection $rows, string $type) => round((float) $rows->sum(function ($invoice) use ($type) {
                if ($invoice->items->isEmpty()) return $this->taxClass($invoice->taxRate) === $type ? (float) $invoice->subtotal : 0;
                return $invoice->items->sum(fn ($item) => $this->taxClassForItem($item, $invoice) === $type ? (float) $item->amount : 0);
            }), 2);

            $standardSales = $bucket($sales, 'standard');
            $outputVat = round((float) $sales->sum(function ($invoice) {
                $snapshotTax = $invoice->items->sum(fn ($item) => $item->taxes->where('is_fiscal_tax', true)->sum('tax_amount'));
                return $invoice->items->contains(fn ($item) => $item->taxes->isNotEmpty()) ? $snapshotTax : (float) $invoice->tax_amount;
            }), 2);
            $inputEligible = $purchases->filter(fn ($e) => (float) $e->tax_amount > 0 && $e->attachments->isNotEmpty());
            $inputVat = round((float) $inputEligible->sum('tax_amount'), 2);
            $adjustment = round((float) $adjustments->where('currency', $currency)->sum('tax_amount'), 2);

            return [
                'currency' => $currency,
                'standard_sales' => $standardSales,
                'zero_rated_sales' => $bucket($sales, 'zero'),
                'exempt_sales' => $bucket($sales, 'exempt'),
                'output_vat' => $outputVat,
                'taxable_purchases' => round((float) $inputEligible->sum('amount'), 2),
                'input_vat' => $inputVat,
                'ineligible_input_vat' => round((float) $purchases->sum('tax_amount') - $inputVat, 2),
                'adjustments' => $adjustment,
                'net_vat' => round($outputVat - $inputVat + $adjustment, 2),
            ];
        })->values()->all();

        $invoiceBase = round((float) $reportableInvoices->sum('base_subtotal'), 2);
        $ledgerRevenue = round((float) DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.company_id', $company->id)->whereBetween('je.date', [$from.' 00:00:00', $to.' 23:59:59'])
            ->whereIn('je.status', ['posted', 'reversed'])->where('a.type', 'income')
            ->sum(DB::raw('jl.credit - jl.debit')), 2);
        $ledgerDifference = round($ledgerRevenue - $invoiceBase, 2);
        if (abs($ledgerDifference) >= 0.01) {
            $exceptions[] = $this->exception(
                'ledger_invoice_mismatch', 'blocking', 'LEDGER',
                'Ledger revenue does not agree to issued invoice sales.', $ledgerDifference, $company->currency,
            );
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'period' => ['from' => $from, 'to' => $to],
            'schedules' => $schedules,
            'reconciliation' => [
                'invoice_count' => $reportableInvoices->count(),
                'invoice_sales_base' => $invoiceBase,
                'ledger_revenue_base' => $ledgerRevenue,
                'ledger_difference' => $ledgerDifference,
                'fdms_submitted' => $reportableInvoices->filter(fn ($i) => $i->fiscalReceipts->contains('status', 'submitted'))->count(),
                'expense_count' => $expenses->count(),
                'exception_count' => count($exceptions),
                'blocking_count' => collect($exceptions)->where('severity', 'blocking')->count(),
            ],
            'exceptions' => $exceptions,
        ];
    }

    protected function exceptions(Company $company, Collection $invoices, Collection $expenses, string $from, string $to): array
    {
        $exceptions = [];
        $hasDevice = $company->fiscalDevices()->where('status', 'active')->exists();

        foreach ($invoices as $invoice) {
            $fiscal = $invoice->fiscalReceipts->where('receipt_type', 'FiscalInvoice')->sortByDesc('id')->first();
            if ($hasDevice && ! $fiscal) {
                $exceptions[] = $this->exception('unfiscalised_invoice', 'blocking', $invoice->invoice_no, 'Issued invoice has no FDMS receipt.', $invoice->total, $invoice->currency);
            } elseif ($fiscal && $fiscal->status !== 'submitted') {
                $exceptions[] = $this->exception('fdms_receipt_'.$fiscal->status, 'blocking', $invoice->invoice_no, $fiscal->error ?: 'FDMS receipt is not accepted.', $invoice->total, $invoice->currency);
            } elseif ($fiscal && abs((float) $fiscal->total - (float) $invoice->total) >= 0.01) {
                $exceptions[] = $this->exception('fdms_total_mismatch', 'blocking', $invoice->invoice_no, 'Invoice and FDMS receipt totals differ.', $invoice->total, $invoice->currency);
            }

            if ($invoice->status === 'void' && $fiscal && ! $invoice->fiscalReceipts->where('receipt_type', 'CreditNote')->contains('status', 'submitted')) {
                $exceptions[] = $this->exception('missing_credit_note', 'blocking', $invoice->invoice_no, 'Voided fiscal invoice has no accepted FDMS credit note.', $invoice->total, $invoice->currency);
            }
        }

        foreach ($expenses as $expense) {
            if ((float) $expense->tax_amount > 0 && $expense->attachments->isEmpty()) {
                $exceptions[] = $this->exception('input_vat_missing_evidence', 'blocking', $expense->expense_no, 'Input VAT has no supplier invoice attachment.', $expense->tax_amount, $expense->currency);
            }
            if ((float) $expense->tax_amount > 0 && ! $expense->contact?->tin) {
                $exceptions[] = $this->exception('supplier_tin_missing', 'warning', $expense->expense_no, 'Taxable purchase supplier has no TIN recorded.', $expense->tax_amount, $expense->currency);
            }
        }

        $numbers = FiscalReceipt::query()->whereHas('device', fn ($q) => $q->where('company_id', $company->id))
            ->whereBetween('receipt_date', [$from.' 00:00:00', $to.' 23:59:59'])
            ->orderBy('receipt_global_no')->pluck('receipt_global_no')->map(fn ($n) => (int) $n)->values();
        for ($i = 1; $i < $numbers->count(); $i++) {
            if ($numbers[$i] !== $numbers[$i - 1] + 1) {
                $exceptions[] = $this->exception('fdms_sequence_gap', 'blocking', (string) $numbers[$i], 'FDMS global receipt sequence has a gap before this number.');
            }
        }

        return $exceptions;
    }

    protected function taxClass($taxRate): string
    {
        if ((int) ($taxRate?->zimra_tax_id ?? 1) === 2) return 'zero';
        if ($taxRate === null || (int) $taxRate->zimra_tax_id === 1) return 'exempt';
        return 'standard';
    }

    protected function taxClassForItem($item, $invoice): string
    {
        $fiscalTax = $item->taxes->firstWhere('is_fiscal_tax', true);
        if ($fiscalTax) {
            if ((int) $fiscalTax->zimra_tax_id === 2) return 'zero';
            if ((int) $fiscalTax->zimra_tax_id === 1) return 'exempt';
            return 'standard';
        }
        return $this->taxClass($invoice->taxRate);
    }

    protected function exception(string $type, string $severity, string $reference, string $message, float|string $amount = 0, ?string $currency = null): array
    {
        return compact('type', 'severity', 'reference', 'message', 'amount', 'currency');
    }
}
