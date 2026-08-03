<?php

namespace App\Services;

use App\Jobs\FiscaliseInvoice;
use App\Models\Account;
use App\Models\Company;
use App\Models\Dividend;
use App\Models\FiscalDevice;
use App\Models\DividendAllocation;
use App\Models\DividendPayment;
use App\Models\DocumentSequence;
use App\Models\EquityTransaction;
use App\Models\Expense;
use App\Models\IntercompanyTransfer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Shareholder;
use App\Models\ShareholderLoan;
use App\Models\ShareholderLoanRepayment;
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
        protected ExchangeRateService $fx,
    ) {}

    /** Fetch an account, scoped to the company. */
    protected function account(Company $company, int $id): Account
    {
        return Account::where('company_id', $company->id)->findOrFail($id);
    }

    /** The transaction-currency columns shared by every line of an entry. */
    protected function lineFx(string $currency, float $rate): array
    {
        return ['currency' => $currency, 'fx_rate' => $rate];
    }

    /** Approve/send an invoice: Dr Accounts Receivable, Cr income (+ Cr Tax Payable). */
    public function issueInvoice(Invoice $invoice, ?int $userId = null): Invoice
    {
        if ($invoice->journal_entry_id) {
            return $invoice;
        }

        return DB::transaction(function () use ($invoice, $userId) {
            $company = $invoice->company;
            $receivable = $this->chart->findBySubtype($company, 'receivable');

            $currency = $invoice->currency ?: $company->currency;
            $rate = $currency === $company->currency
                ? 1.0
                : $this->fx->resolve($company, $currency, $invoice->issue_date->toDateString(), $invoice->exchange_rate);
            $fx = $this->lineFx($currency, $rate);

            // The ledger records base-currency amounts; base totals are
            // built from the rounded parts so the entry balances exactly.
            $baseSubtotal = 0.0;
            $itemLines = [];
            foreach ($invoice->items as $item) {
                $baseItem = $this->fx->toBase((float) $item->amount, $rate);
                $baseSubtotal = round($baseSubtotal + $baseItem, 2);
                $itemLines[] = [
                    'account_id' => $item->account_id,
                    'debit' => 0,
                    'credit' => $baseItem,
                    'memo' => $item->description,
                    'currency_credit' => (float) $item->amount,
                    ...$fx,
                ];
            }

            $baseTax = $this->fx->toBase((float) $invoice->tax_amount, $rate);
            $baseTotal = round($baseSubtotal + $baseTax, 2);

            $lines = [[
                'account_id' => $receivable->id,
                'debit' => $baseTotal,
                'credit' => 0,
                'memo' => 'Invoice '.$invoice->invoice_no,
                'currency_debit' => (float) $invoice->total,
                ...$fx,
            ], ...$itemLines];

            if ((float) $invoice->tax_amount > 0) {
                // Tax lives in the currency of trade (ZIMRA requirement).
                $taxPayable = $this->chart->taxAccountFor($company, $currency);
                $lines[] = [
                    'account_id' => $taxPayable->id,
                    'debit' => 0,
                    'credit' => $baseTax,
                    'memo' => 'Tax on invoice '.$invoice->invoice_no,
                    'currency_credit' => (float) $invoice->tax_amount,
                    ...$fx,
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

            $invoice->update([
                'status' => 'sent',
                'journal_entry_id' => $entry->id,
                'currency' => $currency,
                'exchange_rate' => $rate,
                'base_subtotal' => $baseSubtotal,
                'base_tax_amount' => $baseTax,
                'base_total' => $baseTotal,
            ]);

            // Fiscalise at the moment of issue (queued: a ZIMRA outage
            // never blocks invoicing). No-op when no device is active.
            if (FiscalDevice::where('company_id', $company->id)->where('status', 'active')->exists()) {
                FiscaliseInvoice::dispatch($invoice)->afterCommit();
            }

            return $invoice;
        });
    }

    /**
     * Receive money against an invoice: Dr cash/bank, Cr Accounts
     * Receivable. Payments settle in the invoice's currency; when the
     * rate has moved since issue, the difference between the receivable's
     * booked base value and the money's base value at the payment-date
     * rate is posted automatically as a realised exchange gain/loss.
     */
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
            $currency = $invoice->currency ?: $company->currency;

            $deposit = $this->account($company, (int) $data['deposit_account_id']);
            if ($deposit->effectiveCurrency() !== $currency) {
                throw new InvalidArgumentException(
                    "This invoice is in {$currency}; deposit the payment into a {$currency} account."
                );
            }

            $rate = $this->fx->resolve($company, $currency, $data['date'], $data['exchange_rate'] ?? null);
            $baseAmount = $this->fx->toBase($amount, $rate);

            // The receivable is relieved at its booked (issue-date) value;
            // the final payment absorbs any rounding remainder.
            $paid = round((float) $invoice->amount_paid + $amount, 2);
            $isFinal = $paid + 0.005 >= (float) $invoice->total;
            $baseSettled = $isFinal
                ? round((float) $invoice->base_total - (float) $invoice->base_amount_paid, 2)
                : round($amount * (float) $invoice->base_total / (float) $invoice->total, 2);
            $gain = round($baseAmount - $baseSettled, 2);

            $payment = $invoice->payments()->create([
                'date' => $data['date'],
                'amount' => $amount,
                'deposit_account_id' => $data['deposit_account_id'],
                'method' => $data['method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'created_by' => $userId,
                'currency' => $currency,
                'exchange_rate' => $rate,
                'base_amount' => $baseAmount,
                'fx_gain_loss' => $gain,
            ]);

            $fx = $this->lineFx($currency, $rate);
            $lines = [
                ['account_id' => $deposit->id, 'debit' => $baseAmount, 'credit' => 0,
                    'currency_debit' => $amount, ...$fx],
                ['account_id' => $receivable->id, 'debit' => 0, 'credit' => $baseSettled,
                    'currency_credit' => $amount, ...$fx],
            ];
            if (abs($gain) >= 0.01) {
                $fxAccount = $this->chart->fxAccount($company, realised: true);
                $lines[] = [
                    'account_id' => $fxAccount->id,
                    'debit' => $gain < 0 ? -$gain : 0,
                    'credit' => $gain > 0 ? $gain : 0,
                    'memo' => 'Realised exchange '.($gain > 0 ? 'gain' : 'loss').' on invoice '.$invoice->invoice_no,
                    'currency_debit' => 0, 'currency_credit' => 0,
                    ...$fx,
                ];
            }

            $entry = $this->journal->post(
                $company,
                $data['date'],
                'Payment received for invoice '.$invoice->invoice_no,
                $lines,
                $payment,
                $data['reference'] ?? null,
                $userId,
            );

            $payment->update(['journal_entry_id' => $entry->id]);

            $invoice->update([
                'amount_paid' => $paid,
                'base_amount_paid' => round((float) $invoice->base_amount_paid + $baseSettled, 2),
                'status' => $isFinal ? 'paid' : 'partially_paid',
            ]);

            return $payment;
        });
    }

    /**
     * Record an expense: Dr expense (+ Dr Tax Payable for input tax),
     * Cr money account. The expense happens in the currency of the
     * account it was paid from; the ledger records the base equivalent.
     */
    public function recordExpense(Company $company, array $data, ?int $userId = null): Expense
    {
        return DB::transaction(function () use ($company, $data, $userId) {
            $amount = round((float) $data['amount'], 2);
            $taxAmount = round((float) ($data['tax_amount'] ?? 0), 2);
            $total = round($amount + $taxAmount, 2);

            $paidFrom = $this->account($company, (int) $data['paid_from_account_id']);
            $currency = $paidFrom->effectiveCurrency();
            $rate = $this->fx->resolve($company, $currency, $data['date'], $data['exchange_rate'] ?? null);
            $baseAmount = $this->fx->toBase($amount, $rate);
            $baseTax = $this->fx->toBase($taxAmount, $rate);
            $baseTotal = round($baseAmount + $baseTax, 2);
            $fx = $this->lineFx($currency, $rate);

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
                'currency' => $currency,
                'exchange_rate' => $rate,
                'base_amount' => $baseAmount,
                'base_tax_amount' => $baseTax,
                'base_total' => $baseTotal,
            ]);

            $lines = [
                ['account_id' => (int) $data['account_id'], 'debit' => $baseAmount, 'credit' => 0,
                    'memo' => $data['description'] ?? null, 'currency_debit' => $amount, ...$fx],
            ];
            if ($taxAmount > 0) {
                // Input tax offsets the tax payable of the same currency.
                $taxPayable = $this->chart->taxAccountFor($company, $currency);
                $lines[] = ['account_id' => $taxPayable->id, 'debit' => $baseTax, 'credit' => 0,
                    'memo' => 'Input tax', 'currency_debit' => $taxAmount, ...$fx];
            }
            $lines[] = ['account_id' => $paidFrom->id, 'debit' => 0, 'credit' => $baseTotal,
                'currency_credit' => $total, ...$fx];

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

    /**
     * Outgoing payment or money transfer between own accounts. The
     * payment happens in the from-account's currency. A transfer between
     * accounts of different currencies (e.g. converting USD to ZWG at
     * the bank) needs the amount received on the other side; any
     * difference in base value is a realised exchange gain/loss.
     */
    public function recordPayment(Company $company, array $data, ?int $userId = null): Payment
    {
        return DB::transaction(function () use ($company, $data, $userId) {
            $amount = round((float) $data['amount'], 2);
            $kind = $data['kind'];

            $from = $this->account($company, (int) $data['from_account_id']);
            $currency = $from->effectiveCurrency();
            $rate = $this->fx->resolve($company, $currency, $data['date'], $data['exchange_rate'] ?? null);
            $baseAmount = $this->fx->toBase($amount, $rate);
            $fx = $this->lineFx($currency, $rate);

            $toAmount = null;
            $toRate = null;
            $baseTo = null;
            $lines = [];

            if ($kind === 'transfer') {
                $to = $this->account($company, (int) $data['to_account_id']);
                $toCurrency = $to->effectiveCurrency();

                if ($toCurrency === $currency) {
                    $lines = [
                        ['account_id' => $to->id, 'debit' => $baseAmount, 'credit' => 0,
                            'currency_debit' => $amount, ...$fx],
                        ['account_id' => $from->id, 'debit' => 0, 'credit' => $baseAmount,
                            'currency_credit' => $amount, ...$fx],
                    ];
                } else {
                    $toAmount = round((float) ($data['to_amount'] ?? 0), 2);
                    if ($toAmount <= 0) {
                        throw new InvalidArgumentException(
                            "Transferring from a {$currency} account to a {$toCurrency} account needs the amount received in {$toCurrency}."
                        );
                    }
                    $toRate = $this->fx->resolve($company, $toCurrency, $data['date'], $data['to_exchange_rate'] ?? null);
                    $baseTo = $this->fx->toBase($toAmount, $toRate);
                    $diff = round($baseAmount - $baseTo, 2); // positive = lost base value on conversion

                    $lines = [
                        ['account_id' => $to->id, 'debit' => $baseTo, 'credit' => 0,
                            'currency_debit' => $toAmount, 'currency' => $toCurrency, 'fx_rate' => $toRate],
                        ['account_id' => $from->id, 'debit' => 0, 'credit' => $baseAmount,
                            'currency_credit' => $amount, ...$fx],
                    ];
                    if (abs($diff) >= 0.01) {
                        $fxAccount = $this->chart->fxAccount($company, realised: true);
                        $lines[] = [
                            'account_id' => $fxAccount->id,
                            'debit' => $diff > 0 ? $diff : 0,
                            'credit' => $diff < 0 ? -$diff : 0,
                            'memo' => 'Realised exchange '.($diff > 0 ? 'loss' : 'gain').' on conversion',
                            'currency_debit' => 0, 'currency_credit' => 0,
                            ...$fx,
                        ];
                    }
                }
            } else {
                $category = $this->account($company, (int) $data['category_account_id']);
                $lines = [
                    ['account_id' => $category->id, 'debit' => $baseAmount, 'credit' => 0,
                        'currency_debit' => $amount, ...$fx],
                    ['account_id' => $from->id, 'debit' => 0, 'credit' => $baseAmount,
                        'currency_credit' => $amount, ...$fx],
                ];
            }

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
                'currency' => $currency,
                'exchange_rate' => $rate,
                'base_amount' => $baseAmount,
                'to_amount' => $toAmount,
                'to_exchange_rate' => $toRate,
                'base_to_amount' => $baseTo,
            ]);

            $entry = $this->journal->post(
                $company,
                $data['date'],
                ($kind === 'transfer' ? 'Transfer ' : 'Payment ').$payment->payment_no
                    .($data['description'] ?? null ? ' - '.$data['description'] : ''),
                $lines,
                $payment,
                $data['reference'] ?? null,
                $userId,
            );

            $payment->update(['journal_entry_id' => $entry->id]);

            return $payment;
        });
    }

    /**
     * Equity: contribution (money in), drawing (money out), or share
     * issue (money in, split between share capital and share premium).
     */
    public function recordEquityTransaction(Company $company, array $data, ?int $userId = null): EquityTransaction
    {
        return DB::transaction(function () use ($company, $data, $userId) {
            $amount = round((float) $data['amount'], 2);
            $type = $data['type'];

            if ($type === 'share_issue') {
                return $this->recordShareIssue($company, $data, $amount, $userId);
            }

            $equityAccount = $type === 'contribution'
                ? $this->chart->findBySubtype($company, 'owner_equity')
                : $this->chart->findBySubtype($company, 'owner_drawings');

            $moneyAccount = $this->account($company, (int) $data['money_account_id']);
            $currency = $moneyAccount->effectiveCurrency();
            $rate = $this->fx->resolve($company, $currency, $data['date'], $data['exchange_rate'] ?? null);
            $baseAmount = $this->fx->toBase($amount, $rate);
            $fx = $this->lineFx($currency, $rate);

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
                'currency' => $currency,
                'exchange_rate' => $rate,
                'base_amount' => $baseAmount,
            ]);

            $lines = $type === 'contribution'
                ? [
                    ['account_id' => $moneyAccount->id, 'debit' => $baseAmount, 'credit' => 0,
                        'currency_debit' => $amount, ...$fx],
                    ['account_id' => $equityAccount->id, 'debit' => 0, 'credit' => $baseAmount,
                        'currency_credit' => $amount, ...$fx],
                ]
                : [
                    ['account_id' => $equityAccount->id, 'debit' => $baseAmount, 'credit' => 0,
                        'currency_debit' => $amount, ...$fx],
                    ['account_id' => $moneyAccount->id, 'debit' => 0, 'credit' => $baseAmount,
                        'currency_credit' => $amount, ...$fx],
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
     * Shares issued for cash: Dr money for the full consideration,
     * Cr Share Capital for the nominal value, Cr Share Premium for the
     * excess paid over nominal. When issued to a registered shareholder,
     * their shares held in the register grow by the shares issued.
     */
    protected function recordShareIssue(Company $company, array $data, float $amount, ?int $userId): EquityTransaction
    {
        $capital = round((float) $data['share_capital_amount'], 2);
        $premium = round($amount - $capital, 2);

        if ($capital <= 0 || $capital > $amount) {
            throw new InvalidArgumentException(
                'The share capital (nominal) portion must be positive and cannot exceed the total consideration.'
            );
        }

        $shareholder = null;
        if (! empty($data['shareholder_id'])) {
            $shareholder = Shareholder::where('company_id', $company->id)
                ->whereKey($data['shareholder_id'])->lockForUpdate()->firstOrFail();
        }

        $this->chart->ensureShareCapitalAccounts($company);
        $capitalAccount = $this->chart->findBySubtype($company, 'share_capital');
        $premiumAccount = $this->chart->findBySubtype($company, 'share_premium');

        $moneyAccount = $this->account($company, (int) $data['money_account_id']);
        $currency = $moneyAccount->effectiveCurrency();
        $rate = $this->fx->resolve($company, $currency, $data['date'], $data['exchange_rate'] ?? null);
        $baseAmount = $this->fx->toBase($amount, $rate);
        $baseCapital = $this->fx->toBase($capital, $rate);
        $basePremium = round($baseAmount - $baseCapital, 2);
        $fx = $this->lineFx($currency, $rate);

        $tx = EquityTransaction::create([
            'company_id' => $company->id,
            'type' => 'share_issue',
            'date' => $data['date'],
            'owner_name' => $shareholder?->name ?? $data['owner_name'],
            'shareholder_id' => $shareholder?->id,
            'shares_count' => $data['shares_count'] ?? null,
            'amount' => $amount,
            'share_capital_amount' => $capital,
            'share_premium_amount' => $premium,
            'money_account_id' => $data['money_account_id'],
            'equity_account_id' => $capitalAccount->id,
            'premium_account_id' => $premium > 0 ? $premiumAccount->id : null,
            'description' => $data['description'] ?? null,
            'created_by' => $userId,
            'currency' => $currency,
            'exchange_rate' => $rate,
            'base_amount' => $baseAmount,
        ]);

        $lines = [
            ['account_id' => $moneyAccount->id, 'debit' => $baseAmount, 'credit' => 0,
                'currency_debit' => $amount, ...$fx],
            ['account_id' => $capitalAccount->id, 'debit' => 0, 'credit' => $baseCapital,
                'memo' => 'Nominal value', 'currency_credit' => $capital, ...$fx],
        ];
        if ($basePremium > 0) {
            $lines[] = ['account_id' => $premiumAccount->id, 'debit' => 0, 'credit' => $basePremium,
                'memo' => 'Premium over nominal', 'currency_credit' => $premium, ...$fx];
        }

        $shares = $tx->shares_count ? number_format($tx->shares_count).' shares - ' : '';
        $entry = $this->journal->post(
            $company,
            $data['date'],
            'Share issue - '.$shares.$tx->owner_name,
            $lines,
            $tx,
            null,
            $userId,
        );

        $tx->update(['journal_entry_id' => $entry->id]);

        if ($shareholder && $tx->shares_count) {
            $shareholder->update([
                'shares_held' => round((float) $shareholder->shares_held + $tx->shares_count, 2),
            ]);
        }

        return $tx;
    }

    /** Shareholder lends money to the company: Dr money, Cr Shareholder Loans. */
    public function recordShareholderLoan(Company $company, array $data, ?int $userId = null): ShareholderLoan
    {
        return DB::transaction(function () use ($company, $data, $userId) {
            $principal = round((float) $data['principal'], 2);
            $liabilityAccount = $this->chart->ensureShareholderLoanAccount($company);

            $shareholder = null;
            if (! empty($data['shareholder_id'])) {
                $shareholder = Shareholder::where('company_id', $company->id)
                    ->findOrFail($data['shareholder_id']);
            }

            $moneyAccount = $this->account($company, (int) $data['money_account_id']);
            $currency = $moneyAccount->effectiveCurrency();
            $rate = $this->fx->resolve($company, $currency, $data['date'], $data['exchange_rate'] ?? null);
            $basePrincipal = $this->fx->toBase($principal, $rate);
            $fx = $this->lineFx($currency, $rate);

            $loan = ShareholderLoan::create([
                'company_id' => $company->id,
                'loan_no' => DocumentSequence::nextNo($company->id, 'SHL', 'shareholder_loans', 'loan_no'),
                'lender_name' => $shareholder?->name ?? $data['lender_name'],
                'shareholder_id' => $shareholder?->id,
                'date' => $data['date'],
                'principal' => $principal,
                'outstanding_balance' => $principal,
                'status' => 'active',
                'description' => $data['description'] ?? null,
                'money_account_id' => $data['money_account_id'],
                'liability_account_id' => $liabilityAccount->id,
                'created_by' => $userId,
                'currency' => $currency,
                'exchange_rate' => $rate,
                'base_principal' => $basePrincipal,
            ]);

            $entry = $this->journal->post(
                $company,
                $data['date'],
                'Shareholder loan '.$loan->loan_no.' from '.$loan->lender_name,
                [
                    ['account_id' => $moneyAccount->id, 'debit' => $basePrincipal, 'credit' => 0,
                        'memo' => $loan->loan_no, 'currency_debit' => $principal, ...$fx],
                    ['account_id' => $liabilityAccount->id, 'debit' => 0, 'credit' => $basePrincipal,
                        'memo' => $loan->loan_no, 'currency_credit' => $principal, ...$fx],
                ],
                $loan,
                $loan->loan_no,
                $userId,
            );

            $loan->update(['journal_entry_id' => $entry->id]);

            return $loan;
        });
    }

    /** Repay part or all of a shareholder loan: Dr Shareholder Loans, Cr money. */
    public function recordShareholderLoanRepayment(ShareholderLoan $loan, array $data, ?int $userId = null): ShareholderLoanRepayment
    {
        $amount = round((float) $data['amount'], 2);

        return DB::transaction(function () use ($loan, $data, $amount, $userId) {
            // Row lock so concurrent repayments cannot both pass the
            // outstanding-balance check and overpay the loan.
            $loan = ShareholderLoan::whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if ($loan->status !== 'active') {
                throw new InvalidArgumentException('Repayments can only be recorded against active loans.');
            }
            if ($amount <= 0 || $amount > (float) $loan->outstanding_balance + 0.005) {
                throw new InvalidArgumentException('Repayment amount exceeds the outstanding loan balance.');
            }

            $company = $loan->company;
            $currency = $loan->currency ?: $company->currency;
            $moneyAccount = $this->account($company, (int) $data['money_account_id']);
            if ($moneyAccount->effectiveCurrency() !== $currency) {
                throw new InvalidArgumentException(
                    "This loan is in {$currency}; repay it from a {$currency} account."
                );
            }

            $rate = $this->fx->resolve($company, $currency, $data['date'], $data['exchange_rate'] ?? null);
            $baseAmount = $this->fx->toBase($amount, $rate);

            // The liability is relieved at its booked value; the final
            // repayment absorbs any rounding remainder. Rate movement
            // since the loan was received is a realised gain/loss.
            $repaid = round((float) $loan->total_repaid + $amount, 2);
            $isFinal = $repaid + 0.005 >= (float) $loan->principal;
            $priorSettled = round((float) $loan->base_principal * (float) $loan->total_repaid / (float) $loan->principal, 2);
            $baseSettled = $isFinal
                ? round((float) $loan->base_principal - $priorSettled, 2)
                : round($amount * (float) $loan->base_principal / (float) $loan->principal, 2);
            $gain = round($baseSettled - $baseAmount, 2); // owed less base than booked = gain

            $repayment = $loan->repayments()->create([
                'company_id' => $loan->company_id,
                'date' => $data['date'],
                'amount' => $amount,
                'reference' => $data['reference'] ?? null,
                'money_account_id' => $data['money_account_id'],
                'created_by' => $userId,
                'exchange_rate' => $rate,
                'base_amount' => $baseAmount,
                'fx_gain_loss' => $gain,
            ]);

            $fx = $this->lineFx($currency, $rate);
            $lines = [
                ['account_id' => $loan->liability_account_id, 'debit' => $baseSettled, 'credit' => 0,
                    'memo' => $loan->loan_no, 'currency_debit' => $amount, ...$fx],
                ['account_id' => $moneyAccount->id, 'debit' => 0, 'credit' => $baseAmount,
                    'memo' => $loan->loan_no, 'currency_credit' => $amount, ...$fx],
            ];
            if (abs($gain) >= 0.01) {
                $fxAccount = $this->chart->fxAccount($company, realised: true);
                $lines[] = [
                    'account_id' => $fxAccount->id,
                    'debit' => $gain < 0 ? -$gain : 0,
                    'credit' => $gain > 0 ? $gain : 0,
                    'memo' => 'Realised exchange '.($gain > 0 ? 'gain' : 'loss').' on loan '.$loan->loan_no,
                    'currency_debit' => 0, 'currency_credit' => 0,
                    ...$fx,
                ];
            }

            $entry = $this->journal->post(
                $company,
                $data['date'],
                'Repayment of shareholder loan '.$loan->loan_no.' to '.$loan->lender_name,
                $lines,
                $repayment,
                $data['reference'] ?? null,
                $userId,
            );

            $repayment->update(['journal_entry_id' => $entry->id]);

            $repaid = round((float) $loan->total_repaid + $amount, 2);
            $outstanding = round((float) $loan->principal - $repaid, 2);
            $loan->update([
                'total_repaid' => $repaid,
                'outstanding_balance' => max($outstanding, 0),
                'status' => $outstanding < 0.005 ? 'repaid' : 'active',
            ]);

            return $repayment;
        });
    }

    /**
     * One-time cutover entry that brings an existing business onto the
     * system: every account's real balance as at the cutover date, with
     * any difference posted openly to a chosen equity account. Guarded
     * to a single posted opening entry per company; optionally locks
     * all periods before the cutover.
     */
    public function recordOpeningBalances(Company $company, array $data, ?int $userId = null): JournalEntry
    {
        return DB::transaction(function () use ($company, $data, $userId) {
            $existing = JournalEntry::where('company_id', $company->id)
                ->where('reference', 'OPENING')
                ->where('status', 'posted')
                ->first();
            if ($existing) {
                throw new InvalidArgumentException(
                    "An opening balance entry already exists ({$existing->entry_no}). Reverse or omit it from the Journal before entering new opening balances."
                );
            }

            $lines = [];
            $debits = 0.0;
            $credits = 0.0;
            foreach ($data['lines'] as $line) {
                $debit = round((float) ($line['debit'] ?? 0), 2);
                $credit = round((float) ($line['credit'] ?? 0), 2);
                if ($debit <= 0 && $credit <= 0) {
                    continue;
                }
                if ($debit > 0 && $credit > 0) {
                    throw new InvalidArgumentException('Enter each account balance on one side only (debit or credit).');
                }

                // Foreign-currency accounts: the balance is entered in the
                // account's own currency and converted at the cutover-date
                // rate, so the currency balance carries over correctly.
                $account = $this->account($company, (int) $line['account_id']);
                $currency = $account->currency ?: $company->currency;
                if ($currency !== $company->currency) {
                    $rate = $this->fx->resolve($company, $currency, $data['date'], $line['exchange_rate'] ?? null);
                    $lines[] = [
                        'account_id' => $account->id,
                        'debit' => $this->fx->toBase($debit, $rate),
                        'credit' => $this->fx->toBase($credit, $rate),
                        'memo' => 'Opening balance',
                        'currency' => $currency,
                        'fx_rate' => $rate,
                        'currency_debit' => $debit,
                        'currency_credit' => $credit,
                    ];
                    $debits = round($debits + $this->fx->toBase($debit, $rate), 2);
                    $credits = round($credits + $this->fx->toBase($credit, $rate), 2);

                    continue;
                }

                $lines[] = [
                    'account_id' => $account->id,
                    'debit' => $debit,
                    'credit' => $credit,
                    'memo' => 'Opening balance',
                ];
                $debits = round($debits + $debit, 2);
                $credits = round($credits + $credit, 2);
            }

            if ($lines === []) {
                throw new InvalidArgumentException('Enter at least one opening balance.');
            }

            // Post the difference openly to the chosen equity account so
            // the entry balances without hiding anything.
            $difference = round($debits - $credits, 2);
            if ($difference != 0.0) {
                $balancing = Account::where('company_id', $company->id)
                    ->where('type', 'equity')
                    ->findOrFail((int) $data['balancing_account_id']);
                $lines[] = [
                    'account_id' => $balancing->id,
                    'debit' => $difference < 0 ? -$difference : 0,
                    'credit' => $difference > 0 ? $difference : 0,
                    'memo' => 'Opening balance equity (balancing figure)',
                ];
            }

            $entry = $this->journal->post(
                $company,
                $data['date'],
                'Opening balances as at '.$data['date'],
                $lines,
                null,
                'OPENING',
                $userId,
            );

            if (! empty($data['lock_prior_periods'])) {
                $company->update(['locked_before' => $data['date']]);
            }

            return $entry;
        });
    }

    /**
     * Profit available to distribute: accumulated earnings (all-time
     * income minus expenses) less dividends already declared.
     */
    public function distributableProfit(Company $company): float
    {
        $sums = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->whereIn('je.status', ['posted', 'reversed'])
            ->where('je.company_id', $company->id)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN a.type = 'income' THEN jl.credit - jl.debit ELSE 0 END), 0) as income,
                COALESCE(SUM(CASE WHEN a.type = 'expense' THEN jl.debit - jl.credit ELSE 0 END), 0) as expense,
                COALESCE(SUM(CASE WHEN a.subtype = 'dividends_declared' THEN jl.debit - jl.credit ELSE 0 END), 0) as declared
            ")
            ->first();

        return round((float) $sums->income - (float) $sums->expense - (float) $sums->declared, 2);
    }

    /**
     * Declare a dividend: the total is split across active shareholders
     * in proportion to their shares on the declaration date, and posted
     * as Dr Dividends Declared (contra-equity), Cr Dividends Payable.
     * Capped at distributable profit.
     */
    public function declareDividend(Company $company, array $data, ?int $userId = null): Dividend
    {
        return DB::transaction(function () use ($company, $data, $userId) {
            $total = round((float) $data['total_amount'], 2);

            $shareholders = Shareholder::where('company_id', $company->id)
                ->where('is_active', true)->where('shares_held', '>', 0)
                ->lockForUpdate()->orderBy('name')->get();
            if ($shareholders->isEmpty()) {
                throw new InvalidArgumentException(
                    'Register at least one active shareholder with shares before declaring a dividend.'
                );
            }

            $distributable = $this->distributableProfit($company);
            if ($total > $distributable + 0.005) {
                throw new InvalidArgumentException(
                    'Only '.number_format(max($distributable, 0), 2).' of retained profit is available to distribute.'
                );
            }

            $this->chart->ensureDividendAccounts($company);
            $declared = $this->chart->findBySubtype($company, 'dividends_declared');
            $payable = $this->chart->findBySubtype($company, 'dividend_payable');

            $dividend = Dividend::create([
                'company_id' => $company->id,
                'dividend_no' => DocumentSequence::nextNo($company->id, 'DIV', 'dividends', 'dividend_no'),
                'date' => $data['date'],
                'total_amount' => $total,
                'status' => 'declared',
                'description' => $data['description'] ?? null,
                'created_by' => $userId,
            ]);

            // Split by shareholding; the last allocation absorbs any
            // rounding remainder so the pieces always sum to the total.
            $totalShares = (float) $shareholders->sum('shares_held');
            $allocated = 0.0;
            foreach ($shareholders as $i => $shareholder) {
                $amount = $i === $shareholders->count() - 1
                    ? round($total - $allocated, 2)
                    : round($total * (float) $shareholder->shares_held / $totalShares, 2);
                $allocated = round($allocated + $amount, 2);

                $dividend->allocations()->create([
                    'company_id' => $company->id,
                    'shareholder_id' => $shareholder->id,
                    'shareholder_name' => $shareholder->name,
                    'shares' => $shareholder->shares_held,
                    'percent' => round((float) $shareholder->shares_held / $totalShares * 100, 4),
                    'amount' => $amount,
                ]);
            }

            $entry = $this->journal->post(
                $company,
                $data['date'],
                'Dividend '.$dividend->dividend_no.' declared ('.$shareholders->count().' shareholders)',
                [
                    ['account_id' => $declared->id, 'debit' => $total, 'credit' => 0, 'memo' => $dividend->dividend_no],
                    ['account_id' => $payable->id, 'debit' => 0, 'credit' => $total, 'memo' => $dividend->dividend_no],
                ],
                $dividend,
                $dividend->dividend_no,
                $userId,
            );

            $dividend->update(['journal_entry_id' => $entry->id]);

            return $dividend;
        });
    }

    /** Pay (part of) one shareholder's dividend: Dr Dividends Payable, Cr money. */
    public function recordDividendPayment(DividendAllocation $allocation, array $data, ?int $userId = null): DividendPayment
    {
        $amount = round((float) $data['amount'], 2);

        return DB::transaction(function () use ($allocation, $data, $amount, $userId) {
            $allocation = DividendAllocation::whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            $dividend = Dividend::whereKey($allocation->dividend_id)->lockForUpdate()->firstOrFail();
            $company = $dividend->company;

            if ($dividend->status === 'reversed') {
                throw new InvalidArgumentException('This dividend declaration has been reversed.');
            }

            // Dividends are declared in base currency but may be paid from
            // a foreign-currency account; what settles the payable is the
            // payment's base value at the payment-date rate.
            $moneyAccount = $this->account($company, (int) $data['money_account_id']);
            $currency = $moneyAccount->effectiveCurrency();
            $rate = $this->fx->resolve($company, $currency, $data['date'], $data['exchange_rate'] ?? null);
            $baseAmount = $this->fx->toBase($amount, $rate);

            if ($amount <= 0 || $baseAmount > $allocation->outstanding() + 0.005) {
                throw new InvalidArgumentException("Payment exceeds this shareholder's unpaid dividend balance.");
            }

            $payment = $allocation->payments()->create([
                'company_id' => $company->id,
                'date' => $data['date'],
                'amount' => $amount,
                'reference' => $data['reference'] ?? null,
                'money_account_id' => $data['money_account_id'],
                'created_by' => $userId,
                'currency' => $currency,
                'exchange_rate' => $rate,
                'base_amount' => $baseAmount,
            ]);

            $fx = $this->lineFx($currency, $rate);
            $payable = $this->chart->findBySubtype($company, 'dividend_payable');
            $entry = $this->journal->post(
                $company,
                $data['date'],
                'Dividend '.$dividend->dividend_no.' paid to '.$allocation->shareholder_name,
                [
                    ['account_id' => $payable->id, 'debit' => $baseAmount, 'credit' => 0,
                        'memo' => $dividend->dividend_no, 'currency_debit' => $amount, ...$fx],
                    ['account_id' => $moneyAccount->id, 'debit' => 0, 'credit' => $baseAmount,
                        'memo' => $dividend->dividend_no, 'currency_credit' => $amount, ...$fx],
                ],
                $payment,
                $data['reference'] ?? null,
                $userId,
            );

            $payment->update(['journal_entry_id' => $entry->id]);

            $allocation->update(['paid_amount' => round((float) $allocation->paid_amount + $baseAmount, 2)]);
            $dividend->refreshStatus();

            return $payment;
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

            // One movement of money, so one currency: both money accounts
            // must hold it. Each company then books its own base value.
            $fromAccount = $this->account($from, (int) $data['from_money_account_id']);
            $toAccount = $this->account($to, (int) $data['to_money_account_id']);
            $currency = $fromAccount->effectiveCurrency();
            if ($toAccount->effectiveCurrency() !== $currency) {
                throw new InvalidArgumentException(
                    'Both money accounts must hold the same currency for an intercompany transfer '
                    .'('.$currency.' vs '.$toAccount->effectiveCurrency().').'
                );
            }

            $fromRate = $this->fx->resolve($from, $currency, $data['date'], $data['from_exchange_rate'] ?? null);
            $toRate = $this->fx->resolve($to, $currency, $data['date'], $data['to_exchange_rate'] ?? null);
            $fromBase = $this->fx->toBase($amount, $fromRate);
            $toBase = $this->fx->toBase($amount, $toRate);

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
                'currency' => $currency,
                'from_exchange_rate' => $fromRate,
                'to_exchange_rate' => $toRate,
                'from_base_amount' => $fromBase,
                'to_base_amount' => $toBase,
            ]);

            // Sender's books
            $senderAsset = $type === 'loan'
                ? $this->chart->findBySubtype($from, 'intercompany')                // Intercompany Receivable
                : $this->chart->findBySubtype($from, 'intercompany_investment');    // Investment in Subsidiaries

            $fromFx = $this->lineFx($currency, $fromRate);
            $fromEntry = $this->journal->post(
                $from,
                $data['date'],
                ucfirst($type).' to '.$to->name,
                [
                    ['account_id' => $senderAsset->id, 'debit' => $fromBase, 'credit' => 0,
                        'currency_debit' => $amount, ...$fromFx],
                    ['account_id' => $fromAccount->id, 'debit' => 0, 'credit' => $fromBase,
                        'currency_credit' => $amount, ...$fromFx],
                ],
                $transfer,
                null,
                $userId,
            );

            // Receiver's books
            $receiverCredit = $type === 'loan'
                ? $this->receiverIntercompanyPayable($to)
                : $this->chart->findBySubtype($to, 'intercompany_capital');

            $toFx = $this->lineFx($currency, $toRate);
            $toEntry = $this->journal->post(
                $to,
                $data['date'],
                ucfirst($type).' received from '.$from->name,
                [
                    ['account_id' => $toAccount->id, 'debit' => $toBase, 'credit' => 0,
                        'currency_debit' => $amount, ...$toFx],
                    ['account_id' => $receiverCredit->id, 'debit' => 0, 'credit' => $toBase,
                        'currency_credit' => $amount, ...$toFx],
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
                // The payment relieved the receivable at its booked value
                // (base_amount less the realised gain); put that back too.
                $baseSettled = round((float) $source->base_amount - (float) $source->fx_gain_loss, 2);
                $invoice->update([
                    'amount_paid' => max($paid, 0),
                    'base_amount_paid' => max(round((float) $invoice->base_amount_paid - $baseSettled, 2), 0),
                    'status' => $paid < 0.005 ? 'sent' : 'partially_paid',
                ]);
                $source->forceFill(['reversed_at' => now()])->save();

                return $reversal;
            }

            if ($source instanceof Dividend) {
                $dividend = Dividend::whereKey($source->id)->lockForUpdate()->firstOrFail();
                if ((float) $dividend->allocations()->sum('paid_amount') > 0) {
                    throw new InvalidArgumentException(
                        'This dividend has payments recorded; reverse the payment entries first.'
                    );
                }

                $reversal = $this->journal->reverse($entry, $date, $userId);
                $dividend->update(['status' => 'reversed']);
                $dividend->forceFill(['reversed_at' => now()])->save();

                return $reversal;
            }

            if ($source instanceof DividendPayment) {
                $allocation = DividendAllocation::whereKey($source->dividend_allocation_id)->lockForUpdate()->firstOrFail();

                $reversal = $this->journal->reverse($entry, $date, $userId);

                // paid_amount is tracked in base currency.
                $allocation->update([
                    'paid_amount' => max(round((float) $allocation->paid_amount - (float) $source->base_amount, 2), 0),
                ]);
                $allocation->dividend->refreshStatus();
                $source->forceFill(['reversed_at' => now()])->save();

                return $reversal;
            }

            // Reversing a share issue takes the shares back off the
            // shareholder's register entry.
            if ($source instanceof EquityTransaction && $source->type === 'share_issue') {
                $reversal = $this->journal->reverse($entry, $date, $userId);

                if ($source->shareholder_id && $source->shares_count) {
                    $shareholder = Shareholder::whereKey($source->shareholder_id)->lockForUpdate()->first();
                    $shareholder?->update([
                        'shares_held' => max(round((float) $shareholder->shares_held - $source->shares_count, 2), 0),
                    ]);
                }
                $source->forceFill(['reversed_at' => now()])->save();

                return $reversal;
            }

            if ($source instanceof ShareholderLoan) {
                $loan = ShareholderLoan::whereKey($source->id)->lockForUpdate()->firstOrFail();
                if ((float) $loan->total_repaid > 0) {
                    throw new InvalidArgumentException(
                        'This loan has repayments recorded; reverse the repayment entries first.'
                    );
                }

                $reversal = $this->journal->reverse($entry, $date, $userId);
                $loan->update(['status' => 'reversed', 'outstanding_balance' => 0]);
                $loan->forceFill(['reversed_at' => now()])->save();

                return $reversal;
            }

            if ($source instanceof ShareholderLoanRepayment) {
                $loan = ShareholderLoan::whereKey($source->shareholder_loan_id)->lockForUpdate()->firstOrFail();

                $reversal = $this->journal->reverse($entry, $date, $userId);

                $repaid = max(round((float) $loan->total_repaid - (float) $source->amount, 2), 0);
                $loan->update([
                    'total_repaid' => $repaid,
                    'outstanding_balance' => round((float) $loan->principal - $repaid, 2),
                    'status' => 'active',
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
