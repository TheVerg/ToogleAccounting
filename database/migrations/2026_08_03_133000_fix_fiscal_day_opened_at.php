<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MariaDB implicitly added ON UPDATE to the first TIMESTAMP column.
        DB::statement('UPDATE fiscal_days SET opened_at = created_at WHERE created_at IS NOT NULL');
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE fiscal_days MODIFY opened_at DATETIME NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE fiscal_days MODIFY opened_at TIMESTAMP NOT NULL');
        }
    }
};
