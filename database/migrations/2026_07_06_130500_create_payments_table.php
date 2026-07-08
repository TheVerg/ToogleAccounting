<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('payment_no', 30);
            // outgoing: pay a supplier/liability/expense category from a money account
            // transfer: move money between two money accounts (e.g. petty cash top-up)
            $table->enum('kind', ['outgoing', 'transfer'])->default('outgoing');
            $table->date('date');
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('from_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('category_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('method', 30)->nullable();
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'payment_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
