<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomRatio;
use App\Models\Invoice;
use App\Models\RatioSetting;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\ChartOfAccountsService;
use App\Services\ReportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RatiosReportTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->company = Company::create(['name' => 'Hold', 'code' => 'H', 'type' => 'holding']);
        app(ChartOfAccountsService::class)->seedDefaultChart($this->company);

        $this->user = User::factory()->create(['current_company_id' => $this->company->id]);
        setPermissionsTeamId($this->company->id);
        $this->user->assignRole('holding-admin');
        $this->user->companies()->attach($this->company->id);
    }

    public function test_ratios_are_computed_from_the_ledger(): void
    {
        $acc = app(AccountingService::class);
        $chart = app(ChartOfAccountsService::class);
        $bank = $chart->findBySubtype($this->company, 'bank');

        // Owner funds 10,000; invoice 1,000 + 15% tax issued (unpaid); 300 expense.
        $acc->recordEquityTransaction($this->company, [
            'type' => 'contribution', 'date' => '2026-01-01', 'owner_name' => 'Owner',
            'amount' => 10000, 'money_account_id' => $bank->id,
        ], $this->user->id);

        $rate = TaxRate::create(['company_id' => null, 'name' => 'VAT', 'rate' => 15, 'is_active' => true]);
        $client = Contact::create(['company_id' => $this->company->id, 'type' => 'customer', 'name' => 'Client']);
        $invoice = Invoice::create([
            'company_id' => $this->company->id, 'contact_id' => $client->id,
            'invoice_no' => 'INV-90001', 'issue_date' => '2026-02-01', 'status' => 'draft',
            'subtotal' => 1000, 'tax_rate_id' => $rate->id, 'tax_amount' => 150, 'total' => 1150,
        ]);
        $invoice->items()->create([
            'account_id' => $chart->find($this->company, '4000')->id, 'description' => 'Work',
            'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000,
        ]);
        $acc->issueInvoice($invoice->fresh(['items', 'contact', 'company']), $this->user->id);

        $acc->recordExpense($this->company, [
            'date' => '2026-02-15',
            'account_id' => $chart->find($this->company, '5000')->id,
            'paid_from_account_id' => $bank->id,
            'amount' => 300,
        ], $this->user->id);

        $report = app(ReportService::class)->ratios([$this->company->id], '2026-01-01', '2026-12-31');

        $rows = collect($report['sections'])->flatMap(fn ($s) => $s['rows'])->keyBy('label');

        // Current assets 10,850 (bank 9,700 + AR 1,150); current liabilities 150 (tax payable).
        $this->assertSame(round(10850 / 150, 2), $rows['Current Ratio']['value']);
        $this->assertSame(10700.0, $rows['Working Capital']['value']);
        // Net profit 700 on income 1,000.
        $this->assertSame(70.0, $rows['Net Profit Margin']['value']);
        // Equity 10,000 + 700 earnings; liabilities 150.
        $this->assertSame(round(150 / 10700, 2), $rows['Debt-to-Equity']['value']);
    }

    public function test_zero_denominators_yield_null_not_errors(): void
    {
        $report = app(ReportService::class)->ratios([$this->company->id], '2026-01-01', '2026-12-31');

        $rows = collect($report['sections'])->flatMap(fn ($s) => $s['rows'])->keyBy('label');

        $this->assertNull($rows['Current Ratio']['value']);
        $this->assertNull($rows['Net Profit Margin']['value']);
    }

    public function test_ratios_page_renders(): void
    {
        $this->actingAs($this->user)->get('/statements/ratios')->assertOk();
    }

    public function test_configured_benchmarks_override_the_defaults(): void
    {
        RatioSetting::create([
            'company_id' => $this->company->id, 'key' => 'current_ratio',
            'target_min' => 2.0, 'target_max' => null,
        ]);

        $report = app(ReportService::class)
            ->ratios([$this->company->id], '2026-01-01', '2026-12-31', $this->company);

        $row = collect($report['sections'])->flatMap(fn ($s) => $s['rows'])->firstWhere('key', 'current_ratio');

        $this->assertSame('≥ 2', $row['benchmark']);
    }

    public function test_custom_ratios_are_computed_and_shown_in_their_section(): void
    {
        $acc = app(AccountingService::class);
        $chart = app(ChartOfAccountsService::class);
        $bank = $chart->findBySubtype($this->company, 'bank');

        $acc->recordEquityTransaction($this->company, [
            'type' => 'contribution', 'date' => '2026-01-01', 'owner_name' => 'Owner',
            'amount' => 10000, 'money_account_id' => $bank->id,
        ], $this->user->id);

        CustomRatio::create([
            'company_id' => $this->company->id,
            'label' => 'Cash to Assets',
            'section' => 'liquidity',
            'numerator' => 'cash',
            'denominator' => 'total_assets',
            'format' => '%',
            'target_min' => 20,
            'target_max' => null,
        ]);

        $report = app(ReportService::class)
            ->ratios([$this->company->id], '2026-01-01', '2026-12-31', $this->company);

        $liquidity = collect($report['sections'])->firstWhere('title', 'Liquidity');
        $row = collect($liquidity['rows'])->firstWhere('label', 'Cash to Assets');

        $this->assertNotNull($row);
        $this->assertSame(100.0, $row['value']); // all assets are cash
        $this->assertTrue($row['within']);
        $this->assertSame('≥ 20%', $row['benchmark']);
    }

    public function test_custom_ratios_can_be_added_and_removed(): void
    {
        $this->actingAs($this->user)
            ->post('/settings/ratio-benchmarks/custom', [
                'label' => 'Expense Coverage',
                'section' => 'custom',
                'numerator' => 'cash',
                'denominator' => 'expenses',
                'format' => 'x',
                'target_min' => 1,
                'target_max' => null,
            ])
            ->assertSessionHasNoErrors();

        $ratio = CustomRatio::firstOrFail();
        $this->assertSame($this->company->id, $ratio->company_id);

        // Same figure on both sides is rejected.
        $this->actingAs($this->user)
            ->post('/settings/ratio-benchmarks/custom', [
                'label' => 'Nonsense', 'section' => 'custom',
                'numerator' => 'cash', 'denominator' => 'cash', 'format' => 'x',
            ])
            ->assertSessionHasErrors('denominator');

        $this->actingAs($this->user)
            ->delete('/settings/ratio-benchmarks/custom/'.$ratio->id)
            ->assertSessionHasNoErrors();

        $this->assertSame(0, CustomRatio::count());
    }

    public function test_benchmarks_can_be_saved_from_master_data(): void
    {
        $this->actingAs($this->user)->get('/settings/ratio-benchmarks')->assertOk();

        $this->actingAs($this->user)
            ->put('/settings/ratio-benchmarks', [
                'ratios' => [
                    ['key' => 'current_ratio', 'target_min' => 1.2, 'target_max' => 4],
                    ['key' => 'dso', 'target_min' => null, 'target_max' => 30],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ratio_settings', [
            'company_id' => $this->company->id, 'key' => 'dso', 'target_max' => 30,
        ]);

        // Min above max is rejected.
        $this->actingAs($this->user)
            ->put('/settings/ratio-benchmarks', [
                'ratios' => [['key' => 'current_ratio', 'target_min' => 5, 'target_max' => 2]],
            ])
            ->assertSessionHasErrors('ratios');
    }
}
