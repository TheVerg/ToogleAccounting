<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_tax_bands', function (Blueprint $table) {
            $table->id();
            $table->string('jurisdiction', 2)->default('ZW');
            $table->string('currency', 3);
            $table->string('frequency', 12)->default('monthly');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->decimal('lower_bound', 18, 2);
            $table->decimal('upper_bound', 18, 2)->nullable();
            $table->decimal('rate', 8, 6);
            $table->decimal('deduction', 18, 2)->default(0);
            $table->string('source_url')->nullable();
            $table->boolean('is_approved')->default(false);
            $table->timestamps();
            $table->index(['currency', 'frequency', 'effective_from'], 'payroll_tax_band_lookup');
        });

        Schema::create('payroll_statutory_rates', function (Blueprint $table) {
            $table->id();
            $table->string('jurisdiction', 2)->default('ZW');
            $table->string('code', 40);
            $table->string('currency', 3)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->decimal('employee_rate', 8, 6)->default(0);
            $table->decimal('employer_rate', 8, 6)->default(0);
            $table->decimal('ceiling', 18, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->string('source_url')->nullable();
            $table->boolean('is_approved')->default(false);
            $table->timestamps();
            $table->unique(['jurisdiction', 'code', 'currency', 'effective_from'], 'payroll_statutory_rate_unique');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('tax_number', 60)->nullable()->after('national_id');
            $table->string('nssa_number', 60)->nullable()->after('tax_number');
            $table->boolean('nssa_registered')->default(false)->after('nssa_number');
            $table->string('pay_currency', 3)->nullable()->after('pay_frequency');
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->string('currency', 3)->nullable()->after('pay_date');
            $table->decimal('exchange_rate', 18, 6)->default(1)->after('currency');
            $table->decimal('total_employer_costs', 18, 2)->default(0)->after('total_net');
            $table->json('compliance_warnings')->nullable()->after('notes');
        });

        Schema::table('payroll_items', function (Blueprint $table) {
            $table->string('currency', 3)->nullable()->after('job_title');
            $table->decimal('employer_costs_total', 18, 2)->default(0)->after('net');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', fn (Blueprint $table) => $table->dropColumn(['currency', 'employer_costs_total']));
        Schema::table('payroll_runs', fn (Blueprint $table) => $table->dropColumn(['currency', 'exchange_rate', 'total_employer_costs', 'compliance_warnings']));
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn(['tax_number', 'nssa_number', 'nssa_registered', 'pay_currency']));
        Schema::dropIfExists('payroll_statutory_rates');
        Schema::dropIfExists('payroll_tax_bands');
    }
};
