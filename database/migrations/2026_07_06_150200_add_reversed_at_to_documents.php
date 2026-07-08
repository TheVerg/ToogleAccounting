<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stamped when a document's journal entry is reversed, so the
     * document list agrees with the ledger.
     */
    public function up(): void
    {
        foreach (['invoice_payments', 'expenses', 'payments', 'equity_transactions', 'intercompany_transfers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->timestamp('reversed_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['invoice_payments', 'expenses', 'payments', 'equity_transactions', 'intercompany_transfers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('reversed_at');
            });
        }
    }
};
