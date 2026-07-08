<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equity_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['contribution', 'drawing']);
            $table->date('date');
            $table->string('owner_name');
            $table->decimal('amount', 18, 2);
            $table->foreignId('money_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('equity_account_id')->constrained('accounts')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equity_transactions');
    }
};
