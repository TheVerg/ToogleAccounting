<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiscalisation Package 0 (docs/FISCALISATION.md): the invoice fields
 * FDMS requires — HS codes on invoice lines (auto-filled from the income
 * category's default so users rarely type them) and the ZIMRA tax
 * identifiers on tax rates. Invoice currency already exists
 * (multi-currency Phase A).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('hs_code', 8)->nullable();
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('default_hs_code', 8)->nullable();
        });

        Schema::table('tax_rates', function (Blueprint $table) {
            // ZIMRA's numeric tax ID (e.g. 2026 reference: 515 = standard
            // VAT 15.5%, 514 = withholding 5%, 2 = zero-rated, 3 = exempt)
            // and letter code, as configured on the fiscal device.
            $table->unsignedInteger('zimra_tax_id')->nullable();
            $table->string('zimra_tax_code', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', fn (Blueprint $t) => $t->dropColumn('hs_code'));
        Schema::table('accounts', fn (Blueprint $t) => $t->dropColumn('default_hs_code'));
        Schema::table('tax_rates', fn (Blueprint $t) => $t->dropColumn(['zimra_tax_id', 'zimra_tax_code']));
    }
};
