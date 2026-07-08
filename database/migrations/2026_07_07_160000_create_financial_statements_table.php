<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The statement register: every generated statement is saved as a
     * frozen snapshot (flat rendered lines) with a draft -> approved
     * lifecycle. Rows are soft-deleted only — deleted_at/deleted_by stay
     * visible forever, so nothing disappears without a trace.
     */
    public function up(): void
    {
        Schema::create('financial_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // trial-balance | profit-loss | balance-sheet | cash-flow | tax-summary | ratios
            $table->string('name');
            $table->string('scope_label');
            $table->date('period_from')->nullable();
            $table->date('period_to');
            $table->string('status', 15)->default('draft'); // draft | approved
            $table->json('columns');  // numeric column headings
            $table->json('lines');    // flat rows: {code,name,values[],depth,line_type}
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_statements');
    }
};
