<?php

namespace App\Http\Controllers;

use App\Models\ExchangeRate;
use App\Models\RateFetchLog;
use App\Services\ExchangeRateService;
use App\Services\RateFetchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Daily exchange rates (RBZ publishes the interbank rate). Manually
 * entered; the latest rate on or before a transaction date carries
 * forward, and individual transactions may override it.
 */
class ExchangeRateController extends Controller
{
    public function index(Request $request, ExchangeRateService $fx): Response
    {
        $company = $request->user()->currentCompany;
        $tenantId = $request->user()->tenant_id;

        // The rate in force today per foreign currency (carry-forward),
        // and the latest automated fetch attempt — stored or not.
        $foreign = collect($fx->knownCurrencies($company))
            ->reject(fn ($c) => $c === $company->currency);
        $currentRates = $foreign->map(fn ($currency) => ExchangeRate::where('base_currency', $company->currency)
            ->where('quote_currency', $currency)
            ->whereDate('date', '<=', now()->toDateString())
            ->visibleToTenant($tenantId)
            ->orderByDesc('date')
            ->orderByRaw('CASE WHEN tenant_id IS NULL THEN 0 ELSE 1 END DESC')
            ->first())->filter()->values();
        $latestFetches = $foreign->map(fn ($currency) => RateFetchLog::where('base_currency', $company->currency)
            ->where('quote_currency', $currency)
            ->latest('id')
            ->first())->filter()->values();

        return Inertia::render('Settings/ExchangeRates', [
            'rates' => ExchangeRate::visibleToTenant($tenantId)
                ->orderByDesc('date')
                ->orderBy('base_currency')->orderBy('quote_currency')
                ->paginate(20)->withQueryString(),
            'baseCurrency' => $company->currency,
            'currencies' => $fx->knownCurrencies($company),
            'currentRates' => $currentRates,
            'latestFetches' => $latestFetches,
        ]);
    }

    /** Run the automated fetch on demand instead of waiting for the schedule. */
    public function fetchNow(Request $request, RateFetchService $fetcher): RedirectResponse
    {
        $company = $request->user()->currentCompany;
        $results = $fetcher->fetchDaily($company->currency);

        $summary = collect($results)
            ->map(fn ($r, $currency) => "{$company->currency}/{$currency}: {$r['detail']}")
            ->implode(' · ');

        $allSaved = collect($results)->every(fn ($r) => in_array($r['status'], ['saved', 'skipped'], true));

        return $allSaved
            ? back()->with('success', 'Rates fetched. '.$summary)
            : back()->with('error', 'Fetch needs attention — '.$summary);
    }

    public function store(Request $request): RedirectResponse
    {
        $company = $request->user()->currentCompany;

        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'quote_currency' => ['required', 'string', 'size:3',
                Rule::notIn([$company->currency])],
            'rate' => ['required', 'numeric', 'gt:0'],
        ], [
            'quote_currency.not_in' => 'Enter the rate for a currency other than the base currency.',
        ]);

        // Manual rates belong to this tenant only; the shared auto-fetched
        // rate stays untouched for everyone else.
        ExchangeRate::updateOrCreate(
            [
                'tenant_id' => $request->user()->tenant_id,
                'date' => $data['date'],
                'base_currency' => $company->currency,
                'quote_currency' => strtoupper($data['quote_currency']),
            ],
            [
                'rate' => round((float) $data['rate'], 6),
                'source' => 'manual',
                'created_by' => $request->user()->id,
            ],
        );

        return back()->with('success', 'Exchange rate saved.');
    }

    public function destroy(Request $request, ExchangeRate $exchangeRate): RedirectResponse
    {
        // Only the tenant's own rates can be deleted; shared reference
        // rates are maintained by the automated fetch.
        abort_unless($exchangeRate->tenant_id === $request->user()->tenant_id, 403,
            'Shared reference rates cannot be deleted; enter your own rate to override for your organization.');

        $exchangeRate->delete();

        return back()->with('success', 'Exchange rate deleted.');
    }
}
