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

        $holding = Company::firstOrCreate(
            ['code' => 'HOLD'],
            ['name' => 'Holding Company Ltd', 'type' => 'holding', 'currency' => 'USD'],
        );

        $subA = Company::firstOrCreate(
            ['code' => 'SUB-A'],
            ['name' => 'Subsidiary Alpha Ltd', 'type' => 'subsidiary',
                'parent_id' => $holding->id, 'currency' => 'USD'],
        );

        $subB = Company::firstOrCreate(
            ['code' => 'SUB-B'],
            ['name' => 'Subsidiary Beta Ltd', 'type' => 'subsidiary',
                'parent_id' => $holding->id, 'currency' => 'USD'],
        );

        foreach ([$holding, $subA, $subB] as $company) {
            $chart->seedDefaultChart($company);
        }

        TaxRate::firstOrCreate(
            ['company_id' => null, 'name' => 'VAT 15%'],
            ['rate' => 15.0, 'is_active' => true],
        );
        TaxRate::firstOrCreate(
            ['company_id' => null, 'name' => 'No Tax'],
            ['rate' => 0.0, 'is_active' => true],
        );

        $admin = User::firstOrCreate(
            ['email' => 'admin@holdco.test'],
            ['name' => 'System Admin', 'password' => Hash::make('password'),
                'current_company_id' => $holding->id],
        );
        // Roles are per company (spatie teams): the admin is system-admin
        // in every company, the accountant only in Subsidiary Alpha.
        foreach ([$holding, $subA, $subB] as $company) {
            setPermissionsTeamId($company->id);
            $admin->unsetRelation('roles')->syncRoles(['system-admin']);
        }
        $admin->companies()->syncWithoutDetaching([$holding->id, $subA->id, $subB->id]);

        $accountant = User::firstOrCreate(
            ['email' => 'accountant@holdco.test'],
            ['name' => 'Alpha Accountant', 'password' => Hash::make('password'),
                'current_company_id' => $subA->id],
        );
        setPermissionsTeamId($subA->id);
        $accountant->unsetRelation('roles')->syncRoles(['accountant']);
        $accountant->companies()->syncWithoutDetaching([$subA->id]);
        setPermissionsTeamId(null);
    }
}
