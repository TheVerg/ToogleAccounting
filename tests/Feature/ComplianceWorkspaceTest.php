<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ComplianceDeadline;
use App\Models\ComplianceReturn;
use App\Models\Invoice;
use App\Models\PayrollStatutoryRate;
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

    /**
     * A QPD estimates the annual liability, so the period is the year of
     * assessment and instalment 1 falls due 25 March — eight months before
     * period_to. The blanket `due_date >= period_to` rule made that impossible.
     */
    public function test_a_qpd_due_date_may_fall_inside_the_period_it_estimates(): void
    {
        $payload = ['return_type' => 'QPD', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31'];

        $this->actingAs($this->maker)->post('/compliance/returns', $payload + ['instalment' => 1, 'due_date' => '2026-03-25'])
            ->assertSessionHasNoErrors();

        // All four instalments coexist against the one year of assessment.
        foreach ([2 => '2026-06-25', 3 => '2026-09-25', 4 => '2026-12-20'] as $no => $due) {
            $this->actingAs($this->maker)->post('/compliance/returns', $payload + ['instalment' => $no, 'due_date' => $due])
                ->assertSessionHasNoErrors();
        }
        $this->assertSame(4, ComplianceReturn::where('return_type', 'QPD')->count());

        // Re-filing the same instalment still opens the existing one.
        $this->actingAs($this->maker)->post('/compliance/returns', $payload + ['instalment' => 1, 'due_date' => '2026-03-25'])
            ->assertSessionHas('error');
        $this->assertSame(4, ComplianceReturn::where('return_type', 'QPD')->count());

        // VAT7 keeps the after-period rule.
        $this->actingAs($this->maker)->post('/compliance/returns', [
            'return_type' => 'VAT7', 'period_from' => '2026-07-01', 'period_to' => '2026-07-31', 'due_date' => '2026-07-15',
        ])->assertSessionHasErrors('due_date');
    }

    public function test_qpd_refuses_to_estimate_without_an_approved_corporate_tax_rate(): void
    {
        $return = ComplianceReturn::create([
            'company_id' => $this->company->id, 'return_type' => 'QPD', 'status' => 'draft', 'instalment' => 1,
            'period_from' => '2026-01-01', 'period_to' => '2026-12-31', 'due_date' => '2026-03-25',
        ]);

        $this->actingAs($this->maker)->post("/compliance/returns/{$return->id}/transition", ['action' => 'prepare'])
            ->assertSessionHas('error');
        $this->assertSame('draft', $return->fresh()->status);
    }

    public function test_qpd_estimates_the_instalment_from_profit_and_the_approved_rate(): void
    {
        PayrollStatutoryRate::create([
            'code' => 'corporate_tax', 'currency' => 'USD', 'effective_from' => '2026-01-01',
            'rate' => 0.25, 'source_url' => 'https://www.zimra.co.zw/', 'is_approved' => true,
        ]);

        // 1200 of profit over the first three months of the year.
        $rate = TaxRate::create(['company_id' => $this->company->id, 'name' => 'Zero', 'rate' => 0, 'is_active' => true, 'zimra_tax_id' => 2]);
        $this->postedRevenue(1200, '2026-02-15');

        $return = ComplianceReturn::create([
            'company_id' => $this->company->id, 'return_type' => 'QPD', 'status' => 'draft', 'instalment' => 1,
            'period_from' => '2026-01-01', 'period_to' => '2026-12-31', 'due_date' => '2026-03-25',
        ]);

        $this->actingAs($this->maker)->post("/compliance/returns/{$return->id}/transition", ['action' => 'prepare'])
            ->assertSessionHasNoErrors();

        $snapshot = $return->fresh()->snapshot;
        $this->assertSame('QPD', $snapshot['type']);
        $this->assertCount(4, $snapshot['rows']);

        // 1200 over 3 elapsed months annualises to 4800; at 25% that is 1200 of
        // annual tax, and QPD 1 is 10% of it.
        $this->assertSame(120.0, (float) $snapshot['rows'][0]['tax_due']);
        $this->assertSame(300.0, (float) $snapshot['rows'][1]['tax_due']);   // QPD 2 at 25%
        $this->assertSame('2026-03-25', $snapshot['rows'][0]['due_date']);
        $this->assertSame('100%', $snapshot['rows'][3]['cumulative']);

        // The estimate is flagged as annualised and the schedule as unverified.
        $types = collect($snapshot['exceptions'])->pluck('type');
        $this->assertTrue($types->contains('estimate_annualised'));
        $this->assertTrue($types->contains('qpd_schedule_unverified'));

        // An estimate must never freeze the books it is estimated from.
        $this->actingAs($this->checker)->post("/compliance/returns/{$return->id}/transition", ['action' => 'review']);
        $this->actingAs($this->checker)->post("/compliance/returns/{$return->id}/transition", ['action' => 'approve'])
            ->assertSessionHasNoErrors();
        $this->assertNull($this->company->fresh()->locked_before);

        $this->assertNotNull($rate);
    }

    /**
     * Only obligations with a real working-paper builder may be opened as a
     * return. The rest would inherit VAT schedules, VAT blocking exceptions,
     * a period lock and a VAT evidence pack under the wrong return's name.
     */
    public function test_returns_can_only_be_opened_for_types_with_working_papers(): void
    {
        foreach (['ITF12C', 'ITF12B'] as $type) {
            $this->actingAs($this->maker)->post('/compliance/returns', [
                'return_type' => $type, 'period_from' => '2026-01-01',
                'period_to' => '2026-12-31', 'due_date' => '2026-12-31',
            ])->assertSessionHasErrors('return_type');
        }

        $this->assertSame(0, ComplianceReturn::count());
    }

    public function test_a_return_whose_type_lost_its_working_papers_cannot_advance(): void
    {
        // Created directly: this models a row that predates the type's withdrawal.
        $legacy = ComplianceReturn::create([
            'company_id' => $this->company->id, 'return_type' => 'QPD', 'status' => 'draft',
            'period_from' => '2026-01-01', 'period_to' => '2026-12-31', 'due_date' => '2026-03-25',
        ]);

        $this->actingAs($this->maker)->post("/compliance/returns/{$legacy->id}/transition", ['action' => 'prepare'])
            ->assertSessionHas('error');

        $legacy->refresh();
        $this->assertSame('draft', $legacy->status);
        $this->assertNull($legacy->snapshot);
        // Critically: no period lock was taken on the strength of VAT workings.
        $this->assertNull($this->company->fresh()->locked_before);
    }

    public function test_the_calendar_still_tracks_obligations_that_have_no_workspace(): void
    {
        $this->actingAs($this->maker)->post('/compliance/deadlines', [
            'return_type' => 'QPD', 'period_from' => '2026-01-01',
            'period_to' => '2026-12-31', 'due_date' => '2026-03-25',
        ])->assertSessionHasNoErrors();

        // A QPD is due in-year, well before period_to — the shape the return
        // workspace's `due_date >= period_to` rule cannot express at all.
        $deadline = ComplianceDeadline::where('return_type', 'QPD')->sole();
        $this->assertSame('2026-03-25', $deadline->due_date->toDateString());
        $this->assertTrue($deadline->due_date->lt($deadline->period_to));

        // Free text is rejected so deadlines reconcile with returns and roll up.
        $this->actingAs($this->maker)->post('/compliance/deadlines', [
            'return_type' => 'QPD1', 'period_from' => '2026-01-01',
            'period_to' => '2026-12-31', 'due_date' => '2026-06-25',
        ])->assertSessionHasErrors('return_type');
    }

    /**
     * Period boundaries are inclusive. Date columns come back from SQLite as
     * "Y-m-d 00:00:00", so a bare "Y-m-d" upper bound drops everything dated on
     * the last day — silently under-declaring the return.
     */
    public function test_documents_dated_on_the_last_day_of_the_period_are_included(): void
    {
        $rate = TaxRate::create(['company_id' => $this->company->id, 'name' => 'VAT 15.5%', 'rate' => 15.5, 'is_active' => true, 'zimra_tax_id' => 517]);
        $invoice = $this->invoice('LAST-DAY', 100, $rate, 15.5);
        $invoice->update(['issue_date' => '2026-07-31']);

        $snapshot = app(ComplianceWorkspaceService::class)->build($this->company, '2026-07-01', '2026-07-31');
        $usd = collect($snapshot['schedules'])->firstWhere('currency', 'USD');

        $this->assertSame(100.0, $usd['standard_sales']);
        $this->assertSame(15.5, $usd['output_vat']);
    }

    /**
     * The registry's whole point: a PAYE-P2 return must get payroll figures and
     * payroll exceptions, not the VAT builder's output.
     */
    public function test_paye_p2_returns_get_payroll_working_papers_not_vat(): void
    {
        $run = $this->approvedPayrollRun();

        $return = ComplianceReturn::create([
            'company_id' => $this->company->id, 'return_type' => 'PAYE-P2', 'status' => 'draft',
            'period_from' => '2026-07-01', 'period_to' => '2026-07-31', 'due_date' => '2026-08-10',
        ]);

        $this->actingAs($this->maker)->post("/compliance/returns/{$return->id}/transition", ['action' => 'prepare'])
            ->assertSessionHasNoErrors();

        $snapshot = $return->fresh()->snapshot;
        $this->assertSame('PAYE-P2', $snapshot['type']);
        $this->assertArrayNotHasKey('schedules', $snapshot);       // no VAT figures
        $this->assertArrayNotHasKey('reconciliation', $snapshot);

        // The snapshot round-trips through JSON, so whole floats return as ints.
        $row = collect($snapshot['rows'])->firstWhere('currency', 'USD');
        $this->assertSame(1, $row['employees']);
        $this->assertSame(200.0, (float) $row['paye']);
        $this->assertSame(6.0, (float) $row['aids_levy']);
        $this->assertSame(206.0, (float) $row['total_tax']);

        // Its evidence pack carries payroll columns, not VAT ones.
        $this->actingAs($this->maker)->get("/compliance/returns/{$return->id}/evidence-pack")
            ->assertOk()->assertDownload('PAYE-P2-2026-07-evidence.zip');

        $this->assertSame('PAY-TEST', $run->reference);
    }

    /**
     * Only VAT7 freezes the books. A second locking return for the same period
     * would let whichever is approved first block the other's corrections.
     */
    public function test_only_vat7_locks_the_period_on_approval(): void
    {
        $this->approvedPayrollRun();
        $return = ComplianceReturn::create([
            'company_id' => $this->company->id, 'return_type' => 'PAYE-P2', 'status' => 'draft',
            'period_from' => '2026-07-01', 'period_to' => '2026-07-31', 'due_date' => '2026-08-10',
        ]);

        $this->actingAs($this->maker)->post("/compliance/returns/{$return->id}/transition", ['action' => 'prepare']);
        $this->actingAs($this->checker)->post("/compliance/returns/{$return->id}/transition", ['action' => 'review']);
        $this->actingAs($this->checker)->post("/compliance/returns/{$return->id}/transition", ['action' => 'approve'])
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $return->fresh()->status);
        $this->assertNull($this->company->fresh()->locked_before);
    }

    public function test_paye_p2_blocks_preparation_while_a_payroll_run_is_unapproved(): void
    {
        $this->approvedPayrollRun(approved: false);
        $return = ComplianceReturn::create([
            'company_id' => $this->company->id, 'return_type' => 'PAYE-P2', 'status' => 'draft',
            'period_from' => '2026-07-01', 'period_to' => '2026-07-31', 'due_date' => '2026-08-10',
        ]);

        $this->actingAs($this->maker)->post("/compliance/returns/{$return->id}/transition", ['action' => 'prepare'])
            ->assertSessionHas('error');
        $this->assertSame('draft', $return->fresh()->status);
    }

    /** Post revenue straight to the ledger so profitLoss() sees it. */
    protected function postedRevenue(float $amount, string $date): void
    {
        $chart = app(ChartOfAccountsService::class);
        app(\App\Services\JournalService::class)->post(
            $this->company, $date, 'Revenue', [
                ['account_id' => $chart->findBySubtype($this->company, 'bank')->id, 'debit' => $amount, 'credit' => 0],
                ['account_id' => $chart->find($this->company, '4000')->id, 'debit' => 0, 'credit' => $amount],
            ], null, 'REV', $this->maker->id,
        );
    }

    /** A payroll run paid inside July with one payslip carrying PAYE and AIDS levy. */
    protected function approvedPayrollRun(bool $approved = true): \App\Models\PayrollRun
    {
        $bank = app(ChartOfAccountsService::class)->findBySubtype($this->company, 'bank');
        $entry = $approved ? \App\Models\JournalEntry::create([
            'company_id' => $this->company->id, 'entry_no' => 'JE-PAY-1', 'date' => '2026-07-31',
            'description' => 'Payroll PAY-TEST', 'status' => 'posted', 'posted_at' => now(),
        ]) : null;

        $run = \App\Models\PayrollRun::create([
            'company_id' => $this->company->id, 'reference' => 'PAY-TEST',
            'period_from' => '2026-07-01', 'period_to' => '2026-07-31', 'pay_date' => '2026-07-31',
            'money_account_id' => $bank->id,
            'currency' => 'USD', 'status' => $approved ? 'approved' : 'draft',
            'total_gross' => 1000, 'total_deductions' => 206, 'total_net' => 794,
            'journal_entry_id' => $entry?->id,
        ]);

        $employee = \App\Models\Employee::create([
            'company_id' => $this->company->id, 'employee_no' => 'EMP-1', 'first_name' => 'A', 'last_name' => 'B',
            'contract_type' => 'permanent', 'pay_frequency' => 'monthly', 'base_salary' => 1000,
            'tax_number' => 'TIN-1',
        ]);

        $run->items()->create([
            'employee_id' => $employee->id, 'employee_name' => 'A B', 'currency' => 'USD',
            'basic' => 1000, 'earnings_total' => 1000, 'deductions_total' => 206, 'gross' => 1000, 'net' => 794,
            'lines' => [
                ['kind' => 'earning', 'code' => 'basic', 'name' => 'Basic', 'amount' => 1000, 'source' => 'recurring'],
                ['kind' => 'deduction', 'code' => 'paye', 'name' => 'PAYE', 'amount' => 200, 'source' => 'statutory'],
                ['kind' => 'deduction', 'code' => 'aids_levy', 'name' => 'AIDS Levy', 'amount' => 6, 'source' => 'statutory'],
            ],
        ]);

        return $run;
    }
}
