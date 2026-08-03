<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ExchangeRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Resolves the exchange rate between a company's base currency and a
 * transaction currency. Rates are entered manually (RBZ publishes the
 * interbank rate daily) and carry forward when no newer rate exists;
 * individual transactions may override the rate (negotiated rates).
 *
 * A rate is stored as units of quote currency per 1 base currency
 * (e.g. base USD, quote ZWG, rate 27 means 27 ZWG per USD), so an
 * amount in the transaction currency divided by the rate gives base.
 */
class ExchangeRateService
{
    /**
     * The rate for converting $currency amounts into the company's base
     * currency on $date (latest rate on or before the date carries
     * forward). Returns 1.0 for the base currency itself.
     */
    public function rateFor(Company $company, ?string $currency, Carbon|string $date): float
    {
        $base = $company->currency;
        if ($currency === null || $currency === $base) {
            return 1.0;
        }

        $date = Carbon::parse($date)->toDateString();
        // A tenant's own manual rate outranks the shared (auto-fetched)
        // rate for the same day.
        $tenantId = Auth::hasUser() ? Auth::user()->tenant_id : null;
        $preferOwn = fn ($q) => $q->orderByDesc('date')
            ->orderByRaw('CASE WHEN tenant_id IS NULL THEN 0 ELSE 1 END DESC');

        $direct = ExchangeRate::where('base_currency', $base)
            ->where('quote_currency', $currency)
            ->whereDate('date', '<=', $date)
            ->visibleToTenant($tenantId)
            ->tap($preferOwn)
            ->first();
        if ($direct) {
            return (float) $direct->rate;
        }

        // A rate entered the other way round still defines the pair.
        $inverse = ExchangeRate::where('base_currency', $currency)
            ->where('quote_currency', $base)
            ->whereDate('date', '<=', $date)
            ->visibleToTenant($tenantId)
            ->tap($preferOwn)
            ->first();
        if ($inverse && (float) $inverse->rate != 0.0) {
            return round(1 / (float) $inverse->rate, 6);
        }

        throw new InvalidArgumentException(
            "No {$base}/{$currency} exchange rate found on or before {$date}. Enter one under Settings → Exchange Rates."
        );
    }

    /**
     * Resolve the rate for a transaction: an explicit override wins,
     * otherwise the stored rate for the date.
     */
    public function resolve(Company $company, ?string $currency, Carbon|string $date, mixed $override = null): float
    {
        if ($override !== null && $override !== '') {
            $rate = round((float) $override, 6);
            if ($rate <= 0) {
                throw new InvalidArgumentException('The exchange rate must be greater than zero.');
            }

            return $rate;
        }

        return $this->rateFor($company, $currency, $date);
    }

    /** Convert a transaction-currency amount to base currency at a rate. */
    public function toBase(float $amount, float $rate): float
    {
        if ($rate <= 0) {
            throw new InvalidArgumentException('The exchange rate must be greater than zero.');
        }

        return round($amount / $rate, 2);
    }

    /** Distinct currencies the system knows about (base + any with rates). */
    public function knownCurrencies(Company $company): array
    {
        $currencies = ExchangeRate::query()
            ->select('base_currency')->distinct()->pluck('base_currency')
            ->merge(ExchangeRate::query()->select('quote_currency')->distinct()->pluck('quote_currency'))
            ->push($company->currency, 'USD', 'ZWG')
            ->unique()->sort()->values()->all();

        return $currencies;
    }
}
