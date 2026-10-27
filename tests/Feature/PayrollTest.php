<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\ChartOfAccountsService;
use App\Services\PayrollService;
use App\Services\ReportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->company = Company::create(['name' => 'Hold', 'code' => 'H', 'type' => 'holding']);
        $chart = app(ChartOfAccountsService::class);
        $chart->seedDefaultChart($this->company);

        $this->user = User::factory()->create(['current_company_id' => $this->company->id]);
        setPermissionsTeamId($this->company->id);
        $this->user->assignRole('holding-admin');
        $this->user->companies()->attach($this->company->id);

        // Fund the bank so net pay can be traced.
        app(AccountingService::class)->recordEquityTransaction($this->company, [
            'type' => 'contribution', 'date' => '2026-01-01', 'owner_name' => 'Owner',
            'amount' => 100000, 'money_account_id' => $chart->findBySubtype($this->company, 'bank')->id,
        ], $this->user->id);
    }

    protected function employee(): Employee
    {
        $employee = Employee::create([
            'company_id' => $this->company->id, 'employee_no' => 'EMP-0001',
            'first_name' => 'Jane', 'last_name' => 'Doe', 'job_title' => 'Analyst',
            'contract_type' => 'permanent', 'pay_frequency' => 'monthly',
            'base_salary' => 5000, 'is_active' => true,
        ]);

        $employee->assignments()->create([
            'company_id' => $this->company->id,
            'job_title' => 'Analyst',
            'pay_frequency' => 'monthly',
            'base_salary' => 5000,
            'is_active' => true,
        ]);

        // Recurring: +800 transport (fixed), +10% housing, -pension 5%, -PAYE 1250 fixed.
        $employee->components()->createMany([
            ['kind' => 'earning', 'code' => 'transport', 'name' => 'Transport', 'calc_type' => 'fixed', 'amount' => 800, 'is_active' => true],
            ['kind' => 'earning', 'code' => 'housing', 'name' => 'Housing', 'calc_type' => 'percent_of_basic', 'amount' => 10, 'is_active' => true],
            ['kind' => 'deduction', 'code' => 'pension', 'name' => 'Pension', 'calc_type' => 'percent_of_basic', 'amount' => 5, 'is_active' => true],
            ['kind' => 'deduction', 'code' => 'paye', 'name' => 'PAYE', 'calc_type' => 'fixed', 'amount' => 1250, 'is_active' => true],
        ]);

        return $employee->fresh('components');
    }

    protected function generate(): PayrollRun
    {
        $bank = app(ChartOfAccountsService::class)->findBySubtype($this->company, 'bank');

        return app(PayrollService::class)->generate($this->company, [
            'period_from' => '2026-03-01', 'period_to' => '2026-03-31', 'pay_date' => '2026-03-31',
            'money_account_id' => $bank->id, 'user_id' => $this->user->id,
        ]);
    }

    /**
     * Payroll has no FX handling, so a foreign-currency bank account would post
     * its net pay as though the amount were base currency. It is refused.
     */
    public function test_payroll_refuses_a_money_account_in_another_currency(): void
    {
        $this->employee();
        $bank = app(ChartOfAccountsService::class)->findBySubtype($this->company, 'bank');
        $bank->update(['currency' => 'ZWG']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('transacts in ZWG');
        $this->generate();
    }

    public function test_payroll_accepts_an_account_with_no_explicit_currency(): void
    {
        $this->employee();
        $bank = app(ChartOfAccountsService::class)->findBySubtype($this->company, 'bank');
        // An account with no currency of its own transacts in the company's base
        // currency, so the guard must let it through rather than compare to null.
        $bank->update(['currency' => null]);

        $this->assertSame('draft', $this->generate()->status);
    }

    public function test_pay_slip_computes_from_the_salary_structure(): void
    {
        $this->employee();
        $run = $this->generate();
        $item = $run->items->first();

        // Gross = 5000 + 800 + 500 (10%) = 6300. Deductions = 250 (5%) + 1250 = 1500. Net = 4800.
        $this->assertSame('6300.00', $item->gross);
        $this->assertSame('1500.00', $item->deductions_total);
        $this->assertSame('4800.00', $item->net);
        $this->assertSame('draft', $run->status);
    }

    public function test_approval_posts_a_balanced_payroll_journal(): void
    {
        $this->employee();
        $run = $this->generate();

        app(PayrollService::class)->approve($run, [], $this->user->id);
        $run = $run->fresh();

        $this->assertSame('approved', $run->status);
        $this->assertNotNull($run->journal_entry_id);
        $this->assertNotNull($run->approved_at);

        $reports = app(ReportService::class);
        $tb = $reports->trialBalance([$this->company->id], null, '2026-12-31');
        $this->assertSame($tb['total_debit'], $tb['total_credit']);

        // Salaries expense debited with gross; Tax Payable holds PAYE, Pension holds pension.
        $chart = app(ChartOfAccountsService::class);
        $movements = fn ($code) => $reports->trialBalance([$this->company->id], null, '2026-12-31');
        $rows = collect($reports->trialBalance([$this->company->id], null, '2026-12-31')['rows'])->keyBy('code');
        $this->assertSame(6300.0, (float) $rows['5010']['debit']);
        $this->assertSame(1250.0, (float) $rows['2100']['credit']); // PAYE -> Tax Payable
        $this->assertSame(250.0, (float) $rows['2110']['credit']);  // Pension Payable
    }

    public function test_once_off_adjustment_at_approval_recomputes_and_posts(): void
    {
        $this->employee();
        $run = $this->generate();
        $employeeId = $run->items->first()->employee_id;

        app(PayrollService::class)->approve($run, [
            $employeeId => [
                ['employee_id' => $employeeId, 'kind' => 'earning', 'name' => 'Performance bonus', 'amount' => 1000],
            ],
        ], $this->user->id);

        $item = $run->fresh('items')->items->first();
        // Gross 6300 + 1000 bonus = 7300; net 4800 + 1000 = 5800.
        $this->assertSame('7300.00', $item->gross);
        $this->assertSame('5800.00', $item->net);
        $this->assertTrue(collect($item->lines)->contains(fn ($l) => $l['source'] === 'once_off'));
    }

    public function test_payroll_can_be_generated_and_approved_via_http(): void
    {
        $this->employee();
        $bank = app(ChartOfAccountsService::class)->findBySubtype($this->company, 'bank');

        $this->actingAs($this->user)->post('/payroll', [
            'period_from' => '2026-04-01', 'period_to' => '2026-04-30', 'pay_date' => '2026-04-30',
            'money_account_id' => $bank->id,
        ])->assertRedirect();

        $run = PayrollRun::latest('id')->first();
        $this->assertSame('draft', $run->status);

        $this->actingAs($this->user)->post('/payroll/'.$run->id.'/approve', [])
            ->assertSessionHas('success');
        $this->assertSame('approved', $run->fresh()->status);
    }

    public function test_generation_requires_active_employees(): void
    {
        $bank = app(ChartOfAccountsService::class)->findBySubtype($this->company, 'bank');

        $this->actingAs($this->user)->post('/payroll', [
            'period_from' => '2026-04-01', 'period_to' => '2026-04-30', 'pay_date' => '2026-04-30',
            'money_account_id' => $bank->id,
        ])->assertSessionHas('error');
    }
}
