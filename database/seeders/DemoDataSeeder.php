<?php

namespace Database\Seeders;

use App\Models\Budget;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\FinancialStatement;
use App\Models\Invoice;
use App\Models\RecurringExpense;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\ChartOfAccountsService;
use App\Services\PayrollService;
use App\Services\StatementSnapshotService;
use Illuminate\Database\Seeder;

/**
 * Reconciled end-to-end demo dataset. Every figure posts through the
 * real accounting services, so double entry holds by construction.
 *
 * Expected balances (asserted by DemoDataReconciliationTest):
 *   Subsidiary Alpha — bank 32,550 · petty cash 380 · AR 4,400
 *   income 10,400 · expenses 3,820 · net profit 6,580
 *   tax payable 750 · intercompany payable 10,000 · owner equity 20,000
 *   Holding — bank 40,000 · intercompany receivable 10,000 · equity 50,000
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DatabaseSeeder::class); // clean Horizon company, chart, admin, roles, tax rates

        $holding = Company::where('code', 'HOLD')->firstOrFail();
        $admin = User::where('email', 'admin@horizon-equity.com')->firstOrFail();
        $subA = Company::firstOrCreate(
            ['code' => 'SUB-A'],
            [
                'name' => 'Subsidiary Alpha Ltd',
                'type' => 'subsidiary',
                'parent_id' => $holding->id,
                'currency' => 'USD',
            ],
        );

        $chart = app(ChartOfAccountsService::class);
        $chart->seedDefaultChart($subA);
        setPermissionsTeamId($subA->id);
        $admin->unsetRelation('roles')->syncRoles(['system-admin']);
        $admin->companies()->syncWithoutDetaching([$subA->id]);
        setPermissionsTeamId(null);

        if (Invoice::where('company_id', $subA->id)->exists()) {
            return; // demo data already seeded
        }

        $acc = app(AccountingService::class);
        $vat = TaxRate::whereNull('company_id')->where('name', 'VAT 15%')->firstOrFail();

        $holdBank = $chart->findBySubtype($holding, 'bank');
        $bank = $chart->findBySubtype($subA, 'bank');
        $petty = $chart->findBySubtype($subA, 'petty_cash');
        $sales = $chart->find($subA, '4000');

        // ── Funding: owners in, holding lends to the subsidiary ─────────
        $acc->recordEquityTransaction($holding, [
            'type' => 'contribution', 'date' => '2026-01-05', 'owner_name' => 'W. Rombe',
            'amount' => 50000, 'money_account_id' => $holdBank->id,
        ], $admin->id);

        $acc->recordEquityTransaction($subA, [
            'type' => 'contribution', 'date' => '2026-01-06', 'owner_name' => 'W. Rombe',
            'amount' => 20000, 'money_account_id' => $bank->id,
        ], $admin->id);

        $acc->recordIntercompanyTransfer([
            'from_company_id' => $holding->id, 'to_company_id' => $subA->id,
            'type' => 'loan', 'date' => '2026-01-10', 'amount' => 10000,
            'from_money_account_id' => $holdBank->id, 'to_money_account_id' => $bank->id,
            'description' => 'Working-capital loan to Alpha',
        ], $admin->id);

        // ── Customers, vendors ───────────────────────────────────────────
        $mkCustomer = fn (string $name) => Contact::create([
            'company_id' => $subA->id, 'type' => 'customer', 'name' => $name, 'is_active' => true,
        ]);
        $acme = $mkCustomer('Acme Retail Ltd');
        $zenith = $mkCustomer('Zenith Mining (Pvt) Ltd');
        $delta = $mkCustomer('Delta Farms Co-op');
        foreach (['Office Landlords Ltd', 'City Utilities'] as $vendor) {
            Contact::create(['company_id' => $subA->id, 'type' => 'vendor', 'name' => $vendor, 'is_active' => true]);
        }

        // ── Invoices: paid (with VAT), partially paid, unpaid ────────────
        $invoice = function (Contact $c, string $no, string $date, float $subtotal, ?TaxRate $rate) use ($subA, $sales, $acc, $admin) {
            $tax = $rate ? round($subtotal * 0.15, 2) : 0;
            $inv = Invoice::create([
                'company_id' => $subA->id, 'contact_id' => $c->id, 'invoice_no' => $no,
                'issue_date' => $date, 'status' => 'draft', 'subtotal' => $subtotal,
                'tax_rate_id' => $rate?->id, 'tax_amount' => $tax, 'total' => $subtotal + $tax,
                'created_by' => $admin->id,
            ]);
            $inv->items()->create([
                'account_id' => $sales->id, 'description' => 'Professional services',
                'quantity' => 1, 'unit_price' => $subtotal, 'amount' => $subtotal,
            ]);

            return $acc->issueInvoice($inv->fresh(['items', 'contact', 'company']), $admin->id);
        };

        $paid = $invoice($acme, 'INV-00001', '2026-02-01', 5000, $vat);      // 5,750 incl. VAT
        $partial = $invoice($zenith, 'INV-00002', '2026-02-10', 3000, null);
        $invoice($delta, 'INV-00003', '2026-03-01', 2400, null);             // stays unpaid

        $acc->recordInvoicePayment($paid->fresh('company'), [
            'date' => '2026-02-20', 'amount' => 5750, 'deposit_account_id' => $bank->id,
        ], $admin->id);
        $acc->recordInvoicePayment($partial->fresh('company'), [
            'date' => '2026-03-05', 'amount' => 1000, 'deposit_account_id' => $bank->id,
        ], $admin->id);
        // AR = (3,000 − 1,000) + 2,400 = 4,400

        // ── Expenses & petty cash ────────────────────────────────────────
        $acc->recordExpense($subA, [
            'date' => '2026-02-28', 'account_id' => $chart->find($subA, '5000')->id,
            'paid_from_account_id' => $bank->id, 'amount' => 1200, 'description' => 'Office rent Feb',
        ], $admin->id);
        $acc->recordExpense($subA, [
            'date' => '2026-03-25', 'account_id' => $chart->find($subA, '5010')->id,
            'paid_from_account_id' => $bank->id, 'amount' => 2500, 'description' => 'March payroll',
        ], $admin->id);

        $acc->recordPayment($subA, [
            'kind' => 'transfer', 'date' => '2026-03-01', 'amount' => 500,
            'from_account_id' => $bank->id, 'to_account_id' => $petty->id,
            'description' => 'Petty cash top-up',
        ], $admin->id);
        $acc->recordExpense($subA, [
            'date' => '2026-03-12', 'account_id' => $chart->find($subA, '5050')->id,
            'paid_from_account_id' => $petty->id, 'amount' => 120, 'description' => 'Courier fuel',
        ], $admin->id);
        // Bank 20,000+10,000+5,750+1,000−1,200−2,500−500 = 32,550 · petty 380

        // ── Budget + recurring schedule (master data completeness) ──────
        $budget = Budget::create([
            'company_id' => $subA->id, 'name' => 'FY2026 Operating Budget',
            'fiscal_year' => 2026, 'status' => 'active', 'created_by' => $admin->id,
        ]);
        foreach ([1, 2, 3] as $month) {
            $budget->lines()->create(['account_id' => $chart->find($subA, '5000')->id, 'month' => $month, 'amount' => 1250]);
            $budget->lines()->create(['account_id' => $chart->find($subA, '4000')->id, 'month' => $month, 'amount' => 3500]);
        }

        RecurringExpense::create([
            'company_id' => $subA->id, 'name' => 'Office rent', 'account_id' => $chart->find($subA, '5000')->id,
            'paid_from_account_id' => $bank->id, 'amount' => 1200, 'frequency' => 'monthly',
            'start_date' => '2027-01-01', 'next_run_date' => '2027-01-01', 'is_active' => true,
        ]);

        // ── Employees + a draft payroll run (People module) ─────────────
        // Draft only, so it doesn't post to the ledger or disturb the
        // reconciled balances above; review/approve it from the UI to post.
        $chart->ensurePayrollAccounts($subA);
        $employees = [
            ['first' => 'Jane', 'last' => 'Banda', 'no' => 'EMP-0001', 'title' => 'Accountant', 'base' => 3000, 'components' => [
                ['earning', 'transport', 'Transport Allowance', 'fixed', 300],
                ['deduction', 'pension', 'Pension (5%)', 'percent_of_basic', 5],
                ['deduction', 'paye', 'PAYE', 'fixed', 400],
            ]],
            ['first' => 'Peter', 'last' => 'Mulenga', 'no' => 'EMP-0002', 'title' => 'Analyst', 'base' => 2500, 'components' => [
                ['earning', 'housing', 'Housing Allowance (10%)', 'percent_of_basic', 10],
                ['deduction', 'pension', 'Pension (5%)', 'percent_of_basic', 5],
                ['deduction', 'paye', 'PAYE', 'fixed', 300],
            ]],
        ];
        foreach ($employees as $e) {
            $employee = Employee::create([
                'company_id' => $subA->id, 'employee_no' => $e['no'],
                'first_name' => $e['first'], 'last_name' => $e['last'], 'job_title' => $e['title'],
                'department' => 'Finance', 'contract_type' => 'permanent', 'pay_frequency' => 'monthly',
                'start_date' => '2026-01-01', 'base_salary' => $e['base'],
                'payment_account_id' => $bank->id, 'is_active' => true, 'created_by' => $admin->id,
            ]);
            $employee->assignments()->create([
                'company_id' => $subA->id,
                'job_title' => $e['title'],
                'department' => 'Finance',
                'pay_frequency' => 'monthly',
                'start_date' => '2026-01-01',
                'base_salary' => $e['base'],
                'is_active' => true,
            ]);
            foreach ($e['components'] as [$kind, $code, $cname, $calc, $amt]) {
                $employee->components()->create([
                    'kind' => $kind, 'code' => $code, 'name' => $cname,
                    'calc_type' => $calc, 'amount' => $amt, 'is_active' => true,
                ]);
            }
        }
        app(PayrollService::class)->generate($subA, [
            'period_from' => '2026-04-01', 'period_to' => '2026-04-30', 'pay_date' => '2026-04-30',
            'money_account_id' => $bank->id, 'user_id' => $admin->id,
            'notes' => 'April 2026 salaries — review and approve to post.',
        ]);

        // ── Registered statements: drafts + an approved balance sheet ───
        $snapshots = app(StatementSnapshotService::class);
        foreach (['trial-balance', 'profit-loss', 'balance-sheet'] as $type) {
            $snapshot = $snapshots->build($type, [$subA->id], '2026-01-01', '2026-03-31', $subA);
            FinancialStatement::create([
                'company_id' => $subA->id, 'type' => $type,
                'name' => FinancialStatement::TYPES[$type].' — '.$subA->name.' — 2026-03-31',
                'scope_label' => $subA->name, 'period_from' => '2026-01-01', 'period_to' => '2026-03-31',
                'status' => $type === 'balance-sheet' ? 'approved' : 'draft',
                'columns' => $snapshot['columns'], 'lines' => $snapshot['lines'],
                'generated_by' => $admin->id,
                'approved_by' => $type === 'balance-sheet' ? $admin->id : null,
                'approved_at' => $type === 'balance-sheet' ? now() : null,
            ]);
        }
    }
}
