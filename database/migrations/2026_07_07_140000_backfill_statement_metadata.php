<?php

use App\Models\Company;
use App\Services\ChartOfAccountsService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Stamp the standard statement groups/lines from the chart template
     * onto existing companies' accounts (only where not already set).
     */
    public function up(): void
    {
        $chart = app(ChartOfAccountsService::class);

        foreach (Company::all() as $company) {
            $chart->seedDefaultChart($company);
        }
    }

    public function down(): void
    {
        // Presentation metadata; nothing to revert.
    }
};
