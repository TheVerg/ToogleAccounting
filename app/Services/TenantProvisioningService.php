<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Creates a complete, isolated organization in one step: the tenant,
 * its first (holding) company with a default chart of accounts, and its
 * owner as that tenant's system admin. Used by both public
 * self-registration and the ops-side `tenant:create` command.
 */
class TenantProvisioningService
{
    public function __construct(protected ChartOfAccountsService $chart) {}

    /**
     * @param  string  $hashedPassword  Already-hashed password for the owner.
     * @return array{tenant: Tenant, company: Company, owner: User}
     */
    public function provision(
        string $organizationName,
        string $ownerName,
        string $ownerEmail,
        string $hashedPassword,
        string $currency = 'USD',
        ?string $companyName = null,
        string $companyCode = 'HQ',
        string $status = 'active',
        ?int $trialDays = null,
        bool $mustChangePassword = false,
    ): array {
        $this->ensureRolesExist();

        return DB::transaction(function () use (
            $organizationName, $ownerName, $ownerEmail, $hashedPassword, $currency,
            $companyName, $companyCode, $status, $trialDays, $mustChangePassword,
        ) {
            $tenant = Tenant::create([
                'name' => $organizationName,
                'is_active' => true,
                'status' => $status,
                'trial_ends_at' => $trialDays ? now()->addDays($trialDays) : null,
            ]);

            $company = new Company([
                'name' => $companyName ?: $organizationName,
                'code' => $companyCode,
                'type' => 'holding',
                'currency' => strtoupper($currency),
                'is_active' => true,
            ]);
            $company->tenant_id = $tenant->id;
            $company->save();
            $this->chart->seedDefaultChart($company);

            $owner = new User([
                'name' => $ownerName,
                'email' => $ownerEmail,
                'password' => $hashedPassword,
                'must_change_password' => $mustChangePassword,
                'current_company_id' => $company->id,
            ]);
            $owner->tenant_id = $tenant->id;
            $owner->save();

            $owner->companies()->attach($company->id);

            $adminRole = Role::where('name', 'system-admin')->firstOrFail();
            DB::table('model_has_roles')->insert([
                'role_id' => $adminRole->id,
                'model_type' => $owner->getMorphClass(),
                'model_id' => $owner->id,
                'team_id' => $company->id,
            ]);

            AuditLog::create([
                'user_id' => null,
                'company_id' => $company->id,
                'tenant_id' => $tenant->id,
                'action' => 'tenant_created',
                'auditable_type' => $tenant->getMorphClass(),
                'auditable_id' => $tenant->id,
            ]);

            return ['tenant' => $tenant, 'company' => $company, 'owner' => $owner];
        });
    }

    protected function ensureRolesExist(): void
    {
        if (! Role::where('name', 'system-admin')->exists()) {
            (new RolesAndPermissionsSeeder)->run();
        }
    }
}
