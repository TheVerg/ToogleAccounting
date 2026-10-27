<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `fdms:monitor` re-reported every failed/invalid receipt on every run, so a
 * single unresolvable receipt drowned the alert channel indefinitely.
 * Acknowledging one records who accepted it and silences that alert without
 * touching the receipt itself — the log stays append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_receipts', function (Blueprint $table) {
            $table->timestamp('alert_acknowledged_at')->nullable()->after('error');
            $table->foreignId('alert_acknowledged_by')->nullable()->after('alert_acknowledged_at')
                ->constrained('users')->nullOnDelete();
            $table->string('alert_acknowledged_note', 255)->nullable()->after('alert_acknowledged_by');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('alert_acknowledged_by');
            $table->dropColumn(['alert_acknowledged_at', 'alert_acknowledged_note']);
        });
    }
};
