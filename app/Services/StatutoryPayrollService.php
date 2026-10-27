<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PayrollStatutoryRate;
use App\Models\PayrollTaxBand;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class StatutoryPayrollService
{
    public function calculate(Employee $employee, float $taxableIncome, CarbonInterface $payDate, string $frequency, string $currency): array
    {
        $currency = strtoupper($currency);
        $tenantId = $employee->company?->tenant_id;
        $warnings = [];
        $lines = [];
        $nssaLines = [];
        $employerCosts = [];

        // NSSA is resolved first: the employee POBS contribution is an
        // allowable deduction against PAYE, so the tax table must be applied
        // to remuneration net of it, not to gross.
        $nssaEmployee = 0.0;
        if ($employee->nssa_registered) {
            $nssa = $this->rate('nssa_pobs', $currency, $payDate, $tenantId);
            if (! $nssa) {
                $warnings[] = "No approved NSSA POBS rate for {$currency}; NSSA was not calculated.";
            } else {
                $insurable = min($taxableIncome, $nssa->ceiling === null ? $taxableIncome : (float) $nssa->ceiling);
                $nssaEmployee = round($insurable * (float) $nssa->employee_rate, 2);
                $employerAmount = round($insurable * (float) $nssa->employer_rate, 2);
                if ($nssaEmployee > 0) $nssaLines[] = $this->line('nssa_employee', 'NSSA POBS - employee', $nssaEmployee);
                if ($employerAmount > 0) $employerCosts[] = $this->employerLine('nssa_employer', 'NSSA POBS - employer', $employerAmount);
            }
        }

        $bands = $this->bands($currency, $frequency, $payDate, $tenantId);

        if ($bands->isEmpty()) {
            $warnings[] = "No approved PAYE table for {$currency}/{$frequency} on {$payDate->toDateString()}; PAYE was not calculated.";
        } else {
            $paye = $this->paye(round(max(0, $taxableIncome - $nssaEmployee), 2), $bands);
            if ($paye > 0) {
                $lines[] = $this->line('paye', 'PAYE', $paye);
                $aids = $this->rate('aids_levy', $currency, $payDate, $tenantId);
                if ($aids) {
                    $lines[] = $this->line('aids_levy', 'AIDS Levy', round($paye * (float) $aids->employee_rate, 2));
                } else {
                    $warnings[] = 'No approved AIDS levy rate; levy was not calculated.';
                }
            }
        }

        $lines = array_merge($lines, $nssaLines);

        $apwcs = $this->rate('nssa_apwcs', $currency, $payDate, $tenantId);
        if ($apwcs && (float) $apwcs->employer_rate > 0) {
            $employerCosts[] = $this->employerLine('nssa_apwcs', 'NSSA APWCS', round($taxableIncome * (float) $apwcs->employer_rate, 2));
        }

        return compact('lines', 'employerCosts', 'warnings');
    }

    /**
     * ZIMRA publishes PAYE tables in two interchangeable forms. The marginal
     * form charges each slice of income at its own rate and is summed across
     * bands. The published "income × rate − deduction" form is a single-bracket
     * formula that must NOT be summed — the deduction already accounts for the
     * lower bands. A table is treated as published-form if any band carries a
     * deduction, so operators can transcribe ZIMRA's table verbatim instead of
     * converting it by hand.
     */
    public function paye(float $income, Collection $bands): float
    {
        $sorted = $bands->sortBy('lower_bound');

        if ($sorted->contains(fn ($band) => (float) $band->deduction != 0.0)) {
            $bracket = $sorted->last(fn ($band) => (float) $band->lower_bound < $income);

            return $bracket
                ? round(max(0, $income * (float) $bracket->rate - (float) $bracket->deduction), 2)
                : 0.0;
        }

        $tax = 0.0;
        foreach ($sorted as $band) {
            $lower = (float) $band->lower_bound;
            if ($income <= $lower) continue;
            $upper = $band->upper_bound === null ? $income : min($income, (float) $band->upper_bound);
            $tax += max(0, $upper - $lower) * (float) $band->rate;
        }

        return round(max(0, $tax), 2);
    }

    /**
     * The one PAYE table in force on $date. Several tables can legitimately
     * match at once — a superseded table is not required to carry an
     * effective_to — so exactly one is selected rather than summed: a tenant
     * override beats the shared national table, and the most recently
     * effective table beats older ones.
     */
    protected function bands(string $currency, string $frequency, CarbonInterface $date, ?int $tenantId): Collection
    {
        $rows = PayrollTaxBand::query()->visibleToTenant($tenantId)
            ->where('jurisdiction', 'ZW')->where('currency', $currency)
            ->where('frequency', $frequency)->where('is_approved', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->orderBy('lower_bound')->get();

        return $this->inForce($rows);
    }

    /**
     * The statutory rate in force for a code, under the same governance as
     * payroll: approved only, effective-dated, tenant override beats the shared
     * national row. Exposed so non-payroll callers (e.g. the QPD estimator's
     * corporate income tax rate) inherit the same discipline rather than
     * querying the table directly.
     */
    public function rateInForce(string $code, string $currency, CarbonInterface $date, ?int $tenantId = null): ?PayrollStatutoryRate
    {
        return $this->rate($code, $currency, $date, $tenantId);
    }

    protected function rate(string $code, string $currency, CarbonInterface $date, ?int $tenantId): ?PayrollStatutoryRate
    {
        $rows = PayrollStatutoryRate::query()->visibleToTenant($tenantId)
            ->where('jurisdiction', 'ZW')->where('code', $code)->where('is_approved', true)
            ->where(fn ($q) => $q->whereNull('currency')->orWhere('currency', $currency))
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->get();

        // A currency-specific rate is more precise than a currency-agnostic one.
        $scoped = $rows->whereNotNull('currency');

        return $this->inForce($scoped->isNotEmpty() ? $scoped : $rows)->first();
    }

    /**
     * Narrow candidate rules to the single set in force: tenant overrides win
     * over shared rows, then the latest effective_from wins over earlier ones.
     */
    protected function inForce(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }

        $owned = $rows->whereNotNull('tenant_id');
        $scope = $owned->isNotEmpty() ? $owned : $rows;

        $latest = $scope->max(fn ($row) => $row->effective_from->toDateString());

        return $scope->filter(fn ($row) => $row->effective_from->toDateString() === $latest)->values();
    }

    protected function line(string $code, string $name, float $amount): array
    {
        return ['kind' => 'deduction', 'code' => $code, 'name' => $name, 'amount' => $amount, 'source' => 'statutory'];
    }

    protected function employerLine(string $code, string $name, float $amount): array
    {
        return ['kind' => 'employer_cost', 'code' => $code, 'name' => $name, 'amount' => $amount, 'source' => 'statutory'];
    }
}
