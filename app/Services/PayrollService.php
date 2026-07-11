<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeLoan;
use App\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Computes payroll runs from each employee's salary structure and posts
 * the accounting impact on approval:
 *   Dr Salaries expense (gross)
 *   Cr PAYE / Pension / Payroll-deduction liabilities
 *   Cr Bank (net pay)
 * Gross = net + deductions, so the entry always balances.
 */
class PayrollService
{
    public function __construct(
        protected JournalService $journal,
        protected ChartOfAccountsService $chart,
    ) {}

    /** Build one employee's pay slip. $onceOff: extra [{kind,code,name,amount}]. */
    public function computeItem(Employee $employee, array $onceOff = [], ?EmployeeAssignment $assignment = null): array
    {
        $basic = round((float) ($assignment?->base_salary ?? $employee->base_salary), 2);

        $lines = [[
            'kind' => 'earning', 'code' => 'basic', 'name' => 'Basic Salary',
            'amount' => $basic, 'source' => 'recurring',
        ]];

        foreach ($employee->components->where('is_active', true) as $component) {
            $lines[] = [
                'kind' => $component->kind,
                'code' => $component->code,
                'name' => $component->name,
                'amount' => $component->resolve($basic),
                'source' => 'recurring',
            ];
        }

        foreach ($employee->loans ?? [] as $loan) {
            if ($loan->status !== 'active' || (float) $loan->outstanding_balance <= 0) {
                continue;
            }
            $amount = min((float) $loan->repayment_amount, (float) $loan->outstanding_balance);
            if ($amount <= 0) {
                continue;
            }
            $lines[] = [
                'kind' => 'deduction',
                'code' => 'loan_repayment',
                'name' => 'Loan repayment '.$loan->loan_no,
                'amount' => round($amount, 2),
                'source' => 'loan',
                'loan_id' => $loan->id,
            ];
        }

        foreach ($onceOff as $adj) {
            $lines[] = [
                'kind' => in_array($adj['kind'] ?? 'earning', ['earning', 'deduction'], true) ? $adj['kind'] : 'earning',
                'code' => $adj['code'] ?? 'adjustment',
                'name' => $adj['name'],
                'amount' => round((float) $adj['amount'], 2),
                'source' => 'once_off',
            ];
        }

        $earnings = round(collect($lines)->where('kind', 'earning')->sum('amount'), 2);
        $deductions = round(collect($lines)->where('kind', 'deduction')->sum('amount'), 2);

        return [
            'employee_id' => $employee->id,
            'employee_name' => $employee->full_name,
            'job_title' => $assignment?->job_title ?? $employee->job_title,
            'basic' => $basic,
            'earnings_total' => $earnings,
            'deductions_total' => $deductions,
            'gross' => $earnings,
            'net' => round($earnings - $deductions, 2),
            'lines' => $lines,
        ];
    }

