<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * User-defined ratios: numerator ÷ denominator picked from the
     * report's figure catalogue, shown alongside the built-in ratios.
     */
    public function up(): void
    {
        Schema::create('custom_ratios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('section', 20)->default('custom'); // liquidity | profitability | leverage | efficiency | custom
            $table->string('numerator', 40);
            $table->string('denominator', 40);
            $table->string('format', 5)->default('x'); // x | %
            $table->decimal('target_min', 14, 4)->nullable();
            $table->decimal('target_max', 14, 4)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_ratios');
    }
};
