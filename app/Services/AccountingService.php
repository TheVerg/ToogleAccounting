<?php

namespace App\Services;

use App\Models\Company;
use App\Models\DocumentSequence;
use App\Models\EquityTransaction;
use App\Models\Expense;
use App\Models\IntercompanyTransfer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\JournalEntry;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Turns business documents (invoices, expenses, payments, equity,
 * intercompany transfers) into balanced journal entries.
 */
class AccountingService
{
    public function __construct(
        protected JournalService $journal,
        protected ChartOfAccountsService $chart,
    ) {}

    /** Approve/send an invoice: Dr Accounts Receivable, Cr income (+ Cr Tax Payable). */
    public function issueInvoice(Invoice $invoice, ?int $userId = null): Invoice
    {
        if ($invoice->journal_entry_id) {
            return $invoice;
        }

        return DB::transaction(function () use ($invoice, $userId) {
            $company = $invoice->company;
            $receivable = $this->chart->findBySubtype($company, 'receivable');

            $lines = [[
                'account_id' => $receivable->id,
                'debit' => (float) $invoice->total,
                'credit' => 0,
                'memo' => 'Invoice '.$invoice->invoice_no,
            ]];

            foreach ($invoice->items as $item) {
                $lines[] = [
                    'account_id' => $item->account_id,
                    'debit' => 0,
                    'credit' => (float) $item->amount,
                    'memo' => $item->description,
                ];
            }

            if ((float) $invoice->tax_amount > 0) {
                $taxPayable = $this->chart->findBySubtype($company, 'tax');
                $lines[] = [
                    'account_id' => $taxPayable->id,
                    'debit' => 0,
                    'credit' => (float) $invoice->tax_amount,
                    'memo' => 'Tax on invoice '.$invoice->invoice_no,
                ];
            }

            $entry = $this->journal->post(
                $company,
                $invoice->issue_date->toDateString(),
                'Invoice '.$invoice->invoice_no.' - '.$invoice->contact->name,
                $lines,
                $invoice,
                $invoice->invoice_no,
                $userId,
            );

            $invoice->update(['status' => 'sent', 'journal_entry_id' => $entry->id]);

            return $invoice;
        });
    }

