<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Expense;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\ChartOfAccountsService;
use App\Services\JournalService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AccountingGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Company $other;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $chart = app(ChartOfAccountsService::class);
        $this->company = Company::create(['name' => 'Hold', 'code' => 'H', 'type' => 'holding']);
        $this->other = Company::create(['name' => 'Other', 'code' => 'O', 'type' => 'subsidiary', 'parent_id' => $this->company->id]);
        $chart->seedDefaultChart($this->company);
        $chart->seedDefaultChart($this->other);

        $this->user = User::factory()->create(['current_company_id' => $this->company->id]);
        setPermissionsTeamId($this->company->id);
        $this->user->assignRole('holding-admin');
        $this->user->companies()->attach($this->company->id);
    }

    protected function postExpense(float $amount = 100): Expense
    {
        $chart = app(ChartOfAccountsService::class);

        return app(AccountingService::class)->recordExpense($this->company, [
            'date' => '2026-03-01',
            'account_id' => $chart->find($this->company, '5000')->id,
            'paid_from_account_id' => $chart->findBySubtype($this->company, 'bank')->id,
            'amount' => $amount,
        ], $this->user->id);
    }

    public function test_posted_journal_entries_cannot_be_updated(): void
    {
        $entry = $this->postExpense()->journalEntry;

        $this->expectException(LogicException::class);
        $entry->update(['description' => 'tampered']);
    }

    public function test_posted_journal_entries_cannot_be_deleted(): void
    {
        $entry = $this->postExpense()->journalEntry;

        $this->expectException(LogicException::class);
        $entry->delete();
    }

    public function test_posted_journal_lines_cannot_be_updated(): void
    {
        $line = $this->postExpense()->journalEntry->lines()->first();

        $this->expectException(LogicException::class);
        $line->update(['debit' => 999999]);
    }

    public function test_reversal_is_still_allowed_and_links_the_original(): void
    {
        $entry = $this->postExpense()->journalEntry;

        $reversal = app(JournalService::class)->reverse($entry, '2026-03-02', $this->user->id);

        $this->assertSame($entry->id, $reversal->reversal_of_id);
        $this->assertSame('reversed', $entry->fresh()->status);
    }

    public function test_tax_rates_of_other_companies_cannot_be_updated(): void
    {
        $rate = TaxRate::create([
            'company_id' => $this->other->id, 'name' => 'Other VAT', 'rate' => 15, 'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->put('/taxes/rates/'.$rate->id, ['name' => 'Hijacked', 'rate' => 0])
            ->assertForbidden();

        $this->assertSame('Other VAT', $rate->fresh()->name);
    }

    public function test_global_tax_rates_require_system_admin(): void
    {
        $global = TaxRate::create(['company_id' => null, 'name' => 'Global VAT', 'rate' => 15, 'is_active' => true]);

        $this->actingAs($this->user)
            ->put('/taxes/rates/'.$global->id, ['name' => 'Hijacked', 'rate' => 0])
            ->assertForbidden();

        $this->actingAs($this->user)
            ->post('/taxes/rates', ['name' => 'New Global', 'rate' => 10, 'global' => true])
            ->assertForbidden();

        $this->user->assignRole('system-admin');
        $this->actingAs($this->user)
            ->post('/taxes/rates', ['name' => 'New Global', 'rate' => 10, 'global' => true])
            ->assertSessionHasNoErrors();
    }

    public function test_expenses_must_be_paid_from_a_money_account(): void
    {
        $chart = app(ChartOfAccountsService::class);
        $rent = $chart->find($this->company, '5000');

        $this->actingAs($this->user)
            ->post('/expenses', [
                'date' => '2026-03-01',
                'account_id' => $rent->id,
                'paid_from_account_id' => $rent->id,
                'amount' => 100,
            ])
            ->assertSessionHasErrors('paid_from_account_id');
    }

    public function test_transacted_accounts_cannot_change_type(): void
    {
        $this->postExpense();
        $rent = app(ChartOfAccountsService::class)->find($this->company, '5000');

        $this->actingAs($this->user)
            ->put('/accounts/'.$rent->id, [
                'code' => $rent->code, 'name' => $rent->name,
                'type' => 'income', 'subtype' => $rent->subtype,
            ])
            ->assertSessionHasErrors('type');

        $this->assertSame('expense', $rent->fresh()->type);
    }

    public function test_document_numbers_are_sequential_per_company(): void
    {
        $first = $this->postExpense(100);
        $second = $this->postExpense(200);

        $this->assertSame('EXP-00001', $first->expense_no);
        $this->assertSame('EXP-00002', $second->expense_no);
        $this->assertSame('JE-00001', $first->journalEntry->entry_no);
        $this->assertSame('JE-00002', $second->journalEntry->entry_no);
    }
}
