<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Soft deletes across master data and users: deletions become
     * tombstones (deleted_at) instead of vanishing rows, preserving the
     * audit trail and provenance forever. Ordered before the statement
     * metadata backfill, whose Eloquent queries expect deleted_at.
     */
    private const TABLES = [
        'users', 'companies', 'accounts', 'contacts',
        'tax_rates', 'budgets', 'recurring_expenses', 'custom_ratios',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropSoftDeletes();
                });
            }
        }
    }
};
