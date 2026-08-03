<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('tin', 10)->nullable()->after('tax_number');
            $table->string('vat_number', 9)->nullable()->after('tin');
            $table->string('trade_name', 250)->nullable()->after('vat_number');
            $table->string('province', 100)->nullable()->after('billing_address');
            $table->string('city', 100)->nullable()->after('province');
            $table->string('street', 100)->nullable()->after('city');
            $table->string('house_no', 250)->nullable()->after('street');
            $table->string('district', 100)->nullable()->after('house_no');
        });

        DB::table('fiscal_receipts')
            ->where('status', 'submitted')
            ->where('error', 'like', 'FDMS validation:%')
            ->update(['status' => 'invalid']);
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn([
                'tin', 'vat_number', 'trade_name', 'province', 'city',
                'street', 'house_no', 'district',
            ]);
        });
    }
};
