<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-company statement layout: named sections that group chart-of-
     * accounts rows on the Income Statement and Balance Sheet, matched by
     * account type and (optionally) an account-code range. Companies with
     * no sections keep the default flat presentation.
     */
    public function up(): void
    {
        Schema::create('statement_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('statement', 30); // income_statement | balance_sheet
            $table->string('label');
            $table->string('account_type', 20); // asset | liability | equity | income | expense
            $table->string('code_from', 20)->nullable();
            $table->string('code_to', 20)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'statement']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statement_sections');
    }
};
