<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeLoan;
use App\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Carbon\Carbon;

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
        protected StatutoryPayrollService $statutory,
    ) {}

    /** Build one employee's pay slip. $onceOff: extra [{kind,code,name,amount}]. */
    public function computeItem(Employee $employee, array $onceOff = [], ?EmployeeAssignment $assignment = null, $payDate = null, ?string $currency = null): array
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

        $warnings = [];
        if ($payDate) {
            $grossBeforeStatutory = round(collect($lines)->where('kind', 'earning')->sum('amount'), 2);
            $result = $this->statutory->calculate(
                $employee, $grossBeforeStatutory, Carbon::parse($payDate),
                $assignment?->pay_frequency ?? $employee->pay_frequency,
                $currency ?? $employee->pay_currency ?? $employee->company->currency,
            );
            // Statutory lines replace legacy hand-entered lines with the same codes.
            $codes = collect($result['lines'])->pluck('code');
            $lines = collect($lines)->reject(fn ($line) => $line['source'] === 'recurring' && $codes->contains($line['code']))
                ->concat($result['lines'])->concat($result['employerCosts'])->values()->all();
            $warnings = $result['warnings'];
        }

        $earnings = round(collect($lines)->where('kind', 'earning')->sum('amount'), 2);
        $deductions = round(collect($lines)->where('kind', 'deduction')->sum('amount'), 2);
        $employerCosts = round(collect($lines)->where('kind', 'employer_cost')->sum('amount'), 2);

        return [
            'employee_id' => $employee->id,
            'employee_name' => $employee->full_name,
            'job_title' => $assignment?->job_title ?? $employee->job_title,
            'currency' => $currency ?? $employee->pay_currency ?? $employee->company->currency,
            'basic' => $basic,
            'earnings_total' => $earnings,
            'deductions_total' => $deductions,
            'gross' => $earnings,
            'net' => round($earnings - $deductions, 2),
            'employer_costs_total' => $employerCosts,
            'lines' => $lines,
            '_warnings' => $warnings,
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

            $this->assertPayableInBaseCurrency($company, (int) $data['money_account_id']);

            $run = PayrollRun::create([
                'company_id' => $company->id,
                'reference' => $this->nextReference($company),
                'period_from' => $data['period_from'],
                'period_to' => $data['period_to'],
                'pay_date' => $data['pay_date'],
                'money_account_id' => $data['money_account_id'],
                'currency' => $company->currency,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['user_id'] ?? null,
            ]);

            foreach ($assignments as $assignment) {
                if ($assignment->employee) {
                    $computed = $this->computeItem($assignment->employee, [], $assignment, $data['pay_date'], $company->currency);
                    $warnings = array_merge($warnings ?? [], $computed['_warnings']);
                    unset($computed['_warnings']);
                    $run->items()->create($computed);
                }
            }

            $run->update(['compliance_warnings' => array_values(array_unique($warnings ?? []))]);

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
                        $computed = $this->computeItem($item->employee, $extra, $assignment, $run->pay_date, $run->currency);
                        unset($computed['_warnings']);
                        $item->update($computed);
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

        // Re-checked at posting, not just at generation: the account's currency
        // may have been changed while the run sat in draft.
        $this->assertPayableInBaseCurrency($company, (int) $run->money_account_id);

        $gross = round((float) $run->total_gross, 2);
        $net = round((float) $run->total_net, 2);
        $employerCosts = round((float) $run->total_employer_costs, 2);
        if ($gross <= 0) {
            throw new InvalidArgumentException('Payroll run has no positive gross pay to post.');
        }

        $salaryExpense = $this->chart->find($company, '5010'); // Salaries & Wages
        $employerExpense = $this->chart->find($company, '5090');

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
            foreach ($item->lines as $line) {
                if ($line['kind'] !== 'employer_cost') continue;
                $account = $this->deductionAccount($company, $line['code']);
                $byAccount[$account->id] = round(($byAccount[$account->id] ?? 0) + (float) $line['amount'], 2);
            }
        }

        $lines = [
            ['account_id' => $salaryExpense->id, 'debit' => $gross, 'credit' => 0, 'memo' => 'Gross salaries'],
        ];
        if ($employerCosts > 0) $lines[] = ['account_id' => $employerExpense->id, 'debit' => $employerCosts, 'credit' => 0, 'memo' => 'Employer statutory payroll costs'];
        foreach ($byAccount as $accountId => $amount) {
            if ($amount > 0) {
                $lines[] = ['account_id' => $accountId, 'debit' => 0, 'credit' => $amount];
            }
        }
        $lines[] = ['account_id' => $run->money_account_id, 'debit' => 0, 'credit' => $net, 'memo' => 'Net pay'];

        // Stated rather than left to JournalService's default: the guard above
        // has established that every leg is in the base currency at par.
        $lines = array_map(fn ($line) => $line + ['currency' => $company->currency, 'fx_rate' => 1], $lines);

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

    /**
     * Payroll is computed and posted in the company's base currency only —
     * per-employee pay currencies and FX apportionment are not implemented.
     * Paying net from a foreign-currency account would post the foreign amount
     * as though it were base currency, so it is refused rather than converted.
     */
    protected function assertPayableInBaseCurrency(Company $company, int $moneyAccountId): void
    {
        $account = Account::find($moneyAccountId);
        if (! $account) {
            return; // The controller's exists rule already rejects unknown accounts.
        }

        $accountCurrency = $account->effectiveCurrency();
        if ($accountCurrency !== $company->currency) {
            throw new InvalidArgumentException(
                "Payroll is posted in {$company->currency}, but {$account->name} transacts in {$accountCurrency}. "
                ."Choose a {$company->currency} account, or transfer funds first."
            );
        }
    }

    /** Map a deduction component code to its liability account. */
    protected function deductionAccount(Company $company, string $code)
    {
        return match (strtolower($code)) {
            'paye', 'tax', 'paye_tax', 'income_tax', 'aids_levy' => $this->chart->findBySubtype($company, 'tax'),
            'pension', 'nssf', 'napsa', 'nssa_employee', 'nssa_employer', 'nssa_apwcs' => $this->chart->findBySubtype($company, 'pension'),
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
            'total_employer_costs' => round($items->sum('employer_costs_total'), 2),
        ]);

        return $run;
    }

    protected function nextReference(Company $company): string
    {
        $seq = PayrollRun::withTrashed()->where('company_id', $company->id)->lockForUpdate()->count() + 1;

        return 'PAY-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
