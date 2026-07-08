<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\ChartOfAccountsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataDeletionTest extends TestCase
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

    public function test_unused_tax_rate_can_be_deleted_but_used_one_cannot(): void
    {
        $unused = TaxRate::create(['company_id' => $this->company->id, 'name' => 'Unused', 'rate' => 5, 'is_active' => true]);
        $this->actingAs($this->user)->delete('/taxes/rates/'.$unused->id)->assertSessionHas('success');
        $this->assertSoftDeleted('tax_rates', ['id' => $unused->id]);

        $used = TaxRate::create(['company_id' => $this->company->id, 'name' => 'Used', 'rate' => 15, 'is_active' => true]);
        $chart = app(ChartOfAccountsService::class);
        Expense::create([
            'company_id' => $this->company->id, 'expense_no' => 'EXP-90001', 'date' => '2026-01-01',
            'account_id' => $chart->find($this->company, '5000')->id,
            'paid_from_account_id' => $chart->findBySubtype($this->company, 'bank')->id,
            'amount' => 100, 'tax_rate_id' => $used->id, 'tax_amount' => 15, 'total' => 115,
        ]);

        $this->actingAs($this->user)->delete('/taxes/rates/'.$used->id)->assertSessionHas('error');
        $this->assertDatabaseHas('tax_rates', ['id' => $used->id]);
    }

    public function test_contact_with_transactions_cannot_be_deleted(): void
    {
        $clean = Contact::create(['company_id' => $this->company->id, 'type' => 'customer', 'name' => 'Clean']);
        $this->actingAs($this->user)->delete('/contacts/'.$clean->id)->assertSessionHas('success');

        $used = Contact::create(['company_id' => $this->company->id, 'type' => 'vendor', 'name' => 'Used']);
        $chart = app(ChartOfAccountsService::class);
        Expense::create([
            'company_id' => $this->company->id, 'expense_no' => 'EXP-90002', 'date' => '2026-01-01',
            'account_id' => $chart->find($this->company, '5000')->id,
            'paid_from_account_id' => $chart->findBySubtype($this->company, 'bank')->id,
            'amount' => 100, 'total' => 100, 'contact_id' => $used->id,
        ]);

        $this->actingAs($this->user)->delete('/contacts/'.$used->id)->assertSessionHas('error');
        $this->assertDatabaseHas('contacts', ['id' => $used->id]);
    }

    public function test_cross_company_deletion_is_forbidden(): void
    {
        $other = Company::create(['name' => 'Other', 'code' => 'O', 'type' => 'subsidiary', 'parent_id' => $this->company->id]);
        $contact = Contact::create(['company_id' => $other->id, 'type' => 'customer', 'name' => 'Foreign']);

        $this->actingAs($this->user)->delete('/contacts/'.$contact->id)->assertForbidden();
    }
}
