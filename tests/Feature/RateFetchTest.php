<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\RateFetchLog;
use App\Services\RateFetchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RateFetchTest extends TestCase
{
    use RefreshDatabase;

    protected function fakePrimary(float $zwg): array
    {
        return [
            'result' => 'success',
            'base_code' => 'USD',
            'rates' => ['ZWG' => $zwg, 'EUR' => 0.9],
        ];
    }

    protected function fakeRbz(float $avg): array
    {
        return [
            'success' => true,
            'base' => 'ZWG',
            'rates' => ['USD' => ['bid' => $avg - 0.1, 'ask' => $avg + 0.1, 'avg' => $avg]],
        ];
    }

    public function test_agreeing_sources_store_the_rbz_mid_as_verified(): void
    {
        Http::fake([
            'open.er-api.com/*' => Http::response($this->fakePrimary(26.70)),
            'zwg-rates.vercel.app/*' => Http::response($this->fakeRbz(26.65)),
        ]);

        $results = app(RateFetchService::class)->fetchDaily('USD');

        $this->assertSame('saved', $results['ZWG']['status']);
        $rate = ExchangeRate::where('quote_currency', 'ZWG')->first();
        $this->assertSame(26.65, (float) $rate->rate); // RBZ mid wins
        $this->assertSame('auto (verified)', $rate->source);
    }

    public function test_disagreeing_sources_store_the_rbz_rate_unverified(): void
    {
        Http::fake([
            'open.er-api.com/*' => Http::response($this->fakePrimary(26.70)),
            'zwg-rates.vercel.app/*' => Http::response($this->fakeRbz(30.00)), // ~11% apart
        ]);

        $results = app(RateFetchService::class)->fetchDaily('USD');

        // The RBZ published rate is authoritative: stored, but unverified,
        // and the divergence is on record in the fetch log.
        $this->assertSame('saved', $results['ZWG']['status']);
        $rate = ExchangeRate::where('quote_currency', 'ZWG')->first();
        $this->assertSame(30.0, (float) $rate->rate);
        $this->assertSame('auto (unverified)', $rate->source);

        $log = RateFetchLog::latest('id')->first();
        $this->assertSame(26.70, (float) $log->primary_rate);
        $this->assertSame(30.0, (float) $log->rbz_rate);
        $this->assertStringContainsString('disagrees', $log->detail);
    }

    public function test_scraper_down_falls_back_to_primary_as_unverified(): void
    {
        Http::fake([
            'open.er-api.com/*' => Http::response($this->fakePrimary(26.70)),
            'zwg-rates.vercel.app/*' => Http::response(null, 500),
        ]);

        $results = app(RateFetchService::class)->fetchDaily('USD');

        $this->assertSame('saved', $results['ZWG']['status']);
        $rate = ExchangeRate::where('quote_currency', 'ZWG')->first();
        $this->assertSame(26.70, (float) $rate->rate);
        $this->assertSame('auto (unverified)', $rate->source);
    }

    public function test_manual_rates_are_never_overwritten(): void
    {
        ExchangeRate::create([
            'date' => now()->toDateString(), 'base_currency' => 'USD',
            'quote_currency' => 'ZWG', 'rate' => 27.5, 'source' => 'manual',
        ]);

        Http::fake([
            'open.er-api.com/*' => Http::response($this->fakePrimary(26.70)),
            'zwg-rates.vercel.app/*' => Http::response($this->fakeRbz(26.65)),
        ]);

        $results = app(RateFetchService::class)->fetchDaily('USD');

        $this->assertSame('skipped', $results['ZWG']['status']);
        $this->assertSame(27.5, (float) ExchangeRate::where('quote_currency', 'ZWG')->first()->rate);
    }

    public function test_command_runs_for_each_company_base_currency(): void
    {
        Company::create(['name' => 'Co', 'code' => 'C', 'type' => 'holding', 'currency' => 'USD']);

        Http::fake([
            'open.er-api.com/*' => Http::response($this->fakePrimary(26.70)),
            'zwg-rates.vercel.app/*' => Http::response($this->fakeRbz(26.65)),
        ]);

        $this->artisan('fx:fetch-rates')->assertSuccessful();

        $this->assertSame(1, ExchangeRate::where('base_currency', 'USD')->where('quote_currency', 'ZWG')->count());
    }
}
