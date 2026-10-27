<?php

namespace App\Http\Controllers;

use App\Models\PayrollRun;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayrollRemittanceController extends Controller
{
    public function paye(Request $request, PayrollRun $payrollRun): StreamedResponse
    {
        $this->guard($request, $payrollRun);
        return $this->csv("{$payrollRun->reference}-PAYE-P2.csv", ['Employee no', 'Employee', 'Tax number', 'Gross remuneration', 'Gross PAYE', 'AIDS levy', 'Total tax', 'Currency'],
            $payrollRun->items->map(function ($item) {
                $lines = collect($item->lines);
                $paye = (float) ($lines->firstWhere('code', 'paye')['amount'] ?? 0);
                $aids = (float) ($lines->firstWhere('code', 'aids_levy')['amount'] ?? 0);
                return [$item->employee?->employee_no, $item->employee_name, $item->employee?->tax_number, $item->gross, $paye, $aids, round($paye + $aids, 2), $item->currency];
            })->all());
    }

    public function nssa(Request $request, PayrollRun $payrollRun): StreamedResponse
    {
        $this->guard($request, $payrollRun);
        return $this->csv("{$payrollRun->reference}-NSSA.csv", ['Employee no', 'Employee', 'NSSA number', 'Gross remuneration', 'Employee POBS', 'Employer POBS', 'APWCS', 'Currency'],
            $payrollRun->items->map(function ($item) {
                $lines = collect($item->lines);
                $amount = fn ($code) => (float) ($lines->firstWhere('code', $code)['amount'] ?? 0);
                return [$item->employee?->employee_no, $item->employee_name, $item->employee?->nssa_number, $item->gross, $amount('nssa_employee'), $amount('nssa_employer'), $amount('nssa_apwcs'), $item->currency];
            })->all());
    }

    protected function guard(Request $request, PayrollRun $run): void
    {
        abort_unless($run->company_id === $request->user()->current_company_id, 403);
        $run->load('items.employee');
    }

    protected function csv(string $name, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w'); fputcsv($out, $headers);
            foreach ($rows as $row) fputcsv($out, $row);
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }
}
