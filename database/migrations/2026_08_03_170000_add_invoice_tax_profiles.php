<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('default_tax_mode', 12)->default('exclusive')->after('currency');
        });
        Schema::table('tax_rates', function (Blueprint $table) {
            $table->string('default_price_mode', 12)->nullable()->after('rate');
            $table->unsignedSmallInteger('calculation_order')->default(1)->after('default_price_mode');
            $table->boolean('is_compound')->default(false)->after('calculation_order');
            $table->boolean('is_fiscal_tax')->default(true)->after('is_compound');
        });
        Schema::create('tax_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });
        Schema::create('tax_group_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_rate_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('calculation_order')->default(1);
            $table->boolean('is_compound')->default(false);
            $table->timestamps();
            $table->unique(['tax_group_id', 'tax_rate_id']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('tax_mode', 12)->default('exclusive')->after('subtotal');
            $table->foreignId('tax_group_id')->nullable()->after('tax_mode')->constrained()->nullOnDelete();
        });
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('tax_group_id')->nullable()->after('account_id')->constrained()->nullOnDelete();
            $table->decimal('tax_amount', 18, 2)->default(0)->after('amount');
            $table->decimal('gross_amount', 18, 2)->default(0)->after('tax_amount');
        });
        Schema::create('invoice_item_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tax_name', 100);
            $table->decimal('rate', 8, 3);
            $table->unsignedSmallInteger('calculation_order')->default(1);
            $table->boolean('is_compound')->default(false);
            $table->boolean('is_fiscal_tax')->default(true);
            $table->unsignedInteger('zimra_tax_id')->nullable();
            $table->string('zimra_tax_code', 10)->nullable();
            $table->decimal('taxable_amount', 18, 2);
            $table->decimal('tax_amount', 18, 2);
            $table->timestamps();
        });

        DB::statement('UPDATE invoice_items SET gross_amount = amount');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_item_taxes');
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_group_id');
            $table->dropColumn(['tax_amount', 'gross_amount']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_group_id');
            $table->dropColumn('tax_mode');
        });
        Schema::dropIfExists('tax_group_rates');
        Schema::dropIfExists('tax_groups');
        Schema::table('tax_rates', fn (Blueprint $table) => $table->dropColumn([
            'default_price_mode', 'calculation_order', 'is_compound', 'is_fiscal_tax',
        ]));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('default_tax_mode'));
    }
};
