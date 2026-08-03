<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('return_type', 20)->default('VAT7');
            $table->date('period_from');
            $table->date('period_to');
            $table->date('due_date');
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('amendment_no')->default(0);
            $table->foreignId('amends_return_id')->nullable()->constrained('compliance_returns')->nullOnDelete();
            $table->json('snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->string('filing_reference')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('filed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('filed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'return_type', 'period_from', 'period_to', 'amendment_no'], 'compliance_return_period_unique');
        });

        Schema::create('compliance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_return_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 3);
            $table->string('category', 40);
            $table->string('description');
            $table->decimal('tax_amount', 18, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('compliance_return_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_return_id')->constrained()->cascadeOnDelete();
            $table->string('action', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('compliance_deadlines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('return_type', 20);
            $table->date('period_from');
            $table->date('period_to');
            $table->date('due_date');
            $table->string('status', 20)->default('open');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('compliance_return_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'return_type', 'period_from', 'period_to'], 'compliance_deadline_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_deadlines');
        Schema::dropIfExists('compliance_return_events');
        Schema::dropIfExists('compliance_adjustments');
        Schema::dropIfExists('compliance_returns');
    }
};
