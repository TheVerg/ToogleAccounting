<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a rate came from: 'manual' (entered on the Settings page),
 * 'auto (verified)' (fetched and confirmed against the RBZ published
 * rate), or 'auto (unverified)' (fetched with the cross-check source
 * unavailable). Auto-fetch never overwrites a manual rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->string('source', 40)->nullable();
        });

        DB::table('exchange_rates')->whereNull('source')->update(['source' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('exchange_rates', fn (Blueprint $t) => $t->dropColumn('source'));
    }
};
