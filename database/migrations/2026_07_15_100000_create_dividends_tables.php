<?php

use App\Models\Company;
use App\Services\ChartOfAccountsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A declaration: total profit distributed, split across the
        // shareholder register at declaration-date percentages.
        Schema::create('dividends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('dividend_no', 40);
            $table->date('date');
            $table->decimal('total_amount', 18, 2);
            $table->string('status', 20)->default('declared'); // declared | partially_paid | paid | reversed
            $table->text('description')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'dividend_no']);
            $table->index(['company_id', 'status']);
        });

        // One row per shareholder: their slice, locked at the shares and
        // percentage they held on the declaration date.
        Schema::create('dividend_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dividend_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shareholder_id')->nullable()->constrained()->nullOnDelete();
            $table->string('shareholder_name');
            $table->decimal('shares', 18, 2)->default(0);
            $table->decimal('percent', 8, 4)->default(0);
            $table->decimal('amount', 18, 2);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->timestamps();

            $table->index('dividend_id');
        });

        Schema::create('dividend_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dividend_allocation_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('amount', 18, 2);
            $table->string('reference')->nullable();
            $table->foreignId('money_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['dividend_allocation_id', 'date']);
        });

        // Add the new template accounts (2130 Dividends Payable, 3950
        // Dividends Declared) to companies seeded before them.
        $chart = app(ChartOfAccountsService::class);
        foreach (Company::all() as $company) {
            $chart->seedDefaultChart($company);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dividend_payments');
        Schema::dropIfExists('dividend_allocations');
        Schema::dropIfExists('dividends');
    }
};