    /** Create a draft run with a pay slip for every active employee. */
    public function generate(Company $company, array $data): PayrollRun
    {
        return DB::transaction(function () use ($company, $data) {
            $assignments = EmployeeAssignment::where('employee_assignments.company_id', $company->id)
                ->where('employee_assignments.is_active', true)
                ->with([
                    'employee.components',
                    'employee.loans' => fn ($query) => $query->where('status', 'active'),
                ])
                ->join('employees', 'employees.id', '=', 'employee_assignments.employee_id')
                ->orderBy('employees.employee_no')
                ->select('employee_assignments.*')
                ->get();

            if ($assignments->isEmpty()) {
                throw new InvalidArgumentException('There are no active employees to pay.');
            }

            $run = PayrollRun::create([
                'company_id' => $company->id,
                'reference' => $this->nextReference($company),
                'period_from' => $data['period_from'],
                'period_to' => $data['period_to'],
                'pay_date' => $data['pay_date'],
                'money_account_id' => $data['money_account_id'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['user_id'] ?? null,
            ]);

            foreach ($assignments as $assignment) {
                if ($assignment->employee) {
                    $run->items()->create($this->computeItem($assignment->employee, [], $assignment));
                }
            }

            return $this->refreshTotals($run);
        });
    }

    /**
     * Approve a run, apply optional once-off adjustments per employee,
     * recompute, and post the payroll journal entry.
     * $adjustments: [employee_id => [{kind,name,amount,code?}, ...]]
     */
    public function approve(PayrollRun $run, array $adjustments, int $userId): PayrollRun
    {
        if ($run->isApproved()) {
            throw new InvalidArgumentException('This payroll run is already approved.');
        }

        return DB::transaction(function () use ($run, $adjustments, $userId) {
            if ($adjustments) {
                $run->load('items.employee.components');
                $run->load('items.employee.loans');
                foreach ($run->items as $item) {
                    $extra = $adjustments[$item->employee_id] ?? [];
                    if ($extra && $item->employee) {
                        $assignment = EmployeeAssignment::where('employee_id', $item->employee_id)
                            ->where('company_id', $run->company_id)
                            ->first();
                        $item->update($this->computeItem($item->employee, $extra, $assignment));
                    }
                }
            }

            $run = $this->refreshTotals($run->fresh('items'));
            $this->postJournal($run, $userId);
            $this->recordLoanRepayments($run, $userId);

            $run->update([
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            return $run->fresh(['items', 'moneyAccount']);
        });
    }

    protected function postJournal(PayrollRun $run, int $userId): void
    {
        $company = $run->company;
        $this->chart->ensurePayrollAccounts($company);
        $this->chart->ensureEmployeeLoanAccount($company);

        $gross = round((float) $run->total_gross, 2);
        $net = round((float) $run->total_net, 2);
        if ($gross <= 0) {
            throw new InvalidArgumentException('Payroll run has no positive gross pay to post.');
        }

        $salaryExpense = $this->chart->find($company, '5010'); // Salaries & Wages

        // Aggregate deductions by target liability account.
        $byAccount = [];
        foreach ($run->items as $item) {
            foreach ($item->lines as $line) {
                if ($line['kind'] !== 'deduction') {
                    continue;
                }
                $account = $this->deductionAccount($company, $line['code']);
                $byAccount[$account->id] = round(($byAccount[$account->id] ?? 0) + (float) $line['amount'], 2);
            }
        }

        $lines = [
            ['account_id' => $salaryExpense->id, 'debit' => $gross, 'credit' => 0, 'memo' => 'Gross salaries'],
        ];
        foreach ($byAccount as $accountId => $amount) {
            if ($amount > 0) {
                $lines[] = ['account_id' => $accountId, 'debit' => 0, 'credit' => $amount];
            }
        }
        $lines[] = ['account_id' => $run->money_account_id, 'debit' => 0, 'credit' => $net, 'memo' => 'Net pay'];

        $entry = $this->journal->post(
            $company,
            $run->pay_date->toDateString(),
            'Payroll '.$run->reference,
            $lines,
            $run,
            $run->reference,
            $userId,
        );

        $run->update(['journal_entry_id' => $entry->id]);
    }

    /** Map a deduction component code to its liability account. */
    protected function deductionAccount(Company $company, string $code)
    {
        return match (strtolower($code)) {
            'paye', 'tax', 'paye_tax', 'income_tax' => $this->chart->findBySubtype($company, 'tax'),
            'pension', 'nssf', 'napsa' => $this->chart->findBySubtype($company, 'pension'),
            'loan', 'loan_repayment', 'employee_loan' => $this->chart->findBySubtype($company, 'employee_loan'),
            default => $this->chart->findBySubtype($company, 'payroll_deduction'),
        };
    }

    protected function recordLoanRepayments(PayrollRun $run, int $userId): void
    {
        foreach ($run->items as $item) {
            foreach ($item->lines as $line) {
                if (($line['code'] ?? null) !== 'loan_repayment' || empty($line['loan_id'])) {
                    continue;
                }

                $loan = EmployeeLoan::whereKey($line['loan_id'])->lockForUpdate()->first();
                if (! $loan || $loan->status !== 'active') {
                    continue;
                }

                $amount = min(round((float) $line['amount'], 2), round((float) $loan->outstanding_balance, 2));
                if ($amount <= 0) {
                    continue;
                }

                $loan->payments()->create([
                    'payroll_run_id' => $run->id,
                    'payroll_item_id' => $item->id,
                    'date' => $run->pay_date->toDateString(),
                    'amount' => $amount,
                    'source' => 'payroll',
                    'reference' => $run->reference,
                    'created_by' => $userId,
                ]);

                $totalRepaid = round((float) $loan->total_repaid + $amount, 2);
                $outstanding = round((float) $loan->principal - $totalRepaid, 2);
                $loan->update([
                    'total_repaid' => $totalRepaid,
                    'outstanding_balance' => max($outstanding, 0),
                    'status' => $outstanding <= 0.005 ? 'settled' : 'active',
                ]);
            }
        }
    }

    protected function refreshTotals(PayrollRun $run): PayrollRun
    {
        $items = $run->items;
        $run->update([
            'total_gross' => round($items->sum('gross'), 2),
            'total_deductions' => round($items->sum('deductions_total'), 2),
            'total_net' => round($items->sum('net'), 2),
        ]);

        return $run;
    }

    protected function nextReference(Company $company): string
    {
        $seq = PayrollRun::withTrashed()->where('company_id', $company->id)->lockForUpdate()->count() + 1;

        return 'PAY-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
