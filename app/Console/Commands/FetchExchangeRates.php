<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\RateFetchService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class FetchExchangeRates extends Command
{
    protected $signature = 'fx:fetch-rates {--date= : Store under this date instead of today (Y-m-d)}';

    protected $description = 'Fetch the daily exchange rates (aggregator primary, cross-checked against the RBZ published rate)';

    public function handle(RateFetchService $fetcher): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : now();

        $baseCurrencies = Company::query()->distinct()->pluck('currency')->filter()->all() ?: ['USD'];

        $failed = false;
        foreach ($baseCurrencies as $base) {
            $results = $fetcher->fetchDaily($base, $date);

            foreach ($results as $currency => $result) {
                $line = "{$base}/{$currency}: {$result['status']} — {$result['detail']}";
                match ($result['status']) {
                    'saved' => $this->info($line),
                    'skipped' => $this->line($line),
                    default => $this->warn($line),
                };
                if ($result['status'] === 'failed') {
                    $failed = true;
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
