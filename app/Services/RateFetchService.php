<?php

namespace App\Services;

use App\Models\ExchangeRate;
use App\Models\RateFetchLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Daily exchange-rate auto-fetch with cross-checking.
 *
 * Primary source: ExchangeRate-API's open endpoint — a stable commercial
 * aggregator that relays the officially published central-bank rate
 * (possibly a day behind). Cross-check: an RBZ scraper serving the exact
 * bid/ask/mid from the RBZ published table, but unofficial and free, so
 * it can break silently.
 *
 * Decision rule per currency:
 *  - Both sources agree within tolerance -> store the RBZ mid (the
 *    authoritative official figure), marked 'auto (verified)'.
 *  - Sources disagree beyond tolerance -> the RBZ published rate is the
 *    one ZIMRA cares about, so store the RBZ mid anyway, but marked
 *    'auto (unverified)' with the divergence logged for review.
 *  - Cross-check unavailable -> store the primary rate, 'auto (unverified)'.
 *  - A manually entered rate for the date is never overwritten.
 *
 * Fetched rates are a pre-fill convenience: the Settings page shows the
 * source, manual entry always wins, and per-transaction overrides remain.
 */
class RateFetchService
{
    /**
     * Fetch and store today's (or a given date's) rates for one base
     * currency. Returns a per-currency result map:
     * ['ZWG' => ['status' => saved|skipped|held|failed, 'detail' => ...]]
     */
    public function fetchDaily(string $baseCurrency, ?Carbon $date = null): array
    {
        $date = ($date ?? now())->toDateString();
        $results = [];

        $primaryRates = $this->fetchPrimary($baseCurrency);
        $rbzRates = $baseCurrency === 'USD' ? $this->fetchRbz() : null;

        foreach (config('services.exchange_rates.currencies', ['ZWG']) as $currency) {
            if ($currency === $baseCurrency) {
                continue;
            }

            // Auto-fetch maintains the shared reference row (tenant_id
            // null); tenants' own manual rates live alongside and take
            // precedence for them at lookup time.
            $existing = ExchangeRate::whereNull('tenant_id')
                ->whereDate('date', $date)
                ->where('base_currency', $baseCurrency)
                ->where('quote_currency', $currency)
                ->first();
            $primary = $primaryRates[$currency] ?? null;
            $rbz = $rbzRates[$currency] ?? null;

            // Every attempt is logged, stored or not, so what each source
            // said on each day stays on record.
            $log = fn (string $status, string $detail, ?float $stored = null) => RateFetchLog::create([
                'date' => $date, 'base_currency' => $baseCurrency, 'quote_currency' => $currency,
                'primary_rate' => $primary, 'rbz_rate' => $rbz,
                'stored_rate' => $stored, 'status' => $status, 'detail' => $detail,
            ]);

            if ($existing && $existing->source === 'manual') {
                $detail = 'manual rate already entered';
                $log('skipped', $detail);
                $results[$currency] = ['status' => 'skipped', 'detail' => $detail];

                continue;
            }

            if ($primary === null && $rbz === null) {
                $detail = 'both sources unavailable';
                $log('failed', $detail);
                $results[$currency] = ['status' => 'failed', 'detail' => $detail];

                continue;
            }

            if ($primary !== null && $rbz !== null) {
                $deviation = abs($primary - $rbz) / $rbz;
                $tolerance = (float) config('services.exchange_rates.tolerance', 0.02);

                if ($deviation > $tolerance) {
                    // The RBZ published rate is what ZIMRA expects; store
                    // it, but unverified — the divergence stays on record.
                    Log::warning('Exchange rate sources disagree; storing the RBZ rate unverified.', [
                        'pair' => "{$baseCurrency}/{$currency}", 'date' => $date,
                        'primary' => $primary, 'rbz' => $rbz, 'deviation' => round($deviation, 4),
                    ]);
                    $this->store($date, $baseCurrency, $currency, $rbz, 'auto (unverified)', $existing);
                    $detail = sprintf('RBZ mid %.4f stored; aggregator disagrees (%.4f, %.1f%% apart)', $rbz, $primary, $deviation * 100);
                    $log('saved', $detail, $rbz);
                    $results[$currency] = ['status' => 'saved', 'detail' => $detail];

                    continue;
                }

                $this->store($date, $baseCurrency, $currency, $rbz, 'auto (verified)', $existing);
                $detail = sprintf('RBZ mid %.4f, confirmed by aggregator', $rbz);
                $log('saved', $detail, $rbz);
                $results[$currency] = ['status' => 'saved', 'detail' => $detail];

                continue;
            }

            // One source only: save it, flagged so the UI shows it was
            // not cross-checked.
            $rate = $primary ?? $rbz;
            $label = $primary !== null ? 'aggregator only' : 'RBZ scraper only';
            $this->store($date, $baseCurrency, $currency, $rate, 'auto (unverified)', $existing);
            $detail = sprintf('%.4f (%s, not cross-checked)', $rate, $label);
            $log('saved', $detail, $rate);
            $results[$currency] = ['status' => 'saved', 'detail' => $detail];
        }

        return $results;
    }

    /**
     * ExchangeRate-API open endpoint: rates are quote-per-base for the
     * requested base, matching how this app stores rates.
     */
    protected function fetchPrimary(string $baseCurrency): array
    {
        try {
            $response = Http::timeout(15)->retry(2, 1000)
                ->get(config('services.exchange_rates.primary_url').'/'.$baseCurrency);

            if (! $response->successful() || $response->json('result') !== 'success') {
                return [];
            }

            return collect($response->json('rates', []))
                ->map(fn ($rate) => round((float) $rate, 6))
                ->all();
        } catch (\Throwable $e) {
            Log::info('Primary exchange-rate source unavailable: '.$e->getMessage());

            return [];
        }
    }

    /**
     * RBZ scraper: despite reporting base ZWG, the per-currency figures
     * are ZWG per unit (e.g. rates.USD.avg = ZWG per USD), which for a
     * USD-base company is already quote-per-base. Returns ['ZWG' => mid].
     */
    protected function fetchRbz(): array
    {
        try {
            $response = Http::timeout(15)->retry(2, 1000)
                ->get(config('services.exchange_rates.rbz_url'));

            if (! $response->successful() || $response->json('success') !== true) {
                return [];
            }

            $usd = $response->json('rates.USD');
            $mid = $usd['avg'] ?? $usd['mid'] ?? null;
            if ($mid === null && isset($usd['bid'], $usd['ask'])) {
                $mid = ((float) $usd['bid'] + (float) $usd['ask']) / 2;
            }

            return $mid !== null ? ['ZWG' => round((float) $mid, 6)] : [];
        } catch (\Throwable $e) {
            Log::info('RBZ scraper unavailable: '.$e->getMessage());

            return [];
        }
    }

    protected function store(string $date, string $base, string $quote, float $rate, string $source, ?ExchangeRate $existing): void
    {
        if ($existing) {
            $existing->update(['rate' => $rate, 'source' => $source]);

            return;
        }

        ExchangeRate::create([
            'date' => $date,
            'base_currency' => $base,
            'quote_currency' => $quote,
            'rate' => $rate,
            'source' => $source,
        ]);
    }
}
