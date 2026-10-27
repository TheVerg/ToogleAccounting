<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAYE tables and statutory rates are national reference data, so they follow
 * the exchange-rate pattern: platform-published rows are shared (tenant_id
 * null) and a tenant may publish its own overriding rows. Without this the
 * tables were global and writable by any payroll manager in any tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_tax_bands', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained('tenants')->nullOnDelete();
            $table->index(['tenant_id', 'currency', 'frequency', 'effective_from'], 'payroll_tax_band_tenant_lookup');
        });

        Schema::table('payroll_statutory_rates', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained('tenants')->nullOnDelete();
            $table->dropUnique('payroll_statutory_rate_unique');
            $table->unique(['tenant_id', 'jurisdiction', 'code', 'currency', 'effective_from'], 'payroll_statutory_rate_tenant_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_statutory_rates', function (Blueprint $table) {
            $table->dropUnique('payroll_statutory_rate_tenant_unique');
            $table->dropConstrainedForeignId('tenant_id');
            $table->unique(['jurisdiction', 'code', 'currency', 'effective_from'], 'payroll_statutory_rate_unique');
        });

        Schema::table('payroll_tax_bands', function (Blueprint $table) {
            $table->dropIndex('payroll_tax_band_tenant_lookup');
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
