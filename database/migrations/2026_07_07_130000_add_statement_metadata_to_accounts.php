<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Statement presentation metadata carried by each chart-of-accounts
     * line item, so financial statements compute directly from the chart:
     *  - statement: which statement the account reports on
     *  - statement_group: its section (e.g. Operating Expenses, Finance Costs)
     *  - statement_line: roll-up line (e.g. Cash and cash equivalents)
     *  - balance_type: normal balance; opposite of the type default => contra (+/-)
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('statement', 30)->nullable();       // balance_sheet | income_statement | cash_flow | equity_changes
            $table->string('statement_group', 100)->nullable();
            $table->string('statement_line', 100)->nullable();
            $table->string('balance_type', 10)->nullable();    // debit | credit
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['statement', 'statement_group', 'statement_line', 'balance_type']);
        });
    }
};
