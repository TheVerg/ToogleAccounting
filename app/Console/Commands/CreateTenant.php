<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TenantProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Ops-side tenant onboarding: the tenant, its first company (with a
 * default chart of accounts), and its first system-admin user with a
 * temporary password. Customers can also self-register at /register
 * (which starts a trial); this command provisions as 'active'.
 */
class CreateTenant extends Command
{
    protected $signature = 'tenant:create
        {name : The organization (tenant) name}
        {--company= : First company name (defaults to the tenant name)}
        {--code=HQ : First company code}
        {--currency=USD : First company base currency}
        {--admin-name= : Admin user full name}
        {--admin-email= : Admin user email (required)}
        {--trial : Provision on a trial instead of active}';

    protected $description = 'Create a new tenant with its first company and system-admin user';

    public function handle(TenantProvisioningService $provisioning): int
    {
        $adminEmail = $this->option('admin-email');
        if (! $adminEmail) {
            $this->error('Provide --admin-email for the first user.');

            return self::FAILURE;
        }
        if (User::withoutGlobalScopes()->where('email', $adminEmail)->exists()) {
            $this->error("A user with email {$adminEmail} already exists.");

            return self::FAILURE;
        }

        $temporaryPassword = Str::password(16);

        $provisioned = $provisioning->provision(
            organizationName: $this->argument('name'),
            ownerName: $this->option('admin-name') ?: $this->argument('name').' Admin',
            ownerEmail: $adminEmail,
            hashedPassword: Hash::make($temporaryPassword),
            currency: $this->option('currency'),
            companyName: $this->option('company'),
            companyCode: $this->option('code'),
            status: $this->option('trial') ? 'trial' : 'active',
            trialDays: $this->option('trial') ? (int) config('services.signup.trial_days') : null,
            mustChangePassword: true,
        );

        $tenant = $provisioned['tenant'];
        $company = $provisioned['company'];

        $this->info("Tenant '{$tenant->name}' created (id {$tenant->id}, status {$tenant->status}).");
        $this->info("Company: {$company->name} [{$company->code}], base currency {$company->currency}.");
        $this->line('');
        $this->info("Admin login: {$adminEmail}");
        $this->info("Temporary password: {$temporaryPassword}");
        $this->warn('Share it securely — it will not be shown again, and must be changed at first login.');

        return self::SUCCESS;
    }
}
