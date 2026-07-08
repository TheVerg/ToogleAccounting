<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Services\ChartOfAccountsService;
use App\Services\JournalService;
use App\Services\ReportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatementMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->company = Company::create(['name' => 'Hold', 'code' => 'H', 'type' => 'holding']);
        app(ChartOfAccountsService::class)->seedDefaultChart($this->company);

        $this->user = User::factory()->create(['current_company_id' => $this->company->id]);
        setPermissionsTeamId($this->company->id);
        $this->user->assignRole('holding-admin');
        $this->user->companies()->attach($this->company->id);
    }

    protected function account(string $code): Account
    {
        return app(ChartOfAccountsService::class)->find($this->company, $code);
    }

    protected function postEntry(string $description, array $lines): void
    {
        app(JournalService::class)->post($this->company, '2026-02-01', $description, $lines, null, null, $this->user->id);
    }

    public function test_income_statement_groups_by_account_metadata_with_contra_and_rollups(): void
    {
        $bank = $this->account('1010');

        // Tag the chart: revenue group incl. a contra account; expenses roll up.
        $this->account('4000')->update(['statement_group' => 'Revenue']);
        $this->account('4100')->update(['statement_group' => 'Revenue', 'balance_type' => 'debit']); // contra (returns)
        $this->account('5000')->update(['statement_group' => 'Operating Expenses', 'statement_line' => 'Occupancy & staff']);
        $this->account('5010')->update(['statement_group' => 'Operating Expenses', 'statement_line' => 'Occupancy & staff']);
        $this->account('5950')->update(['statement_group' => 'Finance Costs']);

        // 1,000 sales; 100 returns (debit against income); 300 + 200 operating; 50 finance.
        $this->postEntry('Sales', [
            ['account_id' => $bank->id, 'debit' => 1000, 'credit' => 0],
            ['account_id' => $this->account('4000')->id, 'debit' => 0, 'credit' => 1000],
        ]);
        $this->postEntry('Returns', [
            ['account_id' => $this->account('4100')->id, 'debit' => 100, 'credit' => 0],
            ['account_id' => $bank->id, 'debit' => 0, 'credit' => 100],
        ]);
        $this->postEntry('Rent + salaries', [
            ['account_id' => $this->account('5000')->id, 'debit' => 300, 'credit' => 0],
            ['account_id' => $this->account('5010')->id, 'debit' => 200, 'credit' => 0],
            ['account_id' => $bank->id, 'debit' => 0, 'credit' => 500],
        ]);
        $this->postEntry('Charges', [
            ['account_id' => $this->account('5950')->id, 'debit' => 50, 'credit' => 0],
            ['account_id' => $bank->id, 'debit' => 0, 'credit' => 50],
        ]);

        $report = app(ReportService::class)
            ->profitLoss([$this->company->id], '2026-01-01', '2026-12-31', $this->company);

        $groups = collect($report['groups']);

        // Contra revenue subtracts inside its group: 1,000 - 100.
        $this->assertSame(900.0, $groups->firstWhere('label', 'Revenue')['total']);

        // Two accounts roll up into one presented line.
        $operating = $groups->firstWhere('label', 'Operating Expenses');
        $this->assertSame(500.0, $operating['total']);
        $rollup = collect($operating['rows'])->firstWhere('name', 'Occupancy & staff');
        $this->assertSame(500.0, $rollup['amount']);
        $this->assertSame(2, $rollup['rollup']);

        $this->assertSame(50.0, $groups->firstWhere('label', 'Finance Costs')['total']);

        // Ledger totals unchanged by presentation.
        $this->assertSame(900.0, $report['total_income']);
        $this->assertSame(550.0, $report['total_expenses']);
    }

    public function test_balance_sheet_rolls_up_cash_and_cash_equivalents(): void
    {
        $this->account('1000')->update(['statement_group' => 'Current Assets', 'statement_line' => 'Cash and cash equivalents']);
        $this->account('1010')->update(['statement_group' => 'Current Assets', 'statement_line' => 'Cash and cash equivalents']);

        $equity = $this->account('3000');
        $this->postEntry('Fund cash + bank', [
            ['account_id' => $this->account('1000')->id, 'debit' => 400, 'credit' => 0],
            ['account_id' => $this->account('1010')->id, 'debit' => 600, 'credit' => 0],
            ['account_id' => $equity->id, 'debit' => 0, 'credit' => 1000],
        ]);

        $report = app(ReportService::class)
            ->balanceSheet([$this->company->id], '2026-12-31', $this->company);

        $current = collect($report['groups'])->firstWhere('label', 'Current Assets');
        $line = collect($current['rows'])->firstWhere('name', 'Cash and cash equivalents');

        $this->assertSame(1000.0, $line['amount']);
        $this->assertSame(2, $line['rollup']);
        $this->assertTrue($report['balanced']);
    }
}