    /** Receive money against an invoice: Dr cash/bank, Cr Accounts Receivable. */
    public function recordInvoicePayment(Invoice $invoice, array $data, ?int $userId = null): InvoicePayment
    {
        $amount = round((float) $data['amount'], 2);

        return DB::transaction(function () use ($invoice, $data, $amount, $userId) {
            // Re-read the invoice under a row lock so concurrent payments
            // cannot both pass the balance check and overpay it.
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (! in_array($invoice->status, ['sent', 'partially_paid'], true)) {
                throw new InvalidArgumentException('Payments can only be recorded against issued invoices.');
            }

            if ($amount <= 0 || $amount > $invoice->balanceDue() + 0.005) {
                throw new InvalidArgumentException('Payment amount exceeds the invoice balance due.');
            }

            $company = $invoice->company;
            $receivable = $this->chart->findBySubtype($company, 'receivable');

            $payment = $invoice->payments()->create([
                'date' => $data['date'],
                'amount' => $amount,
                'deposit_account_id' => $data['deposit_account_id'],
                'method' => $data['method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'created_by' => $userId,
            ]);

            $entry = $this->journal->post(
                $company,
                $data['date'],
                'Payment received for invoice '.$invoice->invoice_no,
                [
                    ['account_id' => (int) $data['deposit_account_id'], 'debit' => $amount, 'credit' => 0],
                    ['account_id' => $receivable->id, 'debit' => 0, 'credit' => $amount],
                ],
                $payment,
                $data['reference'] ?? null,
                $userId,
            );

            $payment->update(['journal_entry_id' => $entry->id]);

            $paid = round((float) $invoice->amount_paid + $amount, 2);
            $invoice->update([
                'amount_paid' => $paid,
                'status' => $paid + 0.005 >= (float) $invoice->total ? 'paid' : 'partially_paid',
            ]);

            return $payment;
        });
    }

    /** Record an expense: Dr expense (+ Dr Tax Payable for input tax), Cr money account. */
    public function recordExpense(Company $company, array $data, ?int $userId = null): Expense
    {
        return DB::transaction(function () use ($company, $data, $userId) {
            $amount = round((float) $data['amount'], 2);
            $taxAmount = round((float) ($data['tax_amount'] ?? 0), 2);
            $total = round($amount + $taxAmount, 2);

            $expense = Expense::create([
                'company_id' => $company->id,
                'expense_no' => $this->nextNo($company, Expense::class, 'EXP'),
                'date' => $data['date'],
                'contact_id' => $data['contact_id'] ?? null,
                'account_id' => $data['account_id'],
                'paid_from_account_id' => $data['paid_from_account_id'],
                'amount' => $amount,
                'tax_rate_id' => $data['tax_rate_id'] ?? null,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'description' => $data['description'] ?? null,
                'is_petty_cash' => (bool) ($data['is_petty_cash'] ?? false),
                'recurring_expense_id' => $data['recurring_expense_id'] ?? null,
                'created_by' => $userId,
            ]);

            $lines = [
                ['account_id' => (int) $data['account_id'], 'debit' => $amount, 'credit' => 0,
                    'memo' => $data['description'] ?? null],
            ];
            if ($taxAmount > 0) {
                $taxPayable = $this->chart->findBySubtype($company, 'tax');
                $lines[] = ['account_id' => $taxPayable->id, 'debit' => $taxAmount, 'credit' => 0,
                    'memo' => 'Input tax'];
            }
            $lines[] = ['account_id' => (int) $data['paid_from_account_id'], 'debit' => 0, 'credit' => $total];

            $entry = $this->journal->post(
                $company,
                $data['date'],
                'Expense '.$expense->expense_no.($data['description'] ?? null ? ' - '.$data['description'] : ''),
                $lines,
                $expense,
                null,
                $userId,
            );

            $expense->update(['journal_entry_id' => $entry->id]);

            return $expense;
        });
    }

    /** Outgoing payment or money transfer between own accounts. */
    public function recordPayment(Company $company, array $data, ?int $userId = null): Payment
    {
        return DB::transaction(function () use ($company, $data, $userId) {
            $amount = round((float) $data['amount'], 2);
            $kind = $data['kind'];

            $debitAccountId = $kind === 'transfer'
                ? (int) $data['to_account_id']
                : (int) $data['category_account_id'];

            $payment = Payment::create([
                'company_id' => $company->id,
                'payment_no' => $this->nextNo($company, Payment::class, 'PAY'),
                'kind' => $kind,
                'date' => $data['date'],
                'contact_id' => $data['contact_id'] ?? null,
                'from_account_id' => $data['from_account_id'],
                'to_account_id' => $data['to_account_id'] ?? null,
                'category_account_id' => $data['category_account_id'] ?? null,
                'amount' => $amount,
                'method' => $data['method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'created_by' => $userId,
            ]);

            $entry = $this->journal->post(
                $company,
                $data['date'],
                ($kind === 'transfer' ? 'Transfer ' : 'Payment ').$payment->payment_no
                    .($data['description'] ?? null ? ' - '.$data['description'] : ''),
                [
                    ['account_id' => $debitAccountId, 'debit' => $amount, 'credit' => 0],
                    ['account_id' => (int) $data['from_account_id'], 'debit' => 0, 'credit' => $amount],
                ],
                $payment,
                $data['reference'] ?? null,
                $userId,
            );

            $payment->update(['journal_entry_id' => $entry->id]);

            return $payment;
        });
    }

    /** Owner's equity: contribution (money in) or drawing (money out). */
    public function recordEquityTransaction(Company $company, array $data, ?int $userId = null): EquityTransaction
    {
        return DB::transaction(function () use ($company, $data, $userId) {
            $amount = round((float) $data['amount'], 2);
            $type = $data['type'];

            $equityAccount = $type === 'contribution'
                ? $this->chart->findBySubtype($company, 'owner_equity')
                : $this->chart->findBySubtype($company, 'owner_drawings');

            $tx = EquityTransaction::create([
                'company_id' => $company->id,
                'type' => $type,
                'date' => $data['date'],
                'owner_name' => $data['owner_name'],
                'amount' => $amount,
                'money_account_id' => $data['money_account_id'],
                'equity_account_id' => $equityAccount->id,
                'description' => $data['description'] ?? null,
                'created_by' => $userId,
            ]);

            $lines = $type === 'contribution'
                ? [
                    ['account_id' => (int) $data['money_account_id'], 'debit' => $amount, 'credit' => 0],
                    ['account_id' => $equityAccount->id, 'debit' => 0, 'credit' => $amount],
                ]
                : [
                    ['account_id' => $equityAccount->id, 'debit' => $amount, 'credit' => 0],
                    ['account_id' => (int) $data['money_account_id'], 'debit' => 0, 'credit' => $amount],
                ];

            $entry = $this->journal->post(
                $company,
                $data['date'],
                ($type === 'contribution' ? "Owner's contribution - " : "Owner's drawing - ").$data['owner_name'],
                $lines,
                $tx,
                null,
                $userId,
            );

            $tx->update(['journal_entry_id' => $entry->id]);

            return $tx;
        });
    }

    /**
     * Holding company funds a subsidiary. One event, two sets of books:
     * sender books a receivable/investment, receiver books a payable/capital.
     */
    public function recordIntercompanyTransfer(array $data, ?int $userId = null): IntercompanyTransfer
    {
        return DB::transaction(function () use ($data, $userId) {
            $from = Company::findOrFail($data['from_company_id']);
            $to = Company::findOrFail($data['to_company_id']);
            if ($from->id === $to->id) {
                throw new InvalidArgumentException('Cannot transfer to the same company.');
            }

            $amount = round((float) $data['amount'], 2);
            $type = $data['type']; // loan | capital

            $transfer = IntercompanyTransfer::create([
                'from_company_id' => $from->id,
                'to_company_id' => $to->id,
                'type' => $type,
                'date' => $data['date'],
                'amount' => $amount,
                'from_money_account_id' => $data['from_money_account_id'],
                'to_money_account_id' => $data['to_money_account_id'],
                'description' => $data['description'] ?? null,
                'created_by' => $userId,
            ]);

            // Sender's books
            $senderAsset = $type === 'loan'
                ? $this->chart->findBySubtype($from, 'intercompany')                // Intercompany Receivable
                : $this->chart->findBySubtype($from, 'intercompany_investment');    // Investment in Subsidiaries

            $fromEntry = $this->journal->post(
                $from,
                $data['date'],
                ucfirst($type).' to '.$to->name,
                [
                    ['account_id' => $senderAsset->id, 'debit' => $amount, 'credit' => 0],
                    ['account_id' => (int) $data['from_money_account_id'], 'debit' => 0, 'credit' => $amount],
                ],
                $transfer,
                null,
                $userId,
            );

            // Receiver's books
            $receiverCredit = $type === 'loan'
                ? $this->receiverIntercompanyPayable($to)
                : $this->chart->findBySubtype($to, 'intercompany_capital');

            $toEntry = $this->journal->post(
                $to,
                $data['date'],
                ucfirst($type).' received from '.$from->name,
                [
                    ['account_id' => (int) $data['to_money_account_id'], 'debit' => $amount, 'credit' => 0],
                    ['account_id' => $receiverCredit->id, 'debit' => 0, 'credit' => $amount],
                ],
                $transfer,
                null,
                $userId,
            );

            $transfer->update([
                'from_journal_entry_id' => $fromEntry->id,
                'to_journal_entry_id' => $toEntry->id,
            ]);

            return $transfer;
        });
    }

    /**
     * Reverse a journal entry and keep its source document in sync with
     * the ledger: invoices are voided, invoice balances restored, and
     * other documents flagged as reversed. Intercompany transfers are
     * reversed in both companies' books at once.
     */
    public function reverseEntry(JournalEntry $entry, ?string $date = null, ?int $userId = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $date, $userId) {
            $source = $entry->source;

            if ($source instanceof Invoice) {
                $invoice = Invoice::whereKey($source->id)->lockForUpdate()->firstOrFail();
                if ((float) $invoice->amount_paid > 0) {
                    throw new InvalidArgumentException(
                        'This invoice has payments recorded; reverse the payment entries first.'
                    );
                }

                $reversal = $this->journal->reverse($entry, $date, $userId);
                $invoice->update(['status' => 'void']);

                return $reversal;
            }

            if ($source instanceof InvoicePayment) {
                $invoice = Invoice::whereKey($source->invoice_id)->lockForUpdate()->firstOrFail();

                $reversal = $this->journal->reverse($entry, $date, $userId);

                $paid = round((float) $invoice->amount_paid - (float) $source->amount, 2);
                $invoice->update([
                    'amount_paid' => max($paid, 0),
                    'status' => $paid < 0.005 ? 'sent' : 'partially_paid',
                ]);
                $source->forceFill(['reversed_at' => now()])->save();

                return $reversal;
            }

            if ($source instanceof IntercompanyTransfer) {
                $reversal = null;
                foreach ([$source->from_journal_entry_id, $source->to_journal_entry_id] as $entryId) {
                    $sideEntry = JournalEntry::findOrFail($entryId);
                    $sideReversal = $this->journal->reverse($sideEntry, $date, $userId);
                    if ($sideEntry->id === $entry->id) {
                        $reversal = $sideReversal;
                    }
                }
                $source->forceFill(['reversed_at' => now()])->save();

                return $reversal;
            }

            $reversal = $this->journal->reverse($entry, $date, $userId);

            if ($source instanceof Expense || $source instanceof Payment || $source instanceof EquityTransaction) {
                $source->forceFill(['reversed_at' => now()])->save();
            }

            return $reversal;
        });
    }

    protected function receiverIntercompanyPayable(Company $company)
    {
        return $company->accounts()
            ->where('type', 'liability')
            ->where('subtype', 'intercompany')
            ->orderBy('code')
            ->firstOrFail();
    }

    protected function nextNo(Company $company, string $model, string $prefix): string
    {
        $instance = new $model;

        return DocumentSequence::nextNo(
            $company->id,
            $prefix,
            $instance->getTable(),
            Str::snake(class_basename($model)).'_no',
        );
    }
}
