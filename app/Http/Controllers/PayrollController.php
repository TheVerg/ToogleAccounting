<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\EmployeeAssignment;
use App\Models\PayrollRun;
use App\Services\PayrollService;
use App\Support\CompanyRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class PayrollController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = $request->user()->current_company_id;

        return Inertia::render('Payroll/Index', [
            'runs' => PayrollRun::withTrashed()
                ->where('company_id', $companyId)
                ->withCount('items')
                ->with(['approver:id,name', 'moneyAccount:id,code,name'])
                ->latest('id')->paginate(15)->withQueryString(),
            'moneyAccounts' => Account::where('company_id', $companyId)
                ->whereIn('subtype', Account::MONEY_SUBTYPES)->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'activeEmployees' => EmployeeAssignment::where('company_id', $companyId)->where('is_active', true)->count(),
        ]);
    }

    public function store(Request $request, PayrollService $payroll): RedirectResponse
    {
        $companyId = $request->user()->current_company_id;

        $data = $request->validate([
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'pay_date' => ['required', 'date'],
            'money_account_id' => ['required', 'integer', CompanyRules::moneyAccount($companyId)],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $run = $payroll->generate($request->user()->currentCompany, $data + ['user_id' => $request->user()->id]);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('payroll.show', $run)
            ->with('success', 'Payroll '.$run->reference.' generated (draft).');
    }

    public function show(Request $request, PayrollRun $payrollRun): Response
    {
        abort_unless($payrollRun->company_id === $request->user()->current_company_id, 403);

        return Inertia::render('Payroll/Show', [
            'run' => $payrollRun->load(['items', 'moneyAccount:id,code,name', 'approver:id,name']),
            'canApprove' => $request->user()->can('payroll.manage'),
        ]);
    }

    public function approve(Request $request, PayrollRun $payrollRun, PayrollService $payroll): RedirectResponse
    {
        abort_unless($payrollRun->company_id === $request->user()->current_company_id, 403);

        $data = $request->validate([
            'adjustments' => ['array'],
            'adjustments.*.employee_id' => ['required', 'integer'],
            'adjustments.*.kind' => ['required', 'in:earning,deduction'],
            'adjustments.*.name' => ['required', 'string', 'max:100'],
            'adjustments.*.amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        // Group adjustments by employee for the service.
        $byEmployee = [];
        foreach ($data['adjustments'] ?? [] as $adj) {
            $byEmployee[$adj['employee_id']][] = $adj;
        }

        try {
            $payroll->approve($payrollRun, $byEmployee, $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $payrollRun->reference.' approved and posted to the ledger.');
    }

    public function destroy(Request $request, PayrollRun $payrollRun): RedirectResponse
    {
        abort_unless($payrollRun->company_id === $request->user()->current_company_id, 403);

        if ($payrollRun->isApproved()) {
            $data = $request->validate([
                'force' => ['required', 'accepted'],
                'reason' => ['required', 'string', 'max:255'],
            ]);
            AuditLog::record('payroll_force_deleted', $payrollRun, [
                'reason' => ['old' => null, 'new' => $data['reason']],
            ]);
        }

        $payrollRun->forceFill(['deleted_by' => $request->user()->id])->save();
        $payrollRun->delete();

        return redirect()->route('payroll.index')
            ->with('success', $payrollRun->reference.' deleted.');
    }
}
