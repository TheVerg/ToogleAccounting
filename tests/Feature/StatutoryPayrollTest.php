<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollStatutoryRate;
use App\Models\PayrollTaxBand;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ChartOfAccountsService;
use App\Services\PayrollService;
use App\Services\StatutoryPayrollService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatutoryPayrollTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_effective_rules_calculate_paye_aids_levy_and_nssa(): void
    {
        $company = Company::create(['name' => 'Employer', 'code' => 'EMP', 'type' => 'holding', 'currency' => 'USD']);
        app(ChartOfAccountsService::class)->seedDefaultChart($company);
        $employee = Employee::create(['company_id' => $company->id, 'employee_no' => 'EMP-1', 'first_name' => 'A', 'last_name' => 'B', 'contract_type' => 'permanent', 'pay_frequency' => 'monthly', 'pay_currency' => 'USD', 'base_salary' => 1000, 'nssa_registered' => true]);
        $employee->assignments()->create(['company_id' => $company->id, 'pay_frequency' => 'monthly', 'base_salary' => 1000, 'is_active' => true]);

        $this->table('USD', '2026-01-01', [[0, 100, 0], [100, 500, .20], [500, null, .25]]);
        PayrollStatutoryRate::create(['code' => 'aids_levy', 'currency' => 'USD', 'effective_from' => '2026-01-01', 'employee_rate' => .03, 'source_url' => 'https://www.zimra.co.zw/', 'is_approved' => true]);
        PayrollStatutoryRate::create(['code' => 'nssa_pobs', 'currency' => 'USD', 'effective_from' => '2026-01-01', 'employee_rate' => .045, 'employer_rate' => .045, 'ceiling' => 700, 'source_url' => 'https://www.nssa.org.zw/', 'is_approved' => true]);

        $assignment = $employee->assignments->first();
        $item = app(PayrollService::class)->computeItem($employee->fresh(['components', 'loans']), [], $assignment, Carbon::parse('2026-03-31'), 'USD');
        $lines = collect($item['lines'])->keyBy('code');

        // NSSA POBS is capped at the 700 insurable ceiling: 700 * 4.5%.
        $this->assertSame(31.5, (float) $lines['nssa_employee']['amount']);
        $this->assertSame(31.5, (float) $lines['nssa_employer']['amount']);

        // PAYE is charged on remuneration net of the employee POBS contribution
        // (1000 - 31.50 = 968.50), not on gross: 400 * 20% + 468.50 * 25%.
        $this->assertSame(197.13, (float) $lines['paye']['amount']);
        $this->assertSame(5.91, (float) $lines['aids_levy']['amount']);
        $this->assertSame(765.46, $item['net']);

        // The employer contribution is a company cost, never an employee deduction.
        $this->assertSame('employer_cost', $lines['nssa_employer']['kind']);
        $this->assertSame(234.54, $item['deductions_total']);
        $this->assertSame(31.5, $item['employer_costs_total']);
    }

    public function test_unapproved_or_missing_tables_never_silently_calculate_tax(): void
    {
        $company = Company::create(['name' => 'Employer', 'code' => 'EMP', 'type' => 'holding', 'currency' => 'USD']);
        $employee = Employee::create(['company_id' => $company->id, 'employee_no' => 'EMP-1', 'first_name' => 'A', 'last_name' => 'B', 'contract_type' => 'permanent', 'pay_frequency' => 'monthly', 'base_salary' => 1000]);
        $employee->assignments()->create(['company_id' => $company->id, 'pay_frequency' => 'monthly', 'base_salary' => 1000, 'is_active' => true]);
        PayrollTaxBand::create(['currency' => 'USD', 'frequency' => 'monthly', 'effective_from' => '2026-01-01', 'lower_bound' => 0, 'rate' => .4, 'source_url' => 'https://www.zimra.co.zw/', 'is_approved' => false]);

        $item = app(PayrollService::class)->computeItem($employee->fresh(['components', 'loans']), [], $employee->assignments->first(), Carbon::parse('2026-03-31'), 'USD');
        $this->assertFalse(collect($item['lines'])->contains('code', 'paye'));
        $this->assertNotEmpty($item['_warnings']);
    }

    /**
     * A superseded table is not required to carry an effective_to, so two
     * tables can match the same pay date. Only the later one may apply —
     * summing across both silently over-taxes every employee.
     */
    public function test_a_later_tax_table_supersedes_an_earlier_open_ended_one(): void
    {
        $this->table('USD', '2026-01-01', [[0, 500, 0], [500, null, .20]]);
        $this->table('USD', '2027-01-01', [[0, 600, 0], [600, null, .25]]);

        $employee = $this->soleEmployee();
        $result = app(StatutoryPayrollService::class)->calculate($employee, 1000.0, Carbon::parse('2027-03-31'), 'monthly', 'USD');

        // 2027 table only: (1000 - 600) * 25%.
        $this->assertSame(100.0, (float) collect($result['lines'])->firstWhere('code', 'paye')['amount']);
    }

    public function test_a_tenant_override_supersedes_the_shared_national_table(): void
    {
        $tenant = Tenant::create(['name' => 'Alpha']);
        // The shared table is both newer and harsher; the tenant's own table still wins.
        $this->table('USD', '2026-06-01', [[0, null, .40]]);
        $this->table('USD', '2026-01-01', [[0, null, .10]], $tenant->id);

        $employee = $this->soleEmployee($tenant->id);
        $result = app(StatutoryPayrollService::class)->calculate($employee, 1000.0, Carbon::parse('2026-07-31'), 'monthly', 'USD');

        $this->assertSame(100.0, (float) collect($result['lines'])->firstWhere('code', 'paye')['amount']);
    }

    /**
     * ZIMRA's published tables are "income × rate − deduction" per bracket.
     * Transcribed verbatim they must agree with the equivalent marginal table,
     * and must not be summed across brackets.
     */
    public function test_a_published_deduction_style_table_matches_the_marginal_table(): void
    {
        $marginal = collect([
            new PayrollTaxBand(['lower_bound' => 0, 'upper_bound' => 100, 'rate' => 0, 'deduction' => 0]),
            new PayrollTaxBand(['lower_bound' => 100, 'upper_bound' => 500, 'rate' => .20, 'deduction' => 0]),
            new PayrollTaxBand(['lower_bound' => 500, 'upper_bound' => null, 'rate' => .25, 'deduction' => 0]),
        ]);
        $published = collect([
            new PayrollTaxBand(['lower_bound' => 0, 'upper_bound' => 100, 'rate' => 0, 'deduction' => 0]),
            new PayrollTaxBand(['lower_bound' => 100, 'upper_bound' => 500, 'rate' => .20, 'deduction' => 20]),
            new PayrollTaxBand(['lower_bound' => 500, 'upper_bound' => null, 'rate' => .25, 'deduction' => 45]),
        ]);

        $service = app(StatutoryPayrollService::class);
        foreach ([0, 100, 250, 500, 968.5, 1000, 5000] as $income) {
            $this->assertSame(
                $service->paye((float) $income, $marginal),
                $service->paye((float) $income, $published),
                "Forms disagree at income {$income}",
            );
        }

        // Sanity: the published form is not being summed across brackets.
        $this->assertSame(205.0, $service->paye(1000, $published));
    }

    public function test_overlapping_bands_within_one_table_are_rejected(): void
    {
        [$user] = $this->tenantAdmin('Alpha', 'A');
        $payload = ['currency' => 'USD', 'frequency' => 'monthly', 'effective_from' => '2026-01-01',
            'rate' => 0.2, 'source_url' => 'https://www.zimra.co.zw/', 'is_approved' => true];

        $this->actingAs($user)->post('/settings/payroll-statutory/bands', $payload + ['lower_bound' => 0, 'upper_bound' => 500])
            ->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/settings/payroll-statutory/bands', $payload + ['lower_bound' => 400, 'upper_bound' => 900])
            ->assertSessionHasErrors('lower_bound');

        $this->assertSame(1, PayrollTaxBand::count());
    }

    public function test_a_tenant_cannot_see_or_delete_another_tenants_rules(): void
    {
        [$adminA, $tenantA] = $this->tenantAdmin('Alpha', 'A');
        [$adminB] = $this->tenantAdmin('Beta', 'B');

        $ownBand = PayrollTaxBand::create(['tenant_id' => $tenantA->id, 'currency' => 'USD', 'frequency' => 'monthly',
            'effective_from' => '2026-01-01', 'lower_bound' => 0, 'rate' => .2, 'source_url' => 'https://www.zimra.co.zw/', 'is_approved' => true]);
        $sharedBand = PayrollTaxBand::create(['currency' => 'USD', 'frequency' => 'monthly',
            'effective_from' => '2026-01-01', 'lower_bound' => 0, 'rate' => .3, 'source_url' => 'https://www.zimra.co.zw/', 'is_approved' => true]);

        // Tenant B sees the shared table but not tenant A's override.
        $this->actingAs($adminB)->get('/settings/payroll-statutory')->assertInertia(
            fn ($page) => $page->where('bands', fn ($bands) => collect($bands)->pluck('id')->all() === [$sharedBand->id]),
        );

        $this->actingAs($adminB)->delete("/settings/payroll-statutory/bands/{$ownBand->id}")->assertForbidden();
        // Shared national rules are read-only even for their own tenant's admins.
        $this->actingAs($adminA)->delete("/settings/payroll-statutory/bands/{$sharedBand->id}")->assertForbidden();
        $this->actingAs($adminA)->delete("/settings/payroll-statutory/bands/{$ownBand->id}")->assertRedirect();

        $this->assertSame([$sharedBand->id], PayrollTaxBand::pluck('id')->all());
    }

    /** @param  array<array{0:float,1:?float,2:float}>  $rows  [lower, upper, rate] */
    protected function table(string $currency, string $from, array $rows, ?int $tenantId = null): void
    {
        foreach ($rows as [$lower, $upper, $rate]) {
            PayrollTaxBand::create(['tenant_id' => $tenantId, 'currency' => $currency, 'frequency' => 'monthly',
                'effective_from' => $from, 'lower_bound' => $lower, 'upper_bound' => $upper, 'rate' => $rate,
                'source_url' => 'https://www.zimra.co.zw/', 'is_approved' => true]);
        }
    }

    protected function soleEmployee(?int $tenantId = null): Employee
    {
        $company = new Company(['name' => 'Employer', 'code' => 'EMP', 'type' => 'holding', 'currency' => 'USD']);
        $company->tenant_id = $tenantId;
        $company->save();

        return Employee::create(['company_id' => $company->id, 'employee_no' => 'E1', 'first_name' => 'A', 'last_name' => 'B',
            'contract_type' => 'permanent', 'pay_frequency' => 'monthly', 'base_salary' => 1000]);
    }

    /** @return array{0:User,1:Tenant} */
    protected function tenantAdmin(string $name, string $code): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $tenant = Tenant::create(['name' => $name]);
        $company = new Company(['name' => $name, 'code' => $code, 'type' => 'holding', 'currency' => 'USD']);
        $company->tenant_id = $tenant->id;
        $company->save();

        $user = User::factory()->create(['current_company_id' => $company->id]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();
        $user->companies()->attach($company->id);
        setPermissionsTeamId($company->id);
        $user->assignRole('holding-admin');

        return [$user->fresh(), $tenant];
    }
}
