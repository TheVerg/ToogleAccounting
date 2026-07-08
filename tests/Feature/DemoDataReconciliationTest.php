<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FinancialStatement;
use App\Models\Invoice;
use App\Models\User;
use App\Services\ChartOfAccountsService;
use App\Services\ReportService;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Proves the demo dataset reconciles end to end, and that imported
 * statements enter the same register lifecycle.
 */
class DemoDataReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected Company $subA;

    protected Company $holding;

    protected ReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoDataSeeder::class);
        $this->subA = Company::where('code', 'SUB-A')->firstOrFail();
        $this->holding = Company::where('code', 'HOLD')->firstOrFail();
        $this->reports = app(ReportService::class);
    }

    public function test_trial_balance_balances_for_every_scope(): void
    {
        foreach ([[$this->subA->id], [$this->holding->id], [$this->subA->id, $this->holding->id]] as $ids) {
            $tb = $this->reports->trialBalance($ids, null, '2026-12-31');
            $this->assertSame($tb['total_debit'], $tb['total_credit']);
            $this->assertGreaterThan(0, $tb['total_debit']);
        }
    }

    public function test_profit_and_loss_ties_to_activity(): void
    {
        $pl = $this->reports->profitLoss([$this->subA->id], '2026-01-01', '2026-12-31');

        $this->assertSame(10400.0, $pl['total_income']);   // 5,000 + 3,000 + 2,400
        $this->assertSame(3820.0, $pl['total_expenses']);  // 1,200 + 2,500 + 120
        $this->assertSame(6580.0, $pl['net_profit']);
    }

    public function test_balance_sheet_balances_and_net_profit_flows_to_equity(): void
    {
        $bs = $this->reports->balanceSheet([$this->subA->id], '2026-12-31');

        $this->assertTrue($bs['balanced']);
        $this->assertSame(37330.0, $bs['total_assets']);
        $this->assertSame(10750.0, $bs['total_liabilities']); // tax 750 + intercompany 10,000
        $this->assertSame(6580.0, $bs['current_earnings']);   // net profit flows into equity
        $this->assertSame(26580.0, $bs['total_equity']);      // 20,000 contributed + 6,580 earned
    }

    public function test_cash_balances_tie_to_posted_payments(): void
    {
        $balances = $this->reports->moneyBalances([$this->subA->id]);
        $chart = app(ChartOfAccountsService::class);

        $this->assertSame(32550.0, $balances->firstWhere('id', $chart->findBySubtype($this->subA, 'bank')->id)->balance);
        $this->assertSame(380.0, $balances->firstWhere('id', $chart->findBySubtype($this->subA, 'petty_cash')->id)->balance);
    }

    public function test_receivables_tie_to_open_invoices_and_tax_to_vat(): void
    {
        $tb = collect($this->reports->trialBalance([$this->subA->id], null, '2026-12-31')['rows']);

        $openInvoiceBalance = Invoice::where('company_id', $this->subA->id)->get()
            ->sum(fn ($i) => $i->balanceDue());
        $this->assertSame(4400.0, round($openInvoiceBalance, 2));
        $this->assertSame(4400.0, $tb->firstWhere('code', '1100')['debit']);  // Accounts Receivable

        $this->assertSame(750.0, $tb->firstWhere('code', '2100')['credit']); // Tax Payable = 15% of 5,000

        // Intercompany mirrors on both sides.
        $this->assertSame(4400.0, $tb->firstWhere('code', '1100')['debit']);
        $holdTb = collect($this->reports->trialBalance([$this->holding->id], null, '2026-12-31')['rows']);
        $this->assertSame(10000.0, $holdTb->firstWhere('code', '1200')['debit']);   // IC receivable
        $this->assertSame(10000.0, $tb->firstWhere('code', '2200')['credit']);      // IC payable
    }

    public function test_statements_are_registered_including_an_approved_one(): void
    {
        $this->assertSame(3, FinancialStatement::where('company_id', $this->subA->id)->count());
        $approved = FinancialStatement::where('company_id', $this->subA->id)
            ->where('status', 'approved')->firstOrFail();
        $this->assertSame('balance-sheet', $approved->type);
        $this->assertSame('generated', $approved->source);
    }

    public function test_imported_statement_matches_the_generated_balances(): void
    {
        $admin = User::where('email', 'admin@holdco.test')->firstOrFail();
        setPermissionsTeamId($this->subA->id);
        $admin->forceFill(['current_company_id' => $this->subA->id])->save();

        // Fill the trial-balance template with the known reconciled figures.
        $csv = "Code,Account,Debit,Credit\n"
            ."1010,Bank Account,32550,0\n"
            ."1020,Petty Cash,380,0\n"
            ."1100,Accounts Receivable,4400,0\n"
            ."2100,Tax Payable,0,750\n"
            ."2200,Intercompany Payable,0,10000\n"
            ."3000,Owner's Capital,0,20000\n"
            ."4000,Sales Revenue,0,10400\n"
            ."5000,Rent Expense,1200,0\n"
            ."5010,Salaries & Wages,2500,0\n"
            ."5050,Transport & Fuel,120,0\n";

        $this->actingAs($admin)->post('/statements/import', [
            'type' => 'trial-balance', 'from' => '2026-01-01', 'to' => '2026-03-31',
            'file' => UploadedFile::fake()->createWithContent('tb.csv', $csv),
        ])->assertSessionHasNoErrors();

        $imported = FinancialStatement::where('source', 'imported')->firstOrFail();
        $this->assertSame('draft', $imported->status);
        $total = collect($imported->lines)->firstWhere('line_type', 'total');
        $this->assertEquals([41150, 41150], $total['values']);

        // Unbalanced imports are rejected.
        $bad = "Code,Account,Debit,Credit\n1010,Bank,100,0\n";
        $this->actingAs($admin)->post('/statements/import', [
            'type' => 'trial-balance', 'to' => '2026-03-31',
            'file' => UploadedFile::fake()->createWithContent('bad.csv', $bad),
        ])->assertSessionHasErrors('file');
    }

    public function test_templates_download_with_expected_line_items(): void
    {
        $admin = User::where('email', 'admin@holdco.test')->firstOrFail();
        setPermissionsTeamId($this->subA->id);
        $admin->forceFill(['current_company_id' => $this->subA->id])->save();

        $csv = $this->actingAs($admin)
            ->get('/statements/import/template?type=balance-sheet')
            ->assertOk()->getContent();

        $this->assertStringContainsString('Section,Code,Item,Amount', $csv);
        $this->assertStringContainsString('Accounts Receivable', $csv);
        $this->assertStringContainsString('Current earnings', $csv);
    }
}
