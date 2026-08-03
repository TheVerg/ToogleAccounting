<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ChartOfAccountsService;
use App\Services\ExchangeRateService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;

    protected Tenant $tenantB;

    protected User $adminA;

    protected User $adminB;

    protected Company $companyA;

    protected Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        [$this->tenantA, $this->companyA, $this->adminA] = $this->makeTenant('Alpha Group', 'A');
        [$this->tenantB, $this->companyB, $this->adminB] = $this->makeTenant('Beta Group', 'B');
    }

    protected function makeTenant(string $name, string $code): array
    {
        $tenant = Tenant::create(['name' => $name]);

        $company = new Company(['name' => $name.' Holdings', 'code' => $code, 'type' => 'holding']);
        $company->tenant_id = $tenant->id;
        $company->save();
        app(ChartOfAccountsService::class)->seedDefaultChart($company);

        $admin = User::factory()->create(['current_company_id' => $company->id]);
        $admin->forceFill(['tenant_id' => $tenant->id])->save();
        $admin->companies()->attach($company->id);

        DB::table('model_has_roles')->insert([
            'role_id' => Role::where('name', 'system-admin')->first()->id,
            'model_type' => $admin->getMorphClass(),
            'model_id' => $admin->id,
            'team_id' => $company->id,
        ]);

        return [$tenant, $company->fresh(), $admin->fresh()];
    }

    public function test_companies_and_users_are_invisible_across_tenants(): void
    {
        $this->actingAs($this->adminA);

        $this->assertSame([$this->companyA->id], Company::pluck('id')->all());
        $this->assertSame([$this->adminA->id], User::pluck('id')->all());

        // Even a system admin's "all companies" view stays inside the tenant.
        $this->assertSame(
            [$this->companyA->id],
            $this->adminA->accessibleCompanies()->pluck('id')->all(),
        );
    }

    public function test_route_model_binding_404s_for_another_tenants_records(): void
    {
        $this->actingAs($this->adminA)
            ->put('/companies/'.$this->companyB->id, ['name' => 'Hijack'])
            ->assertNotFound();

        $this->actingAs($this->adminA)
            ->post('/switch-company', ['company_id' => $this->companyB->id])
            ->assertNotFound();
    }

    public function test_created_records_inherit_the_creators_tenant(): void
    {
        $this->actingAs($this->adminA)->post('/companies', [
            'name' => 'Alpha Sub', 'code' => 'AS', 'type' => 'subsidiary',
            'parent_id' => $this->companyA->id, 'currency' => 'USD',
        ])->assertSessionHasNoErrors();

        $company = Company::withoutGlobalScopes()->where('code', 'AS')->first();
        $this->assertSame($this->tenantA->id, $company->tenant_id);

        $this->actingAs($this->adminA)->post('/admin/users', [
            'name' => 'Alpha Accountant', 'email' => 'accountant@alpha.test',
            'assignments' => [['company_id' => $this->companyA->id, 'role' => 'accountant']],
        ])->assertSessionHasNoErrors();

        $user = User::withoutGlobalScopes()->where('email', 'accountant@alpha.test')->first();
        $this->assertSame($this->tenantA->id, $user->tenant_id);
    }

    public function test_users_cannot_be_assigned_to_another_tenants_company(): void
    {
        $this->actingAs($this->adminA)->post('/admin/users', [
            'name' => 'Intruder', 'email' => 'intruder@alpha.test',
            'assignments' => [['company_id' => $this->companyB->id, 'role' => 'accountant']],
        ])->assertSessionHasErrors();

        $this->assertNull(User::withoutGlobalScopes()->where('email', 'intruder@alpha.test')->first());
    }

    public function test_company_codes_are_unique_per_tenant_not_globally(): void
    {
        // Tenant B may reuse tenant A's code...
        $this->actingAs($this->adminB)->post('/companies', [
            'name' => 'Beta Twin', 'code' => 'A', 'type' => 'subsidiary',
            'parent_id' => $this->companyB->id, 'currency' => 'USD',
        ])->assertSessionHasNoErrors();

        // ...but not its own.
        $this->actingAs($this->adminB)->post('/companies', [
            'name' => 'Beta Dup', 'code' => 'B', 'type' => 'subsidiary',
            'parent_id' => $this->companyB->id, 'currency' => 'USD',
        ])->assertSessionHasErrors('code');
    }

    public function test_manual_exchange_rates_are_per_tenant_and_beat_shared_rates(): void
    {
        // Shared auto-fetched rate, visible to everyone.
        ExchangeRate::create([
            'date' => '2026-07-01', 'base_currency' => 'USD', 'quote_currency' => 'ZWG',
            'rate' => 26, 'source' => 'auto (verified)',
        ]);
        // Tenant A's own manual rate for the same day.
        ExchangeRate::create([
            'date' => '2026-07-01', 'base_currency' => 'USD', 'quote_currency' => 'ZWG',
            'rate' => 30, 'source' => 'manual', 'tenant_id' => $this->tenantA->id,
        ]);

        $fx = app(ExchangeRateService::class);

        $this->actingAs($this->adminA);
        $this->assertSame(30.0, $fx->rateFor($this->companyA, 'ZWG', '2026-07-05'));

        $this->actingAs($this->adminB);
        $this->assertSame(26.0, $fx->rateFor($this->companyB, 'ZWG', '2026-07-05'));

        // Tenant B cannot delete the shared reference rate.
        $shared = ExchangeRate::whereNull('tenant_id')->first();
        $this->actingAs($this->adminB)
            ->delete('/settings/exchange-rates/'.$shared->id)
            ->assertForbidden();
    }

    public function test_tenant_create_command_bootstraps_an_isolated_organization(): void
    {
        $this->artisan('tenant:create', [
            'name' => 'Gamma Group',
            '--admin-email' => 'owner@gamma.test',
            '--currency' => 'usd',
        ])->assertSuccessful();

        $tenant = Tenant::where('name', 'Gamma Group')->firstOrFail();
        $owner = User::withoutGlobalScopes()->where('email', 'owner@gamma.test')->firstOrFail();
        $company = Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->assertSame($tenant->id, $owner->tenant_id);
        $this->assertTrue($owner->must_change_password);
        $this->assertTrue($owner->fresh()->isSystemAdmin());
        $this->assertSame('USD', $company->currency);
        $this->assertTrue($company->accounts()->where('code', '1000')->exists());

        // And the new organization sees only itself.
        $this->actingAs($owner);
        $this->assertSame([$company->id], Company::pluck('id')->all());
    }
}
