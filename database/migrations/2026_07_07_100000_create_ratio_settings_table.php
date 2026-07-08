<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-company target ranges for the financial ratios report.
     * When a company has no row for a ratio, the built-in default applies.
     */
    public function up(): void
    {
        Schema::create('ratio_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->decimal('target_min', 14, 4)->nullable();
            $table->decimal('target_max', 14, 4)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratio_settings');
    }
};
