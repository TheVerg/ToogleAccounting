<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('employee_no', 30);
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('national_id', 60)->nullable();
            // Employment contract
            $table->string('job_title')->nullable();
            $table->string('department')->nullable();
            $table->string('contract_type', 20)->default('permanent'); // permanent | contract | temporary
            $table->string('pay_frequency', 20)->default('monthly');   // monthly | weekly | biweekly
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('base_salary', 18, 2)->default(0); // basic salary
            $table->foreignId('payment_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'employee_no']);
            $table->index(['company_id', 'is_active']);
        });

        // Recurring salary structure items (allowances / deductions).
        Schema::create('salary_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 15);  // earning | deduction
            $table->string('code', 30);  // basic | housing | transport | bonus | paye | pension | loan | other
            $table->string('name');
            $table->string('calc_type', 20)->default('fixed'); // fixed | percent_of_basic
            $table->decimal('amount', 18, 2)->default(0);      // fixed money, or percent value
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('employee_id');
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 40);
            $table->date('period_from');
            $table->date('period_to');
            $table->date('pay_date');
            $table->foreignId('money_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('status', 15)->default('draft'); // draft | approved
            $table->decimal('total_gross', 18, 2)->default(0);
            $table->decimal('total_deductions', 18, 2)->default(0);
            $table->decimal('total_net', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'reference']);
            $table->index(['company_id', 'status']);
        });

        // One computed pay slip per employee per run (frozen breakdown).
        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('employee_name');   // snapshot
            $table->string('job_title')->nullable();
            $table->decimal('basic', 18, 2)->default(0);
            $table->decimal('earnings_total', 18, 2)->default(0);
            $table->decimal('deductions_total', 18, 2)->default(0);
            $table->decimal('gross', 18, 2)->default(0);
            $table->decimal('net', 18, 2)->default(0);
            $table->json('lines'); // [{kind,code,name,amount,source:recurring|once_off}]
            $table->timestamps();

            $table->index('payroll_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('salary_components');
        Schema::dropIfExists('employees');
    }
};
