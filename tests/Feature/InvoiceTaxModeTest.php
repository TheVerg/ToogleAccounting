<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\TaxGroup;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\ChartOfAccountsService;
use App\Services\InvoiceTaxCalculator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTaxModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculator_supports_exclusive_inclusive_and_compound_taxes(): void
    {
        $vat = new TaxRate(['name' => 'VAT', 'rate' => 15.5, 'is_fiscal_tax' => true]);
        $vat->id = 1;
        $levy = new TaxRate(['name' => 'Levy', 'rate' => 2, 'is_fiscal_tax' => false]);
        $levy->id = 2;
        $calculator = app(InvoiceTaxCalculator::class);

        $exclusive = $calculator->calculate(100, 'exclusive', collect([$vat, $levy]));
        $inclusive = $calculator->calculate(100, 'inclusive', collect([$vat, $levy]));
        $compoundLevy = new TaxRate(['name' => 'Compound levy', 'rate' => 2, 'is_compound' => true, 'is_fiscal_tax' => false]);
        $compoundLevy->id = 3;
        $compound = $calculator->calculate(100, 'exclusive', collect([$vat, $compoundLevy]));

        $this->assertSame(['net' => 100.0, 'tax' => 17.5, 'gross' => 117.5], collect($exclusive)->except('components')->all());
        $this->assertSame(['net' => 85.11, 'tax' => 14.89, 'gross' => 100.0], collect($inclusive)->except('components')->all());
        $this->assertSame(['net' => 100.0, 'tax' => 17.81, 'gross' => 117.81], collect($compound)->except('components')->all());
    }

    public function test_invoice_stores_line_tax_snapshots_from_reusable_group(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Tax Co', 'code' => 'TAX', 'type' => 'holding', 'currency' => 'USD']);
        app(ChartOfAccountsService::class)->seedDefaultChart($company);
        $user = User::factory()->create(['current_company_id' => $company->id]);
        $user->companies()->attach($company);
        setPermissionsTeamId($company->id);
        $user->assignRole('system-admin');
        $customer = Contact::create(['company_id' => $company->id, 'type' => 'customer', 'name' => 'Buyer']);
        $income = $company->accounts()->where('type', 'income')->firstOrFail();
        $vat = TaxRate::create(['company_id' => $company->id, 'name' => 'VAT', 'rate' => 15.5, 'is_active' => true, 'is_fiscal_tax' => true, 'zimra_tax_id' => 517]);
        $levy = TaxRate::create(['company_id' => $company->id, 'name' => 'Levy', 'rate' => 2, 'is_active' => true, 'is_fiscal_tax' => false]);
        $group = TaxGroup::create(['company_id' => $company->id, 'name' => 'VAT + Levy', 'is_default' => true, 'is_active' => true]);
        $group->rates()->attach([
            $vat->id => ['calculation_order' => 1, 'is_compound' => false],
            $levy->id => ['calculation_order' => 2, 'is_compound' => false],
        ]);

        $this->actingAs($user)->post('/income/invoices', [
            'contact_id' => $customer->id, 'issue_date' => '2026-08-03', 'currency' => 'USD',
            'tax_mode' => 'inclusive', 'tax_group_id' => $group->id, 'issue' => false,
            'items' => [['account_id' => $income->id, 'description' => 'Inclusive service', 'quantity' => 1, 'unit_price' => 100]],
        ])->assertSessionHasNoErrors();

        $invoice = Invoice::with('items.taxes')->firstOrFail();
        $this->assertSame('inclusive', $invoice->tax_mode);
        $this->assertSame(85.11, (float) $invoice->subtotal);
        $this->assertSame(14.89, (float) $invoice->tax_amount);
        $this->assertSame(100.0, (float) $invoice->total);
        $this->assertCount(2, $invoice->items->first()->taxes);
        $this->assertSame(['VAT', 'Levy'], $invoice->items->first()->taxes->pluck('tax_name')->all());
    }
}
