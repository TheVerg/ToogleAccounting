<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('email')->nullable()->after('currency');
            $table->string('phone', 40)->nullable()->after('email');
            $table->text('address')->nullable()->after('phone');
            $table->string('tax_number')->nullable()->after('address');
            $table->string('bank_name')->nullable()->after('tax_number');
            $table->string('bank_account_name')->nullable()->after('bank_name');
            $table->string('bank_account_number')->nullable()->after('bank_account_name');
            $table->string('bank_branch_code')->nullable()->after('bank_account_number');
            $table->string('bank_swift_code')->nullable()->after('bank_branch_code');
            $table->text('payment_instructions')->nullable()->after('bank_swift_code');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('contact_person')->nullable()->after('name');
            $table->string('tax_number')->nullable()->after('phone');
            $table->text('billing_address')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['contact_person', 'tax_number', 'billing_address']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'email',
                'phone',
                'address',
                'tax_number',
                'bank_name',
                'bank_account_name',
                'bank_account_number',
                'bank_branch_code',
                'bank_swift_code',
                'payment_instructions',
            ]);
        });
    }
};
