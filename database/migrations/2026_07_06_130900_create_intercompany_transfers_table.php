<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intercompany_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('to_company_id')->constrained('companies')->cascadeOnDelete();
            $table->enum('type', ['loan', 'capital']); // loan = repayable, capital = investment
            $table->date('date');
            $table->decimal('amount', 18, 2);
            $table->foreignId('from_money_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('to_money_account_id')->constrained('accounts')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->foreignId('from_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('to_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intercompany_transfers');
    }
};
