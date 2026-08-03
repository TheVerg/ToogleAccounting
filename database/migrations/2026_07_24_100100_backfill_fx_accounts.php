<?php

use App\Models\Company;
use App\Services\ChartOfAccountsService;
use Illuminate\Database\Migrations\Migration;

/**
 * Roll the new FX system accounts (realised/unrealised exchange
 * gains/losses) out to companies whose chart was seeded before
 * multi-currency support existed. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $chart = app(ChartOfAccountsService::class);

        foreach (Company::withTrashed()->get() as $company) {
            $chart->seedDefaultChart($company);
        }
    }

    public function down(): void
    {
        // System accounts stay; removing them could orphan journal lines.
    }
};
