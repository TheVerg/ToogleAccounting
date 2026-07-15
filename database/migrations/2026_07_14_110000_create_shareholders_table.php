<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-company shareholder register. Ownership percentage is derived
     * from each shareholder's share of the total shares held, so it is
     * never stored. Share issues and shareholder loans link back here so
     * computations know which holder to add to or deduct from.
     */
    public function up(): void
    {
        Schema::create('shareholders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->decimal('shares_held', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active']);
        });

        Schema::table('equity_transactions', function (Blueprint $table) {
            $table->foreignId('shareholder_id')->nullable()->after('owner_name')
                ->constrained()->nullOnDelete();
        });

        Schema::table('shareholder_loans', function (Blueprint $table) {
            $table->foreignId('shareholder_id')->nullable()->after('lender_name')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shareholder_loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shareholder_id');
        });
        Schema::table('equity_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shareholder_id');
        });
        Schema::dropIfExists('shareholders');
    }
};
