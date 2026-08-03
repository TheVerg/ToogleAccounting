<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('auth:clear-resets')->everyFifteenMinutes();
Schedule::command('queue:prune-failed --hours=168')->daily();

// Daily rate fetch after the aggregator's ~00:00 UTC refresh and the RBZ
// scraper's 08:00 UTC run; carry-forward covers weekends/failures.
Schedule::command('fx:fetch-rates')->weekdays()->at('10:30');
