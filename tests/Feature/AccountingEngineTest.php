<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\ChartOfAccountsService;
use App\Services\JournalService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class AccountingEngineTest extends TestCase
{
    use RefreshDatabase;

    protected Company $holding;

    protected Company $subsidiary;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $chart = app(ChartOfAccountsService::class);

        $this->holding = Company::create(['name' => 'Hold', 'code' => 'H', 'type' => 'holding']);
        $this->subsidiary = Company::create(['name' => 'Sub', 'code' => 'S', 'type' => 'subsidiary', 'parent_id' => $this->holding->id]);
        $chart->seedDefaultChart($this->holding);
        $chart->seedDefaultChart($this->subsidiary);

        $this->user = User::factory()->create();
    }

    public function test_unbalanced_journal_entries_are_rejected(): void
    {
        $chart = app(ChartOfAccountsService::class);
        $bank = $chart->findBySubtype($this->holding, 'bank');
        $equity = $chart->findBySubtype($this->holding, 'owner_equity');

        $this->expectException(InvalidArgumentException::class);

        app(JournalService::class)->post($this->holding, '2026-01-01', 'Broken', [
            ['account_id' => $bank->id, 'debit' => 100, 'credit' => 0],
            ['account_id' => $equity->id, 'debit' => 0, 'credit' => 99],
        ]);
    }

    public function test_full_cycle_keeps_books_balanced_and_consolidation_eliminates_intercompany(): void
    {
        $acc = app(AccountingService::class);
        $chart = app(ChartOfAccountsService::class);
        $reports = app(ReportService::class);

        $holdBank = $chart->findBySubtype($this->holding, 'bank');
        $subBank = $chart->findBySubtype($this->subsidiary, 'bank');

        // Owner funds the holding, holding lends to the subsidiary.
        $acc->recordEquityTransaction($this->holding, [
            'type' => 'contribution', 'date' => '2026-01-01', 'owner_name' => 'Owner',
            'amount' => 10000, 'money_account_id' => $holdBank->id,
        ], $this->user->id);

        $acc->recordIntercompanyTransfer([
            'from_company_id' => $this->holding->id, 'to_company_id' => $this->subsidiary->id,
            'type' => 'loan', 'date' => '2026-01-02', 'amount' => 4000,
            'from_money_account_id' => $holdBank->id, 'to_money_account_id' => $subBank->id,
        ], $this->user->id);

        // Subsidiary invoices a client and is paid, then pays an expense.
        $client = Contact::create(['company_id' => $this->subsidiary->id, 'type' => 'customer', 'name' => 'Client']);
        $sales = $chart->find($this->subsidiary, '4000');

        $invoice = Invoice::create([
            'company_id' => $this->subsidiary->id, 'contact_id' => $client->id,
            'invoice_no' => 'INV-1', 'issue_date' => '2026-02-01', 'status' => 'draft',
            'subtotal' => 1000, 'tax_amount' => 0, 'total' => 1000,
        ]);
        $invoice->items()->create([
            'account_id' => $sales->id, 'description' => 'Work',
            'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000,
        ]);
        $acc->issueInvoice($invoice->fresh(['items', 'contact', 'company']), $this->user->id);
        $acc->recordInvoicePayment($invoice->fresh('company'), [
            'date' => '2026-02-10', 'amount' => 1000, 'deposit_account_id' => $subBank->id,
        ], $this->user->id);

        $rent = $chart->find($this->subsidiary, '5000');
        $acc->recordExpense($this->subsidiary, [
            'date' => '2026-02-15', 'account_id' => $rent->id,
            'paid_from_account_id' => $subBank->id, 'amount' => 300,
        ], $this->user->id);

        // Every scope must balance.
        foreach ([[$this->holding->id], [$this->subsidiary->id], [$this->holding->id, $this->subsidiary->id]] as $ids) {
            $tb = $reports->trialBalance($ids, null, '2026-12-31');
            $this->assertSame($tb['total_debit'], $tb['total_credit']);

            $bs = $reports->balanceSheet($ids, '2026-12-31');
            $this->assertTrue($bs['balanced']);
        }

        // Subsidiary P&L.
        $pl = $reports->profitLoss([$this->subsidiary->id], '2026-01-01', '2026-12-31');
        $this->assertSame(1000.0, $pl['total_income']);
        $this->assertSame(300.0, $pl['total_expenses']);
        $this->assertSame(700.0, $pl['net_profit']);

        // Consolidated balance sheet must not contain intercompany accounts.
        $cons = $reports->balanceSheet([$this->holding->id, $this->subsidiary->id], '2026-12-31');
        $names = collect($cons['assets'])->merge($cons['liabilities'])->pluck('name');
        $this->assertFalse($names->contains(fn ($n) => str_contains($n, 'Intercompany')));

        // Consolidated assets = cash only (6000 hold + 4700 sub).
        $this->assertSame(10700.0, $cons['total_assets']);
    }

    public function test_reversal_posts_an_equal_and_opposite_entry(): void
    {
        $acc = app(AccountingService::class);
        $chart = app(ChartOfAccountsService::class);
        $reports = app(ReportService::class);

        $bank = $chart->findBySubtype($this->holding, 'bank');
        $tx = $acc->recordEquityTransaction($this->holding, [
            'type' => 'contribution', 'date' => '2026-01-01', 'owner_name' => 'Owner',
            'amount' => 500, 'money_account_id' => $bank->id,
        ], $this->user->id);

        app(JournalService::class)->reverse($tx->journalEntry, '2026-01-02', $this->user->id);

        $tb = $reports->trialBalance([$this->holding->id], null, '2026-12-31');
        $this->assertSame($tb['total_debit'], $tb['total_credit']);

        $balances = $reports->moneyBalances([$this->holding->id]);
        $this->assertSame(0.0, (float) $balances->firstWhere('id', $bank->id)?->balance ?? 0.0);
    }
}
