<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\ChartOfAccountsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PeriodLockAndAuditTest extends TestCase
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

    protected function recordExpense(string $date, float $amount = 100): Expense
    {
        $chart = app(ChartOfAccountsService::class);

        return app(AccountingService::class)->recordExpense($this->company, [
            'date' => $date,
            'account_id' => $chart->find($this->company, '5000')->id,
            'paid_from_account_id' => $chart->findBySubtype($this->company, 'bank')->id,
            'amount' => $amount,
        ], $this->user->id);
    }

    public function test_posting_into_a_locked_period_is_rejected(): void
    {
        $this->company->update(['locked_before' => '2026-01-01']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('locked');

        $this->recordExpense('2025-12-31');
    }

    public function test_posting_on_or_after_the_lock_date_is_allowed(): void
    {
        $this->company->update(['locked_before' => '2026-01-01']);

        $expense = $this->recordExpense('2026-01-01');

        $this->assertNotNull($expense->journal_entry_id);
    }

    public function test_reversal_into_a_locked_period_is_rejected(): void
    {
        $expense = $this->recordExpense('2026-01-15');
        $this->company->update(['locked_before' => '2026-07-01']);

        $this->expectException(InvalidArgumentException::class);

        // Reversal entry would be dated inside the locked period.
        app(AccountingService::class)->reverseEntry(
            $expense->journalEntry()->first(), '2026-06-30', $this->user->id,
        );
    }

    public function test_updates_are_audited_with_old_and_new_values(): void
    {
        $contact = Contact::create([
            'company_id' => $this->company->id, 'type' => 'customer', 'name' => 'Original Name',
        ]);

        $this->actingAs($this->user)
            ->put('/contacts/'.$contact->id, [
                'type' => 'customer', 'name' => 'New Name', 'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $log = AuditLog::where('auditable_type', Contact::class)
            ->where('auditable_id', $contact->id)
            ->where('action', 'updated')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertSame($this->company->id, $log->company_id);
        $this->assertSame('Original Name', $log->changes['name']['old']);
        $this->assertSame('New Name', $log->changes['name']['new']);
    }

    public function test_role_changes_are_audited(): void
    {
        $admin = User::factory()->create(['current_company_id' => $this->company->id]);
        $admin->assignRole('system-admin');
        $admin->companies()->attach($this->company->id);

        $target = User::factory()->create();
        $target->assignRole('viewer');

        $this->actingAs($admin)
            ->put('/admin/users/'.$target->id, [
                'name' => $target->name,
                'email' => $target->email,
                'assignments' => [
                    ['company_id' => $this->company->id, 'role' => 'accountant'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $log = AuditLog::where('auditable_type', User::class)
            ->where('auditable_id', $target->id)
            ->where('action', 'access_changed')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(['Hold: viewer'], $log->changes['assignments']['old']);
        $this->assertSame(['Hold: accountant'], $log->changes['assignments']['new']);
    }

    public function test_reversing_an_invoice_entry_voids_the_invoice(): void
    {
        $invoice = $this->issuedInvoice();

        app(AccountingService::class)->reverseEntry(
            $invoice->journalEntry()->first(), '2026-03-05', $this->user->id,
        );

        $this->assertSame('void', $invoice->fresh()->status);
    }

    public function test_invoice_entry_cannot_be_reversed_while_payments_exist(): void
    {
        $acc = app(AccountingService::class);
        $chart = app(ChartOfAccountsService::class);
        $invoice = $this->issuedInvoice();

        $acc->recordInvoicePayment($invoice->fresh('company'), [
            'date' => '2026-03-02', 'amount' => 400,
            'deposit_account_id' => $chart->findBySubtype($this->company, 'bank')->id,
        ], $this->user->id);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('payments');

        $acc->reverseEntry($invoice->journalEntry()->first(), '2026-03-05', $this->user->id);
    }

    public function test_reversing_a_payment_entry_restores_the_invoice_balance(): void
    {
        $acc = app(AccountingService::class);
        $chart = app(ChartOfAccountsService::class);
        $invoice = $this->issuedInvoice();

        $payment = $acc->recordInvoicePayment($invoice->fresh('company'), [
            'date' => '2026-03-02', 'amount' => 1000,
            'deposit_account_id' => $chart->findBySubtype($this->company, 'bank')->id,
        ], $this->user->id);
        $this->assertSame('paid', $invoice->fresh()->status);

        $acc->reverseEntry($payment->journalEntry()->first(), '2026-03-05', $this->user->id);

        $invoice = $invoice->fresh();
        $this->assertSame('sent', $invoice->status);
        $this->assertSame(0.0, (float) $invoice->amount_paid);
        $this->assertNotNull($payment->fresh()->reversed_at);
    }

    protected function issuedInvoice(): Invoice
    {
        $chart = app(ChartOfAccountsService::class);
        $client = Contact::create(['company_id' => $this->company->id, 'type' => 'customer', 'name' => 'Client']);

        $invoice = Invoice::create([
            'company_id' => $this->company->id, 'contact_id' => $client->id,
            'invoice_no' => 'INV-90001', 'issue_date' => '2026-03-01', 'status' => 'draft',
            'subtotal' => 1000, 'tax_amount' => 0, 'total' => 1000,
        ]);
        $invoice->items()->create([
            'account_id' => $chart->find($this->company, '4000')->id, 'description' => 'Work',
            'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000,
        ]);

        return app(AccountingService::class)
            ->issueInvoice($invoice->fresh(['items', 'contact', 'company']), $this->user->id);
    }
}
