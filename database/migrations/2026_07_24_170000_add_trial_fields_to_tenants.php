<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing Phase 1 groundwork (docs/BILLING.md), pulled forward with
 * self-registration: a signing-up tenant starts on a trial. Existing
 * tenants stay 'active'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('plan', 20)->nullable();
            $table->string('status', 20)->default('active'); // trial | active | past_due | suspended
            $table->timestamp('trial_ends_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['plan', 'status', 'trial_ends_at']));
    }
};
