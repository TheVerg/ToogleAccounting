<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'companies.view', 'companies.manage',
        'users.manage', 'roles.manage',
        'accounts.view', 'accounts.manage',
        'journals.view', 'journals.create', 'journals.reverse',
        'contacts.view', 'contacts.manage',
        'invoices.view', 'invoices.manage',
        'payments.view', 'payments.manage',
        'expenses.view', 'expenses.manage',
        'payroll.view', 'payroll.manage',
        'equity.view', 'equity.manage',
        'budgets.view', 'budgets.manage',
        'intercompany.manage',
        'taxes.manage',
        'reports.view',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // System admin: everything, including the permissions section itself.
        $admin = Role::firstOrCreate(['name' => 'system-admin', 'guard_name' => 'web']);
        $admin->syncPermissions(Permission::all());

        // Holding admin: full accounting control, no user/role administration.
        $holding = Role::firstOrCreate(['name' => 'holding-admin', 'guard_name' => 'web']);
        $holding->syncPermissions(array_diff(self::PERMISSIONS, ['users.manage', 'roles.manage']));

        // Accountant: day-to-day bookkeeping in assigned companies.
        $accountant = Role::firstOrCreate(['name' => 'accountant', 'guard_name' => 'web']);
        $accountant->syncPermissions([
            'companies.view',
            'accounts.view', 'accounts.manage',
            'journals.view', 'journals.create',
            'contacts.view', 'contacts.manage',
            'invoices.view', 'invoices.manage',
            'payments.view', 'payments.manage',
            'expenses.view', 'expenses.manage',
            'payroll.view', 'payroll.manage',
            'equity.view', 'equity.manage',
            'budgets.view', 'budgets.manage',
            'reports.view',
        ]);

        // Viewer: read-only access.
        $viewer = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
        $viewer->syncPermissions([
            'companies.view', 'accounts.view', 'journals.view', 'contacts.view',
            'invoices.view', 'payments.view', 'expenses.view', 'payroll.view', 'equity.view',
            'budgets.view', 'reports.view',
        ]);
    }
}
