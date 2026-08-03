<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every automated rate fetch is recorded here — including the ones that
 * stored nothing (sources disagreed, sources down, manual rate already
 * present) — so there is always a reference of what each source said on
 * each day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_fetch_logs', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->decimal('primary_rate', 18, 6)->nullable();  // aggregator
            $table->decimal('rbz_rate', 18, 6)->nullable();      // RBZ scraper mid
            $table->decimal('stored_rate', 18, 6)->nullable();   // what was saved, if anything
            $table->string('status', 20);                        // saved | held | failed | skipped
            $table->string('detail')->nullable();
            $table->timestamps();
            $table->index(['base_currency', 'quote_currency', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_fetch_logs');
    }
};
