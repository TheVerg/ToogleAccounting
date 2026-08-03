<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenancy: a tenant is one customer organization (a business and
 * its group of companies). Users and companies belong to a tenant; every
 * other table inherits isolation through its company_id. Existing data
 * is gathered into a single default tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
        });
        // Company codes are unique within a tenant, not across the world:
        // two unrelated businesses can both have a company coded "H".
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->unique(['tenant_id', 'code']);
        });
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
        });

        // Auto-fetched rates are shared reference data (tenant_id null);
        // manually entered rates belong to the tenant that entered them
        // and take precedence for that tenant only.
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->dropUnique(['date', 'base_currency', 'quote_currency']);
            $table->unique(['tenant_id', 'date', 'base_currency', 'quote_currency'], 'exchange_rates_tenant_pair_unique');
        });

        $this->backfill();
    }

    /** Gather all existing data into one default tenant. */
    protected function backfill(): void
    {
        if (! DB::table('companies')->exists() && ! DB::table('users')->exists()) {
            return;
        }

        $holding = DB::table('companies')
            ->whereNull('deleted_at')
            ->where('type', 'holding')
            ->orderBy('id')
            ->first();

        $tenantId = DB::table('tenants')->insertGetId([
            'name' => $holding?->name ?? 'Default Tenant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->update(['tenant_id' => $tenantId]);
        DB::table('companies')->update(['tenant_id' => $tenantId]);
        DB::table('audit_logs')->update(['tenant_id' => $tenantId]);
        DB::table('exchange_rates')->where('source', 'manual')->update(['tenant_id' => $tenantId]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'code']);
            $table->unique(['code']);
        });
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->dropUnique('exchange_rates_tenant_pair_unique');
            $table->unique(['date', 'base_currency', 'quote_currency']);
            $table->dropConstrainedForeignId('tenant_id');
        });

        foreach (['users', 'companies', 'audit_logs'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('tenant_id');
            });
        }

        Schema::dropIfExists('tenants');
    }
};
