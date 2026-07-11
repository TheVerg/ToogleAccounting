<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CleanDatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_seed_contains_only_horizon_setup_and_chart_of_accounts(): void
    {
        $this->seed(DatabaseSeeder::class);

        $company = Company::sole();
        $this->assertSame('Horizon Equity Group', $company->name);
        $this->assertSame('HOLD', $company->code);
        $this->assertSame(1, User::count());
        $this->assertSame('admin@horizon-equity.com', User::sole()->email);
        $this->assertGreaterThan(0, Account::where('company_id', $company->id)->count());

        foreach ([
            'contacts', 'invoices', 'payments', 'expenses', 'equity_transactions',
            'budgets', 'intercompany_transfers', 'employees', 'payroll_runs',
            'employee_loans', 'financial_statements',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table.' should not contain demo data.');
        }
    }
}
