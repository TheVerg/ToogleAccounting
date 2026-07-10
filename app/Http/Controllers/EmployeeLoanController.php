<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Services\ChartOfAccountsService;
use App\Services\JournalService;
use App\Support\CompanyRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class EmployeeLoanController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = $request->user()->current_company_id;
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'settled', 'written_off'])],
        ]);

        return Inertia::render('Loans/Index', [
            'loans' => EmployeeLoan::where('company_id', $companyId)
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->with(['employee:id,first_name,last_name,employee_no,job_title', 'moneyAccount:id,code,name'])
                ->withCount('payments')
                ->orderByDesc('issue_date')
                ->orderByDesc('id')
                ->get(),
            'employees' => Employee::where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('employee_no')
                ->get(['id', 'employee_no', 'first_name', 'last_name', 'job_title']),
            'moneyAccounts' => Account::where('company_id', $companyId)
                ->whereIn('subtype', Account::MONEY_SUBTYPES)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'filters' => ['status' => $filters['status'] ?? ''],
        ]);
    }

    public function store(Request $request, JournalService $journal, ChartOfAccountsService $chart): RedirectResponse
    {
        $company = $request->user()->currentCompany;
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('company_id', $company->id)],
            'issue_date' => ['required', 'date'],
            'principal' => ['required', 'numeric', 'min:0.01'],
            'repayment_amount' => ['required', 'numeric', 'min:0.01'],
            'money_account_id' => ['required', 'integer', CompanyRules::moneyAccount($company->id)],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            DB::transaction(function () use ($company, $data, $request, $journal, $chart) {
                $loan = EmployeeLoan::create($data + [
                    'company_id' => $company->id,
                    'loan_no' => $this->nextLoanNo($company->id),
                    'outstanding_balance' => round((float) $data['principal'], 2),
                    'status' => 'active',
                    'created_by' => $request->user()->id,
                ]);

                $loanAccount = $chart->ensureEmployeeLoanAccount($company);
                $entry = $journal->post(
                    $company,
                    $data['issue_date'],
                    'Employee loan '.$loan->loan_no.' issued to '.$loan->employee->full_name,
                    [
                        ['account_id' => $loanAccount->id, 'debit' => $loan->principal, 'credit' => 0, 'memo' => $loan->loan_no],
                        ['account_id' => $data['money_account_id'], 'debit' => 0, 'credit' => $loan->principal, 'memo' => $loan->loan_no],
                    ],
                    $loan,
                    $loan->loan_no,
                    $request->user()->id,
                );

                $loan->update(['journal_entry_id' => $entry->id]);
            });
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Employee loan created and posted.');
    }

    protected function nextLoanNo(int $companyId): string
    {
        $next = EmployeeLoan::where('company_id', $companyId)->lockForUpdate()->count() + 1;

        return 'LN-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
