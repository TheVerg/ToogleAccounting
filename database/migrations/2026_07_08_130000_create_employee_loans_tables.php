<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('loan_no', 40);
            $table->date('issue_date');
            $table->decimal('principal', 18, 2);
            $table->decimal('repayment_amount', 18, 2)->default(0);
            $table->decimal('total_repaid', 18, 2)->default(0);
            $table->decimal('outstanding_balance', 18, 2)->default(0);
            $table->string('status', 20)->default('active'); // active | settled | written_off
            $table->text('notes')->nullable();
            $table->foreignId('money_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'loan_no']);
            $table->index(['company_id', 'status']);
            $table->index(['employee_id', 'status']);
        });

        Schema::create('employee_loan_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payroll_item_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->decimal('amount', 18, 2);
            $table->string('source', 20)->default('payroll'); // payroll | manual | adjustment
            $table->string('reference')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_loan_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_loan_payments');
        Schema::dropIfExists('employee_loans');
    }
};
