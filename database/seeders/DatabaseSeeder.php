<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\ChartOfAccountsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $chart = app(ChartOfAccountsService::class);

        $holding = Company::updateOrCreate(
            ['code' => 'HOLD'],
            ['name' => 'Horizon Equity Group', 'type' => 'holding', 'currency' => 'USD'],
        );

        $chart->seedDefaultChart($holding);

        TaxRate::firstOrCreate(
            ['company_id' => null, 'name' => 'VAT 15%'],
            ['rate' => 15.0, 'is_active' => true],
        );
        TaxRate::firstOrCreate(
            ['company_id' => null, 'name' => 'No Tax'],
            ['rate' => 0.0, 'is_active' => true],
        );

        $adminEmail = 'admin@horizon-equity.com';
        $admin = User::where('email', $adminEmail)->first()
            ?? User::where('email', 'admin@holdco.test')->first();

        if ($admin) {
            $admin->forceFill(['email' => $adminEmail])->save();
        } else {
            $initialPassword = config('auth.bootstrap_admin_password');
            if (! $initialPassword) {
                throw new \RuntimeException('ADMIN_INITIAL_PASSWORD is required when creating the production administrator.');
            }
            $admin = User::create([
                'email' => $adminEmail,
                'name' => 'System Admin',
                'password' => Hash::make($initialPassword),
                'must_change_password' => true,
                'current_company_id' => $holding->id,
            ]);
        }
        setPermissionsTeamId($holding->id);
        $admin->unsetRelation('roles')->syncRoles(['system-admin']);
        $admin->companies()->syncWithoutDetaching([$holding->id]);
        setPermissionsTeamId(null);
    }
}
