<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EquityTransaction;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\ChartOfAccountsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionOmitTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->company = Company::create(['name' => 'Hold', 'code' => 'H', 'type' => 'holding']);
        app(ChartOfAccountsService::class)->seedDefaultChart($this->company);

        $this->admin = User::factory()->create(['current_company_id' => $this->company->id]);
        setPermissionsTeamId($this->company->id);
        $this->admin->assignRole('system-admin');
        $this->admin->companies()->attach($this->company->id);
    }

    protected function issuedInvoiceWithPayment(): Invoice
    {
        $acc = app(AccountingService::class);
        $chart = app(ChartOfAccountsService::class);
        $bank = $chart->findBySubtype($this->company, 'bank');
        $sales = $chart->find($this->company, '4000');

        $client = Contact::create(['company_id' => $this->company->id, 'type' => 'customer', 'name' => 'Client']);
        $invoice = Invoice::create([
            'company_id' => $this->company->id, 'contact_id' => $client->id,
            'invoice_no' => 'INV-1', 'issue_date' => '2026-02-01', 'status' => 'draft',
            'subtotal' => 1000, 'tax_amount' => 0, 'total' => 1000,
        ]);
        $invoice->items()->create([
            'account_id' => $sales->id, 'description' => 'Work',
            'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000,
        ]);
        $acc->issueInvoice($invoice->fresh(['items', 'contact', 'company']), $this->admin->id);
        $acc->recordInvoicePayment($invoice->fresh('company'), [
            'date' => '2026-02-10', 'amount' => 1000, 'deposit_account_id' => $bank->id,
        ], $this->admin->id);

        return $invoice->fresh();
    }

    public function test_admin_can_omit_an_invoice_and_its_payment_entries_from_the_revenue_page(): void
    {
        $invoice = $this->issuedInvoiceWithPayment();
        $this->assertSame(2, JournalEntry::count());

        $this->actingAs($this->admin)
            ->delete('/income/invoices/'.$invoice->id.'/omit', ['reason' => 'Duplicate invoice captured in error'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertSame(0, InvoicePayment::count());
        $this->assertSame(0, JournalEntry::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'transaction_omitted', 'company_id' => $this->company->id]);
    }

    public function test_admin_can_omit_expense_payment_and_equity_transactions(): void
    {
        $acc = app(AccountingService::class);
        $chart = app(ChartOfAccountsService::class);
        $bank = $chart->findBySubtype($this->company, 'bank');
        $rent = $chart->find($this->company, '5000');

        $expense = $acc->recordExpense($this->company, [
            'date' => '2026-03-01', 'account_id' => $rent->id,
            'paid_from_account_id' => $bank->id, 'amount' => 300,
        ], $this->admin->id);

        $payment = $acc->recordPayment($this->company, [
            'kind' => 'outgoing', 'date' => '2026-03-02', 'amount' => 50,
            'from_account_id' => $bank->id, 'category_account_id' => $rent->id,
        ], $this->admin->id);

        $equity = $acc->recordEquityTransaction($this->company, [
            'type' => 'contribution', 'date' => '2026-03-03', 'owner_name' => 'Owner',
            'amount' => 5000, 'money_account_id' => $bank->id,
        ], $this->admin->id);

        $this->assertSame(3, JournalEntry::count());

        $this->actingAs($this->admin)
            ->delete('/expenses/'.$expense->id.'/omit', ['reason' => 'Captured with the wrong amount'])
            ->assertSessionHas('success');
        $this->actingAs($this->admin)
            ->delete('/payments/'.$payment->id.'/omit', ['reason' => 'Payment recorded twice by mistake'])
            ->assertSessionHas('success');
        $this->actingAs($this->admin)
            ->delete('/equity/'.$equity->id.'/omit', ['reason' => 'Wrong owner selected on capture'])
            ->assertSessionHas('success');

        $this->assertSame(0, Expense::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, EquityTransaction::count());
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(3, AuditLog::where('action', 'transaction_omitted')->count());
    }

    public function test_non_admin_cannot_omit_and_reason_is_required(): void
    {
        $invoice = $this->issuedInvoiceWithPayment();

        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->assignRole('holding-admin');
        $user->companies()->attach($this->company->id);

        $this->actingAs($user)
            ->delete('/income/invoices/'.$invoice->id.'/omit', ['reason' => 'Trying without the right role'])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->delete('/income/invoices/'.$invoice->id.'/omit', ['reason' => 'short'])
            ->assertSessionHasErrors('reason');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        $this->assertSame(2, JournalEntry::count());
    }
}
