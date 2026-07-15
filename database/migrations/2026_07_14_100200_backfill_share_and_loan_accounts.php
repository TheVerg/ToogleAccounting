<?php

use App\Models\Company;
use App\Services\ChartOfAccountsService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Add the new template accounts (2350 Shareholder Loans, 3300 Share
     * Capital, 3400 Share Premium) to companies seeded before them.
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
        // New optional accounts; nothing to revert.
    }
};
