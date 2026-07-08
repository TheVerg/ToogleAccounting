<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FinancialStatement;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\ChartOfAccountsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class StatementRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->company = Company::create(['name' => 'Hold', 'code' => 'H', 'type' => 'holding']);
        $chart = app(ChartOfAccountsService::class);
        $chart->seedDefaultChart($this->company);

        $this->user = User::factory()->create(['current_company_id' => $this->company->id]);
        setPermissionsTeamId($this->company->id);
        $this->user->assignRole('holding-admin');
        $this->user->companies()->attach($this->company->id);

        app(AccountingService::class)->recordEquityTransaction($this->company, [
            'type' => 'contribution', 'date' => '2026-01-01', 'owner_name' => 'Owner',
            'amount' => 5000, 'money_account_id' => $chart->findBySubtype($this->company, 'bank')->id,
        ], $this->user->id);
    }

    protected function generate(): FinancialStatement
    {
        $this->actingAs($this->user)->post('/statements/register', [
            'type' => 'balance-sheet', 'scope' => 'current',
            'from' => '2026-01-01', 'to' => '2026-06-30',
        ])->assertSessionHasNoErrors();

        return FinancialStatement::latest('id')->firstOrFail();
    }

    public function test_generating_saves_a_frozen_snapshot(): void
    {
        $statement = $this->generate();

        $this->assertSame('draft', $statement->status);
        $this->assertNotEmpty($statement->lines);
        $this->assertSame(['Amount'], $statement->columns);
        // Snapshot contains the drilled hierarchy from the chart metadata.
        $types = collect($statement->lines)->pluck('line_type')->unique();
        $this->assertTrue($types->contains('section_header'));
        $this->assertTrue($types->contains('total'));
    }

    public function test_approval_locks_and_duplicates_are_blocked(): void
    {
        $statement = $this->generate();

        $this->actingAs($this->user)
            ->post('/statements/register/'.$statement->id.'/approve')
            ->assertSessionHas('success');
        $this->assertSame('approved', $statement->fresh()->status);
        $this->assertNotNull($statement->fresh()->approved_at);

        // Second statement for the same type/period cannot also be approved.
        $second = $this->generate();
        $this->actingAs($this->user)
            ->post('/statements/register/'.$second->id.'/approve')
            ->assertSessionHas('error');
        $this->assertSame('draft', $second->fresh()->status);
    }

    public function test_approved_statements_need_a_forced_reasoned_soft_delete(): void
    {
        $statement = $this->generate();
        $this->actingAs($this->user)->post('/statements/register/'.$statement->id.'/approve');

        // Plain delete is rejected (force + reason required).
        $this->actingAs($this->user)
            ->delete('/statements/register/'.$statement->id)
            ->assertSessionHasErrors();

        $this->actingAs($this->user)
            ->delete('/statements/register/'.$statement->id, ['force' => true, 'reason' => 'Wrong period'])
            ->assertSessionHas('success');

        $this->assertSoftDeleted('financial_statements', ['id' => $statement->id]);
        $this->assertSame($this->user->id, FinancialStatement::withTrashed()->find($statement->id)->deleted_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'statement_force_deleted']);
    }

    public function test_statement_type_pages_serve_registered_snapshots(): void
    {
        // Empty register: the page still renders, with no statement selected.
        $this->actingAs($this->user)->get('/statements/balance-sheet')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/StatementView')
                ->where('statement', null));

        $statement = $this->generate();

        $this->actingAs($this->user)->get('/statements/balance-sheet')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/StatementView')
                ->where('statement.id', $statement->id)
                ->where('typeLabel', 'Balance Sheet'));

        // Generating from the type page returns to it with the new snapshot selected.
        $this->actingAs($this->user)->post('/statements/register', [
            'type' => 'balance-sheet', 'scope' => 'current', 'to' => '2026-07-31', 'stay' => true,
        ])->assertRedirectContains('/statements/balance-sheet?statement=');
    }

    public function test_snapshot_exports_come_from_the_frozen_lines(): void
    {
        $statement = $this->generate();

        $csv = $this->actingAs($this->user)
            ->get('/statements/register/'.$statement->id.'/export?format=csv')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString('TOTAL ASSETS', $csv->getContent());
    }

    public function test_draft_statements_soft_delete_without_force(): void
    {
        $statement = $this->generate();

        $this->actingAs($this->user)
            ->delete('/statements/register/'.$statement->id)
            ->assertSessionHas('success');

        $this->assertSoftDeleted('financial_statements', ['id' => $statement->id]);
    }
}
