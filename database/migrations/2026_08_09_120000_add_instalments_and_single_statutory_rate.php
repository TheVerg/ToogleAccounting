<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two shapes the compliance module could not previously express:
 *
 * 1. A QPD is a payment on account of the *annual* liability — four instalments
 *    against one year of assessment, each with its own in-year due date. The
 *    period + amendment key allowed only one return per period, so four QPDs
 *    for one tax year collided.
 * 2. Corporate income tax has a single rate with no employee/employer split,
 *    which `payroll_statutory_rates` had nowhere to put.
 *
 * `instalment` defaults to 0 rather than null: NULLs compare as distinct in a
 * unique index, so a nullable column would silently drop the duplicate-return
 * protection for every non-instalment type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compliance_returns', function (Blueprint $table) {
            $table->unsignedTinyInteger('instalment')->default(0)->after('period_to');
            $table->dropUnique('compliance_return_period_unique');
            $table->unique(['company_id', 'return_type', 'period_from', 'period_to', 'instalment', 'amendment_no'], 'compliance_return_period_unique');
        });

        Schema::table('compliance_deadlines', function (Blueprint $table) {
            $table->unsignedTinyInteger('instalment')->default(0)->after('period_to');
            $table->dropUnique('compliance_deadline_period_unique');
            $table->unique(['company_id', 'return_type', 'period_from', 'period_to', 'instalment'], 'compliance_deadline_period_unique');
        });

        Schema::table('payroll_statutory_rates', function (Blueprint $table) {
            // For codes with no employee/employer split (corporate income tax).
            $table->decimal('rate', 8, 6)->nullable()->after('employer_rate');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_statutory_rates', fn (Blueprint $table) => $table->dropColumn('rate'));

        Schema::table('compliance_deadlines', function (Blueprint $table) {
            $table->dropUnique('compliance_deadline_period_unique');
            $table->dropColumn('instalment');
            $table->unique(['company_id', 'return_type', 'period_from', 'period_to'], 'compliance_deadline_period_unique');
        });

        Schema::table('compliance_returns', function (Blueprint $table) {
            $table->dropUnique('compliance_return_period_unique');
            $table->dropColumn('instalment');
            $table->unique(['company_id', 'return_type', 'period_from', 'period_to', 'amendment_no'], 'compliance_return_period_unique');
        });
    }
};
