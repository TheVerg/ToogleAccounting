<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ComplianceReturn;
use App\Models\Invoice;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\ChartOfAccountsService;
use App\Services\Compliance\ComplianceWorkspaceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplianceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected User $maker;
    protected User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->company = Company::create(['name' => 'Compliance Co', 'code' => 'CC', 'type' => 'holding', 'currency' => 'USD']);
        app(ChartOfAccountsService::class)->seedDefaultChart($this->company);
        $this->maker = $this->user('holding-admin');
        $this->checker = $this->user('holding-admin');
    }

    protected function user(string $role): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company);
        setPermissionsTeamId($this->company->id);
        $user->assignRole($role);
        return $user;
    }

    protected function invoice(string $number, float $subtotal, ?TaxRate $rate, float $tax): Invoice
    {
        $contact = \App\Models\Contact::firstOrCreate(['company_id' => $this->company->id, 'name' => 'Buyer'], ['type' => 'customer']);
        return Invoice::create([
            'company_id' => $this->company->id, 'contact_id' => $contact->id, 'invoice_no' => $number,
            'issue_date' => '2026-07-15', 'status' => 'sent', 'subtotal' => $subtotal,
            'tax_rate_id' => $rate?->id, 'tax_amount' => $tax, 'total' => $subtotal + $tax,
            'currency' => 'USD', 'base_subtotal' => $subtotal, 'base_tax_amount' => $tax, 'base_total' => $subtotal + $tax,
        ]);
    }

    public function test_vat7_separates_standard_zero_rated_and_exempt_sales(): void
    {
        $standard = TaxRate::create(['company_id' => $this->company->id, 'name' => 'VAT 15.5%', 'rate' => 15.5, 'is_active' => true, 'zimra_tax_id' => 517]);
        $zero = TaxRate::create(['company_id' => $this->company->id, 'name' => 'Zero', 'rate' => 0, 'is_active' => true, 'zimra_tax_id' => 2]);
        $this->invoice('STD', 100, $standard, 15.5);
        $this->invoice('ZERO', 40, $zero, 0);
        $this->invoice('EXEMPT', 25, null, 0);

        $workspace = app(ComplianceWorkspaceService::class)->build($this->company, '2026-07-01', '2026-07-31');
        $usd = collect($workspace['schedules'])->firstWhere('currency', 'USD');

        $this->assertSame(100.0, $usd['standard_sales']);
        $this->assertSame(40.0, $usd['zero_rated_sales']);
        $this->assertSame(25.0, $usd['exempt_sales']);
        $this->assertSame(15.5, $usd['output_vat']);
    }

    public function test_voided_invoice_is_excluded_from_vat_sales_and_reconciliation_base(): void
    {
        $zero = TaxRate::create(['company_id' => $this->company->id, 'name' => 'Zero', 'rate' => 0, 'is_active' => true, 'zimra_tax_id' => 2]);
        $invoice = $this->invoice('VOID-1', 30, $zero, 0);
        $invoice->update(['status' => 'void']);

        $workspace = app(ComplianceWorkspaceService::class)->build($this->company, '2026-07-01', '2026-07-31');
        $usd = collect($workspace['schedules'])->firstWhere('currency', 'USD');

        $this->assertSame(0.0, $usd['zero_rated_sales']);
        $this->assertSame(0.0, $workspace['reconciliation']['invoice_sales_base']);
        $this->assertSame(0, $workspace['reconciliation']['invoice_count']);
    }

    public function test_prepare_blocker_returns_to_workspace_with_actionable_message(): void
    {
        $this->invoice('BLOCKED-1', 100, null, 0);
        $return = ComplianceReturn::create([
            'company_id' => $this->company->id, 'return_type' => 'VAT7',
            'period_from' => '2026-07-01', 'period_to' => '2026-07-31', 'due_date' => '2026-08-25',
        ]);

        $response = $this->actingAs($this->maker)
            ->from('/compliance?return_id='.$return->id)
            ->post("/compliance/returns/{$return->id}/transition", ['action' => 'prepare']);

        $response->assertRedirect('/compliance?return_id='.$return->id)
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'LEDGER'));
        $this->assertSame('draft', $return->fresh()->status);
    }

    public function test_return_workflow_enforces_maker_checker_and_locks_approved_period(): void
    {
        $return = ComplianceReturn::create([
            'company_id' => $this->company->id, 'return_type' => 'VAT7',
            'period_from' => '2026-07-01', 'period_to' => '2026-07-31', 'due_date' => '2026-08-25',
        ]);

        $this->actingAs($this->maker)->post("/compliance/returns/{$return->id}/transition", ['action' => 'prepare'])->assertSessionHasNoErrors();
        $this->assertSame('prepared', $return->fresh()->status);

        $this->actingAs($this->maker)->post("/compliance/returns/{$return->id}/transition", ['action' => 'review'])
            ->assertRedirect()->assertSessionHas('error');
        $this->actingAs($this->checker)->post("/compliance/returns/{$return->id}/transition", ['action' => 'review'])->assertSessionHasNoErrors();
        $this->actingAs($this->checker)->post("/compliance/returns/{$return->id}/transition", ['action' => 'approve'])->assertSessionHasNoErrors();

        $this->assertSame('approved', $return->fresh()->status);
        $this->assertSame('2026-08-01', $this->company->fresh()->locked_before->toDateString());
        $this->assertSame(['prepare', 'review', 'approve'], $return->events()->pluck('action')->all());
    }

    public function test_accountant_can_prepare_but_cannot_review(): void
    {
        $accountant = $this->user('accountant');
        $return = ComplianceReturn::create([
            'company_id' => $this->company->id, 'return_type' => 'VAT7',
            'period_from' => '2026-07-01', 'period_to' => '2026-07-31', 'due_date' => '2026-08-25',
        ]);

        $this->actingAs($accountant)->post("/compliance/returns/{$return->id}/transition", ['action' => 'prepare'])->assertSessionHasNoErrors();
        $this->actingAs($accountant)->post("/compliance/returns/{$return->id}/transition", ['action' => 'review'])->assertForbidden();
    }

    public function test_return_creation_also_creates_calendar_deadline(): void
    {
        $this->actingAs($this->maker)->post('/compliance/returns', [
            'return_type' => 'VAT7', 'period_from' => '2026-07-01',
            'period_to' => '2026-07-31', 'due_date' => '2026-08-25',
        ])->assertRedirect();

        $this->assertDatabaseHas('compliance_deadlines', [
            'company_id' => $this->company->id, 'return_type' => 'VAT7',
        ]);
        $this->assertSame('2026-08-25', \App\Models\ComplianceDeadline::firstOrFail()->due_date->toDateString());
    }

    public function test_duplicate_return_opens_existing_return_with_flash_error(): void
    {
        $return = ComplianceReturn::create([
            'company_id' => $this->company->id, 'return_type' => 'VAT7',
            'period_from' => '2026-07-01', 'period_to' => '2026-07-31', 'due_date' => '2026-08-25',
        ]);

        $this->actingAs($this->maker)->post('/compliance/returns', [
            'return_type' => 'VAT7', 'period_from' => '2026-07-01',
            'period_to' => '2026-07-31', 'due_date' => '2026-08-26',
        ])->assertRedirect('/compliance?return_id='.$return->id)
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'already exists'));

        $this->assertSame(1, ComplianceReturn::count());
    }

    public function test_filed_return_can_create_a_traceable_amendment(): void
    {
        $original = ComplianceReturn::create([
            'company_id' => $this->company->id, 'return_type' => 'VAT7', 'status' => 'filed',
            'period_from' => '2026-07-01', 'period_to' => '2026-07-31', 'due_date' => '2026-08-25',
        ]);

        $this->actingAs($this->maker)->post("/compliance/returns/{$original->id}/amend")->assertRedirect();
        $amendment = ComplianceReturn::where('amends_return_id', $original->id)->firstOrFail();
        $this->assertSame(1, $amendment->amendment_no);
        $this->assertSame('draft', $amendment->status);
        $this->assertSame('amendment_created', $amendment->events()->firstOrFail()->action);
    }

    public function test_client_tasks_are_scoped_to_the_current_company(): void
    {
        $this->actingAs($this->maker)->post('/compliance/tasks', [
            'type' => 'missing_document', 'title' => 'Upload supplier fiscal invoice',
            'due_date' => '2026-08-20',
        ])->assertSessionHasNoErrors();
        $task = \App\Models\ComplianceTask::firstOrFail();
        $this->assertSame($this->company->id, $task->company_id);

        $this->actingAs($this->maker)->post("/compliance/tasks/{$task->id}/complete")->assertSessionHasNoErrors();
        $this->assertSame('completed', $task->fresh()->status);
    }

    public function test_compliance_workspace_and_accountant_queue_render(): void
    {
        $this->actingAs($this->maker)->get('/compliance')->assertOk()->assertInertia(fn ($page) => $page->component('Compliance/Index'));
        $this->actingAs($this->maker)->get('/compliance/accountant')->assertOk()->assertInertia(fn ($page) => $page->component('Compliance/Accountant'));
    }

    public function test_prepared_return_exports_a_zip_evidence_pack(): void
    {
        $snapshot = app(ComplianceWorkspaceService::class)->build($this->company, '2026-07-01', '2026-07-31');
        $return = ComplianceReturn::create([
            'company_id' => $this->company->id, 'return_type' => 'VAT7', 'status' => 'prepared',
            'period_from' => '2026-07-01', 'period_to' => '2026-07-31', 'due_date' => '2026-08-25',
            'snapshot' => $snapshot,
        ]);

        $this->actingAs($this->maker)->get("/compliance/returns/{$return->id}/evidence-pack")
            ->assertOk()->assertDownload('VAT7-2026-07-evidence.zip');
    }
}
