<?php

namespace App\Http\Controllers;

use App\Models\EmployeeAssignment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PayrollPayslipController extends Controller
{
    public function __invoke(Request $request, PayrollRun $payrollRun, PayrollItem $payrollItem)
    {
        abort_unless($payrollRun->company_id === $request->user()->current_company_id, 403);
        abort_unless($payrollItem->payroll_run_id === $payrollRun->id, 404);

        $payrollRun->load(['company', 'moneyAccount:id,code,name', 'approver:id,name']);
        $payrollItem->load(['employee.user:id,name,email']);

        $employee = $payrollItem->employee;
        $assignment = $employee
            ? EmployeeAssignment::where('employee_id', $employee->id)
                ->where('company_id', $payrollRun->company_id)
                ->latest('is_active')
                ->latest('id')
                ->first()
            : null;

        $lines = collect($payrollItem->lines ?? []);
        $basicLine = $lines->first(fn ($line) => ($line['code'] ?? null) === 'basic');
        $earnings = $lines->where('kind', 'earning')->values();
        $deductions = $lines->where('kind', 'deduction')->values();
        $filename = 'payslip-'
            .$payrollRun->reference.'-'
            .Str::slug($payrollItem->employee_name ?: 'employee')
            .'.pdf';

        return Pdf::loadView('exports.payslip', [
            'run' => $payrollRun,
            'item' => $payrollItem,
            'employee' => $employee,
            'assignment' => $assignment,
            'basicLine' => $basicLine,
            'earnings' => $earnings,
            'deductions' => $deductions,
            'logo' => $this->logoDataUri(),
        ])->download($filename);
    }
}
