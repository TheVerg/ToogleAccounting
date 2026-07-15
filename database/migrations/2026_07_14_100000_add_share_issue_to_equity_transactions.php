<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Equity transactions gain a third type, share_issue, which splits
     * the money received between share capital (nominal value) and
     * share premium (excess over nominal).
     */
    public function up(): void
    {
        // Widen the enum so 'share_issue' is accepted.
        Schema::table('equity_transactions', function (Blueprint $table) {
            $table->string('type', 30)->change();
        });

        Schema::table('equity_transactions', function (Blueprint $table) {
            $table->unsignedInteger('shares_count')->nullable()->after('owner_name');
            $table->decimal('share_capital_amount', 18, 2)->nullable()->after('amount');
            $table->decimal('share_premium_amount', 18, 2)->nullable()->after('share_capital_amount');
            $table->foreignId('premium_account_id')->nullable()->after('equity_account_id')
                ->constrained('accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('equity_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('premium_account_id');
            $table->dropColumn(['shares_count', 'share_capital_amount', 'share_premium_amount']);
        });
    }
};
