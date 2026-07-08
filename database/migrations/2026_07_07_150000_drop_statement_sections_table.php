<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Statement presentation now comes from chart-of-accounts metadata
     * (statement_group / statement_line); the code-range layout table
     * is redundant.
     */
    public function up(): void
    {
        Schema::dropIfExists('statement_sections');
    }

    public function down(): void
    {
        // Superseded feature; nothing to restore.
    }
};
