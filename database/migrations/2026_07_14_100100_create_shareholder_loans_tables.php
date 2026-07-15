<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shareholder_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('loan_no', 40);
            $table->string('lender_name');
            $table->date('date');
            $table->decimal('principal', 18, 2);
            $table->decimal('total_repaid', 18, 2)->default(0);
            $table->decimal('outstanding_balance', 18, 2)->default(0);
            $table->string('status', 20)->default('active'); // active | repaid | reversed
            $table->text('description')->nullable();
            $table->foreignId('money_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('liability_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'loan_no']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('shareholder_loan_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shareholder_loan_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('amount', 18, 2);
            $table->string('reference')->nullable();
            $table->foreignId('money_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['shareholder_loan_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shareholder_loan_repayments');
        Schema::dropIfExists('shareholder_loans');
    }
};
