<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\ChartOfAccountsService;
use App\Services\ExchangeRateService;
use App\Services\FxRevaluationService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class MultiCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $user;

    protected Account $usdBank;

    protected Account $zwgBank;

    protected function setUp(): void
    {
        parent::setUp();

        $chart = app(ChartOfAccountsService::class);

        $this->company = Company::create(['name' => 'Zim Co', 'code' => 'Z', 'type' => 'holding', 'currency' => 'USD']);
        $chart->seedDefaultChart($this->company);

        $this->usdBank = $chart->findBySubtype($this->company, 'bank');
        $this->zwgBank = Account::create([
            'company_id' => $this->company->id, 'code' => '1011', 'name' => 'CBZ ZWG Account',
            'type' => 'asset', 'subtype' => 'bank', 'currency' => 'ZWG', 'is_active' => true,
            'statement_group' => 'Current Assets', 'statement_line' => 'Cash and cash equivalents',
        ]);

        $this->user = User::factory()->create();
    }

    protected function rate(string $date, float $rate): void
    {
        ExchangeRate::create([
            'date' => $date, 'base_currency' => 'USD', 'quote_currency' => 'ZWG', 'rate' => $rate,
        ]);
    }

    protected function issueZwgInvoice(float $amount, string $date, float $taxAmount = 0): Invoice
    {
        $chart = app(ChartOfAccountsService::class);
        $client = Contact::firstOrCreate(['company_id' => $this->company->id, 'name' => 'Client'], ['type' => 'customer']);
        $sales = $chart->find($this->company, '4000');

        $invoice = Invoice::create([
            'company_id' => $this->company->id, 'contact_id' => $client->id,
            'invoice_no' => 'INV-'.uniqid(), 'issue_date' => $date, 'status' => 'draft',
            'subtotal' => $amount, 'tax_amount' => $taxAmount, 'total' => round($amount + $taxAmount, 2),
            'currency' => 'ZWG',
        ]);
        $invoice->items()->create([
            'account_id' => $sales->id, 'description' => 'Work',
            'quantity' => 1, 'unit_price' => $amount, 'amount' => $amount,
        ]);

        return app(AccountingService::class)
            ->issueInvoice($invoice->fresh(['items', 'contact', 'company']), $this->user->id);
    }

    public function test_rates_carry_forward_and_transactions_can_override(): void
    {
        $fx = app(ExchangeRateService::class);
        $this->rate('2026-01-01', 27);
        $this->rate('2026-01-10', 30);

        $this->assertSame(27.0, $fx->rateFor($this->company, 'ZWG', '2026-01-05'));
        $this->assertSame(30.0, $fx->rateFor($this->company, 'ZWG', '2026-02-01'));
        $this->assertSame(1.0, $fx->rateFor($this->company, 'USD', '2026-02-01'));
        $this->assertSame(28.5, $fx->resolve($this->company, 'ZWG', '2026-02-01', 28.5));

        $this->expectException(InvalidArgumentException::class);
        $fx->rateFor($this->company, 'ZWG', '2025-12-31');
    }

    public function test_zwg_invoice_posts_base_amounts_and_per_currency_tax(): void
    {
        $this->rate('2026-01-01', 27);

        // ZWG 27,000 + ZWG 2,700 tax at 27 ZWG/USD -> $1,000 revenue, $100 tax.
        $invoice = $this->issueZwgInvoice(27000, '2026-01-05', 2700);

        $this->assertSame(27.0, (float) $invoice->exchange_rate);
        $this->assertSame(1000.0, (float) $invoice->base_subtotal);
        $this->assertSame(100.0, (float) $invoice->base_tax_amount);
        $this->assertSame(1100.0, (float) $invoice->base_total);

        // Tax posted to the ZWG-specific tax payable account.
        $zwgTax = Account::where('company_id', $this->company->id)
            ->where('subtype', 'tax')->where('currency', 'ZWG')->first();
        $this->assertNotNull($zwgTax);
        $this->assertSame('Tax Payable — ZWG', $zwgTax->name);

        $taxLine = $invoice->journalEntry->lines->firstWhere('account_id', $zwgTax->id);
        $this->assertSame(100.0, (float) $taxLine->credit);          // base
        $this->assertSame(2700.0, (float) $taxLine->currency_credit); // currency of trade
        $this->assertSame('ZWG', $taxLine->currency);

        // Books balance in base currency.
        $tb = app(ReportService::class)->trialBalance([$this->company->id], null, '2026-12-31');
        $this->assertSame($tb['total_debit'], $tb['total_credit']);
    }

    public function test_settlement_at_a_weaker_rate_posts_a_realised_loss(): void
    {
        $chart = app(ChartOfAccountsService::class);
        $this->rate('2026-01-01', 27);
        $invoice = $this->issueZwgInvoice(27000, '2026-01-05');

        // A month later the rate is 30: ZWG 27,000 is now worth $900.
        $this->rate('2026-02-01', 30);
        $payment = app(AccountingService::class)->recordInvoicePayment($invoice->fresh('company'), [
            'date' => '2026-02-05', 'amount' => 27000, 'deposit_account_id' => $this->zwgBank->id,
        ], $this->user->id);

        $this->assertSame(900.0, (float) $payment->base_amount);
        $this->assertSame(-100.0, (float) $payment->fx_gain_loss);

        $fxAccount = $chart->fxAccount($this->company, realised: true);
        $fxLine = $payment->journalEntry->lines->firstWhere('account_id', $fxAccount->id);
        $this->assertSame(100.0, (float) $fxLine->debit); // realised loss

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(1000.0, (float) $invoice->base_amount_paid);

        // Receivable fully relieved in base currency.
        $receivable = $chart->findBySubtype($this->company, 'receivable');
        $balance = $receivable->journalLines()->sum('debit') - $receivable->journalLines()->sum('credit');
        $this->assertSame(0.0, round((float) $balance, 2));

        $tb = app(ReportService::class)->trialBalance([$this->company->id], null, '2026-12-31');
        $this->assertSame($tb['total_debit'], $tb['total_credit']);
    }

    public function test_payment_must_be_deposited_into_an_account_of_the_invoice_currency(): void
    {
        $this->rate('2026-01-01', 27);
        $invoice = $this->issueZwgInvoice(27000, '2026-01-05');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ZWG');

        app(AccountingService::class)->recordInvoicePayment($invoice->fresh('company'), [
            'date' => '2026-02-05', 'amount' => 27000, 'deposit_account_id' => $this->usdBank->id,
        ], $this->user->id);
    }

    public function test_expense_in_foreign_account_converts_to_base(): void
    {
        $chart = app(ChartOfAccountsService::class);
        $this->rate('2026-01-01', 25);

        // Fund the ZWG account first.
        app(AccountingService::class)->recordEquityTransaction($this->company, [
            'type' => 'contribution', 'date' => '2026-01-02', 'owner_name' => 'Owner',
            'amount' => 50000, 'money_account_id' => $this->zwgBank->id,
        ], $this->user->id);

        $rent = $chart->find($this->company, '5000');
        $expense = app(AccountingService::class)->recordExpense($this->company, [
            'date' => '2026-01-15', 'account_id' => $rent->id,
            'paid_from_account_id' => $this->zwgBank->id, 'amount' => 2500,
        ], $this->user->id);

        $this->assertSame('ZWG', $expense->currency);
        $this->assertSame(25.0, (float) $expense->exchange_rate);
        $this->assertSame(100.0, (float) $expense->base_amount);

        $rentLine = $expense->journalEntry->lines->firstWhere('account_id', $rent->id);
        $this->assertSame(100.0, (float) $rentLine->debit);
        $this->assertSame(2500.0, (float) $rentLine->currency_debit);
    }

    public function test_cross_currency_transfer_needs_received_amount_and_books_fx(): void
    {
        $acc = app(AccountingService::class);
        $this->rate('2026-01-01', 25);

        $acc->recordEquityTransaction($this->company, [
            'type' => 'contribution', 'date' => '2026-01-02', 'owner_name' => 'Owner',
            'amount' => 1000, 'money_account_id' => $this->usdBank->id,
        ], $this->user->id);

        // Convert $100 at the bank, receiving ZWG 2,400 (worse than interbank 25).
        $payment = $acc->recordPayment($this->company, [
            'kind' => 'transfer', 'date' => '2026-01-10', 'amount' => 100,
            'from_account_id' => $this->usdBank->id, 'to_account_id' => $this->zwgBank->id,
            'to_amount' => 2400,
        ], $this->user->id);

        $this->assertSame(100.0, (float) $payment->base_amount);
        $this->assertSame(96.0, (float) $payment->base_to_amount);

        $fxAccount = app(ChartOfAccountsService::class)->fxAccount($this->company, realised: true);
        $fxLine = $payment->journalEntry->lines->firstWhere('account_id', $fxAccount->id);
        $this->assertSame(4.0, (float) $fxLine->debit); // conversion loss

        // Without the received amount the transfer is rejected.
        $this->expectException(InvalidArgumentException::class);
        $acc->recordPayment($this->company, [
            'kind' => 'transfer', 'date' => '2026-01-11', 'amount' => 100,
            'from_account_id' => $this->usdBank->id, 'to_account_id' => $this->zwgBank->id,
        ], $this->user->id);
    }

    public function test_month_end_close_revalues_open_foreign_balances(): void
    {
        $acc = app(AccountingService::class);
        $this->rate('2026-01-01', 25);

        // ZWG 25,000 in the bank ($1,000) and an open ZWG 50,000 invoice ($2,000).
        $acc->recordEquityTransaction($this->company, [
            'type' => 'contribution', 'date' => '2026-01-02', 'owner_name' => 'Owner',
            'amount' => 25000, 'money_account_id' => $this->zwgBank->id,
        ], $this->user->id);
        $this->issueZwgInvoice(50000, '2026-01-10');

        // Closing rate 30: bank now $833.33 (-166.67), invoice $1,666.67 (-333.33).
        $this->rate('2026-01-31', 30);
        $entry = app(FxRevaluationService::class)
            ->revalueAtClose($this->company, Carbon::parse('2026-01-31'), $this->user->id);

        $this->assertNotNull($entry);
        $fxAccount = app(ChartOfAccountsService::class)->fxAccount($this->company, realised: false);
        $fxLine = $entry->lines->firstWhere('account_id', $fxAccount->id);
        $this->assertSame(500.0, (float) $fxLine->debit); // unrealised loss 166.67 + 333.33

        // The reversal is posted the next day, so February opens at booked rates.
        $reversal = JournalEntry::where('company_id', $this->company->id)
            ->where('reference', 'FX-REVAL-REVERSAL')->first();
        $this->assertNotNull($reversal);
        $this->assertSame('2026-02-01', $reversal->date->toDateString());

        $tb = app(ReportService::class)->trialBalance([$this->company->id], null, '2026-12-31');
        $this->assertSame($tb['total_debit'], $tb['total_credit']);
    }

    public function test_tax_by_currency_report_shows_currency_of_trade(): void
    {
        $this->rate('2026-01-01', 27);
        $this->issueZwgInvoice(27000, '2026-01-05', 2700);

        $admin = User::factory()->create();
        $admin->forceFill(['current_company_id' => $this->company->id])->save();
        $admin->companies()->attach($this->company->id);

        // System admin bypasses permission middleware in this app's tests
        // via role; simplest here is to hit the query directly.
        $rows = \Illuminate\Support\Facades\DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.company_id', $this->company->id)
            ->where('a.subtype', 'tax')->where('a.type', 'liability')
            ->groupBy('a.currency')
            ->selectRaw('a.currency, SUM(jl.currency_credit - jl.currency_debit) as net_tax, SUM(jl.credit - jl.debit) as net_base')
            ->get()->keyBy('currency');

        $this->assertSame(2700.0, (float) $rows['ZWG']->net_tax);   // declarable to ZIMRA in ZWG
        $this->assertSame(100.0, (float) $rows['ZWG']->net_base);   // base value on the balance sheet
    }
}
