<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Switch spatie/laravel-permission to teams mode, keyed by company.
 * Roles become per-company assignments (a user's "grade" in each company)
 * instead of global. Existing global assignments are remapped to one
 * assignment per company the user belongs to (system admins: every company).
 */
return new class extends Migration
{
    public function up(): void
    {
        // On a fresh database the stock spatie migration has already built
        // the teams schema (it reads config('permission.teams') at run time);
        // this migration only upgrades databases created before teams mode.
        if (Schema::hasColumn('model_has_roles', 'team_id')) {
            return;
        }

        $existing = DB::table('model_has_roles')->get();

        // The primary keys must include team_id, which SQLite cannot alter
        // in place — rebuild both pivot tables.
        Schema::drop('model_has_roles');
        Schema::drop('model_has_permissions');

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('team_id');

            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
            $table->index('team_id', 'model_has_permissions_team_foreign_key_index');
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->primary(['team_id', 'permission_id', 'model_id', 'model_type'],
                'model_has_permissions_permission_model_type_primary');
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('team_id');

            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->index('team_id', 'model_has_roles_team_foreign_key_index');
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary(['team_id', 'role_id', 'model_id', 'model_type'],
                'model_has_roles_role_model_type_primary');
        });

        // Roles themselves stay global (team_id null = usable by every company).
        Schema::table('roles', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable()->after('id');
            $table->index('team_id', 'roles_team_foreign_key_index');
            $table->dropUnique(['name', 'guard_name']);
            $table->unique(['team_id', 'name', 'guard_name']);
        });

        // Remap old global assignments to per-company assignments.
        $companyIds = DB::table('companies')->pluck('id');
        $systemAdminRoleId = DB::table('roles')->where('name', 'system-admin')->value('id');

        foreach ($existing as $row) {
            $teams = $row->role_id === $systemAdminRoleId
                ? $companyIds
                : DB::table('company_user')->where('user_id', $row->model_id)->pluck('company_id');

            foreach ($teams as $companyId) {
                DB::table('model_has_roles')->insertOrIgnore([
                    'role_id' => $row->role_id,
                    'model_type' => $row->model_type,
                    'model_id' => $row->model_id,
                    'team_id' => $companyId,
                ]);
            }
        }

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        $existing = DB::table('model_has_roles')->get()->unique(fn ($r) => $r->role_id.'|'.$r->model_type.'|'.$r->model_id);

        Schema::drop('model_has_roles');
        Schema::drop('model_has_permissions');

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');

            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->primary(['permission_id', 'model_id', 'model_type'],
                'model_has_permissions_permission_model_type_primary');
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');

            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary(['role_id', 'model_id', 'model_type'],
                'model_has_roles_role_model_type_primary');
        });

        foreach ($existing as $row) {
            DB::table('model_has_roles')->insertOrIgnore([
                'role_id' => $row->role_id,
                'model_type' => $row->model_type,
                'model_id' => $row->model_id,
            ]);
        }

        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'name', 'guard_name']);
            $table->dropIndex('roles_team_foreign_key_index');
            $table->dropColumn('team_id');
            $table->unique(['name', 'guard_name']);
        });
    }
};
