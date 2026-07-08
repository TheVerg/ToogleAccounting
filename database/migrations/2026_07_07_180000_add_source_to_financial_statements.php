<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Statements are either generated from the ledger or imported from a template. */
    public function up(): void
    {
        Schema::table('financial_statements', function (Blueprint $table) {
            $table->string('source', 15)->default('generated'); // generated | imported
        });
    }

    public function down(): void
    {
        Schema::table('financial_statements', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
