<?php

namespace App\Services\Compliance;

use App\Models\Company;
use App\Models\ComplianceReturn;
use App\Models\PayrollRun;
use Illuminate\Support\Collection;

/**
 * PAYE P2 remittance working papers, built from approved payroll runs whose
 * pay date falls in the period — the remittance follows the money leaving the
 * business, not the period the work was done in.
 *
 * The figures are the same ones the P2 CSV export emits, aggregated by currency.
 */
class PayeP2Workspace implements ReturnWorkspace
{
    public function key(): string
    {
        return 'PAYE-P2';
    }

    public function label(): string
    {
        return 'PAYE P2 working papers';
    }

    /**
     * Deliberately false. VAT7 already locks the period, and a second locking
     * return for the same month would mean whichever is approved first freezes
     * the other's ability to correct anything.
     */
    public function locksPeriodOnApproval(): bool
    {
        return false;
    }

    public function dueDateFollowsPeriod(): bool
    {
        return true;
    }

    public function instalments(): int
    {
        return 1;
    }

    public function build(Company $company, string $from, string $to, ?ComplianceReturn $return = null): array
    {
        // Explicit day boundaries: SQLite stores date columns as "Y-m-d 00:00:00",
        // so comparing against a bare "Y-m-d" upper bound drops the last day.
        // Range form (not whereDate) keeps the index usable on MariaDB.
        $runs = PayrollRun::where('company_id', $company->id)
            ->whereBetween('pay_date', [$from.' 00:00:00', $to.' 23:59:59'])
            ->with('items.employee:id,employee_no,tax_number,nssa_number,nssa_registered')
            ->orderBy('pay_date')->get();

        $items = $runs->flatMap->items;
        $exceptions = $this->exceptions($runs, $items);

        $rows = $items->groupBy(fn ($item) => strtoupper($item->currency ?: $company->currency))
            ->map(function (Collection $group, string $currency) {
                $paye = $this->sumLine($group, 'paye');
                $aids = $this->sumLine($group, 'aids_levy');

                return [
                    'currency' => $currency,
                    'employees' => $group->count(),
                    'gross' => round((float) $group->sum(fn ($item) => (float) $item->gross), 2),
                    'paye' => $paye,
                    'aids_levy' => $aids,
                    'total_tax' => round($paye + $aids, 2),
                    'nssa_employee' => $this->sumLine($group, 'nssa_employee'),
                    'nssa_employer' => $this->sumLine($group, 'nssa_employer'),
                ];
            })->sortKeys()->values()->all();

        $totalTax = round(collect($rows)->sum('total_tax'), 2);

        return [
            'type' => $this->key(),
            'label' => $this->label(),
            'generated_at' => now()->toIso8601String(),
            'period' => ['from' => $from, 'to' => $to],
            'columns' => [
                ['key' => 'currency', 'label' => 'Currency', 'align' => 'left', 'format' => 'text'],
                ['key' => 'employees', 'label' => 'Employees', 'align' => 'right', 'format' => 'number'],
                ['key' => 'gross', 'label' => 'Gross remuneration', 'align' => 'right', 'format' => 'money'],
                ['key' => 'paye', 'label' => 'PAYE', 'align' => 'right', 'format' => 'money'],
                ['key' => 'aids_levy', 'label' => 'AIDS levy', 'align' => 'right', 'format' => 'money'],
                ['key' => 'total_tax', 'label' => 'Total remittable', 'align' => 'right', 'format' => 'money', 'emphasis' => true],
                ['key' => 'nssa_employee', 'label' => 'NSSA employee', 'align' => 'right', 'format' => 'money'],
                ['key' => 'nssa_employer', 'label' => 'NSSA employer', 'align' => 'right', 'format' => 'money'],
            ],
            'rows' => $rows,
            'summary' => [
                ['label' => 'Payroll runs', 'value' => $runs->count(), 'format' => 'number'],
                ['label' => 'Payslips', 'value' => $items->count(), 'format' => 'number'],
                ['label' => 'Total remittable', 'value' => $totalTax, 'format' => 'money', 'currency' => $company->currency],
                ['label' => 'Exceptions', 'value' => count($exceptions), 'format' => 'number',
                    'tone' => $exceptions ? 'bad' : 'good'],
            ],
            'exceptions' => $exceptions,
        ];
    }

    public function evidenceCsv(array $snapshot): array
    {
        $keys = ['currency', 'employees', 'gross', 'paye', 'aids_levy', 'total_tax', 'nssa_employee', 'nssa_employer'];

        return [
            'name' => 'paye-p2-working-papers.csv',
            'headers' => ['Currency', 'Employees', 'Gross remuneration', 'PAYE', 'AIDS levy', 'Total remittable',
                'NSSA employee', 'NSSA employer'],
            'rows' => collect($snapshot['rows'] ?? [])
                ->map(fn ($row) => array_map(fn ($key) => $row[$key] ?? '', $keys))->all(),
        ];
    }

    protected function exceptions(Collection $runs, Collection $items): array
    {
        $exceptions = [];

        if ($runs->isEmpty()) {
            // A nil return is legitimate, so this warns rather than blocks.
            return [$this->exception('no_payroll_in_period', 'warning', 'PAYROLL',
                'No payroll runs were paid in this period. File a nil return only if that is correct.')];
        }

        foreach ($runs as $run) {
            if (! $run->isApproved()) {
                $exceptions[] = $this->exception('payroll_run_not_approved', 'blocking', $run->reference,
                    'Payroll run is still '.$run->status.'; its figures can change after filing.',
                    $run->total_gross, $run->currency);
            } elseif (! $run->journal_entry_id) {
                $exceptions[] = $this->exception('payroll_run_not_posted', 'blocking', $run->reference,
                    'Approved payroll run has no journal entry, so the ledger does not carry this liability.',
                    $run->total_gross, $run->currency);
            }

            foreach ($run->compliance_warnings ?? [] as $warning) {
                $exceptions[] = $this->exception('payroll_statutory_warning', 'blocking', $run->reference,
                    $warning, $run->total_gross, $run->currency);
            }
        }

        foreach ($items as $item) {
            $paye = $this->sumLine(collect([$item]), 'paye');
            if ($paye > 0 && blank($item->employee?->tax_number)) {
                $exceptions[] = $this->exception('employee_tax_number_missing', 'warning',
                    $item->employee?->employee_no ?? $item->employee_name,
                    'Employee has PAYE but no tax number recorded; ZIMRA requires one on the P2 schedule.',
                    $paye, $item->currency);
            }
            if ($item->employee?->nssa_registered && blank($item->employee?->nssa_number)) {
                $exceptions[] = $this->exception('employee_nssa_number_missing', 'warning',
                    $item->employee?->employee_no ?? $item->employee_name,
                    'Employee is NSSA registered but has no NSSA number recorded.', 0, $item->currency);
            }
        }

        return $exceptions;
    }

    protected function sumLine(Collection $items, string $code): float
    {
        return round((float) $items->sum(
            fn ($item) => (float) (collect($item->lines)->firstWhere('code', $code)['amount'] ?? 0)
        ), 2);
    }

    protected function exception(string $type, string $severity, string $reference, string $message, float|string $amount = 0, ?string $currency = null): array
    {
        return compact('type', 'severity', 'reference', 'message', 'amount', 'currency');
    }
}
