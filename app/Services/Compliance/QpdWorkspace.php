<?php

namespace App\Services\Compliance;

use App\Models\Company;
use App\Models\ComplianceReturn;
use App\Services\ReportService;
use App\Services\StatutoryPayrollService;
use Illuminate\Support\Carbon;

/**
 * Quarterly Payment Date (QPD) estimator — provisional corporate income tax.
 *
 * A QPD is a payment on account of the *annual* liability, so the period is the
 * year of assessment and each of the four instalments is filed separately
 * (`compliance_returns.instalment`). The figures are an **estimate**: taxable
 * profit for a year that has not finished is annualised from actuals to date.
 * Every input is shown on the working paper so the preparer can judge it.
 *
 * Nothing is computed without an approved, effective-dated `corporate_tax` rate
 * — the same fail-closed discipline as PAYE bands. See docs/COMPLIANCE.md.
 */
class QpdWorkspace implements ReturnWorkspace
{
    /**
     * 10% / 25% / 30% / 35% of the estimated annual liability, due 25 Mar,
     * 25 Jun, 25 Sep and 20 Dec.
     *
     * This is a documented default, not validated production data — the
     * snapshot raises a warning saying so. Override per taxpayer by putting an
     * `instalments` array on the corporate_tax rate's `metadata`.
     */
    public const SCHEDULE = [
        1 => ['percent' => 0.10, 'month' => 3, 'day' => 25],
        2 => ['percent' => 0.25, 'month' => 6, 'day' => 25],
        3 => ['percent' => 0.30, 'month' => 9, 'day' => 25],
        4 => ['percent' => 0.35, 'month' => 12, 'day' => 20],
    ];

    public function __construct(
        protected ReportService $reports,
        protected StatutoryPayrollService $statutory,
    ) {}

    public function key(): string
    {
        return 'QPD';
    }

    public function label(): string
    {
        return 'QPD estimate';
    }

    /** An estimate must never freeze the books it is estimated from. */
    public function locksPeriodOnApproval(): bool
    {
        return false;
    }

    /** Instalment 1 falls due 25 March against a year ending 31 December. */
    public function dueDateFollowsPeriod(): bool
    {
        return false;
    }

    public function instalments(): int
    {
        return 4;
    }

    public function build(Company $company, string $from, string $to, ?ComplianceReturn $return = null): array
    {
        $periodFrom = Carbon::parse($from);
        $periodTo = Carbon::parse($to);
        $instalment = (int) ($return?->instalment ?: 1);
        $dueDate = $return?->due_date ?? $this->dueDate($periodTo, $instalment);

        $exceptions = [];
        $schedule = $this->schedule($company, $dueDate);
        $rate = $this->statutory->rateInForce('corporate_tax', $company->currency, $dueDate, $company->tenant_id);

        // Actuals to date, annualised. Estimating from a part-year is the whole
        // point of a QPD, so the elapsed fraction is stated, not hidden.
        $actualsTo = $dueDate->lt($periodTo) ? $dueDate->copy() : $periodTo->copy();
        // Whole calendar months, inclusive of both ends. Carbon's diffInMonths
        // returns a fraction, which would annualise from 3.77 months rather
        // than 3 and quietly understate every instalment.
        $monthsElapsed = $this->calendarMonths($periodFrom, $actualsTo);
        $monthsInPeriod = $this->calendarMonths($periodFrom, $periodTo);
        $actualProfit = round((float) $this->reports->profitLoss([$company->id], $from, $actualsTo->toDateString())['net_profit'], 2);
        $annualProfit = round($actualProfit / $monthsElapsed * $monthsInPeriod, 2);

        if (! $rate) {
            $exceptions[] = $this->exception('corporate_tax_rate_missing', 'blocking', 'CORPORATE TAX',
                'No approved corporate income tax rate is effective on '.$dueDate->toDateString()
                .'. Record it under Settings → Payroll Rules before estimating a QPD.');
        }
        $exceptions[] = $this->exception('qpd_schedule_unverified', 'warning', 'SCHEDULE',
            'Instalment schedule 10/25/30/35 is a documented default. Verify it against the current ZIMRA public notice.');
        if ($monthsElapsed < $monthsInPeriod) {
            $exceptions[] = $this->exception('estimate_annualised', 'warning', 'ESTIMATE',
                "Annualised from {$monthsElapsed} of {$monthsInPeriod} months of actuals. Revise if trading is seasonal or has changed.",
                $actualProfit, $company->currency);
        }
        if ($annualProfit <= 0) {
            $exceptions[] = $this->exception('estimated_loss', 'warning', 'ESTIMATE',
                'Actuals to date show no taxable profit, so every instalment estimates to nil.',
                $annualProfit, $company->currency);
        }

        $taxRate = $rate ? (float) ($rate->rate ?? 0) : 0.0;
        $annualTax = round(max(0, $annualProfit) * $taxRate, 2);

        $cumulative = 0.0;
        $rows = [];
        foreach ($schedule as $no => $line) {
            $cumulative += $line['percent'];
            $rows[] = [
                'instalment' => $no === $instalment ? "{$no}  ← this return" : (string) $no,
                'due_date' => $this->dueDate($periodTo, $no)->toDateString(),
                'percent' => round($line['percent'] * 100, 2).'%',
                'cumulative' => round($cumulative * 100, 2).'%',
                'tax_due' => round($annualTax * $line['percent'], 2),
                'currency' => $company->currency,
            ];
        }

        return [
            'type' => $this->key(),
            'label' => $this->label(),
            'generated_at' => now()->toIso8601String(),
            'period' => ['from' => $from, 'to' => $to],
            'columns' => [
                ['key' => 'instalment', 'label' => 'QPD', 'align' => 'left', 'format' => 'text'],
                ['key' => 'due_date', 'label' => 'Due', 'align' => 'left', 'format' => 'text'],
                ['key' => 'percent', 'label' => 'Share of annual', 'align' => 'right', 'format' => 'text'],
                ['key' => 'cumulative', 'label' => 'Cumulative', 'align' => 'right', 'format' => 'text'],
                ['key' => 'tax_due', 'label' => 'Estimated payment', 'align' => 'right', 'format' => 'money', 'emphasis' => true],
            ],
            'rows' => $rows,
            'summary' => [
                ['label' => 'Actual profit to date', 'value' => $actualProfit, 'format' => 'money', 'currency' => $company->currency],
                ['label' => 'Estimated annual profit', 'value' => $annualProfit, 'format' => 'money', 'currency' => $company->currency],
                ['label' => 'Corporate tax rate', 'value' => $rate ? round($taxRate * 100, 2).'%' : 'not set', 'format' => 'text',
                    'tone' => $rate ? 'neutral' : 'bad'],
                ['label' => "QPD {$instalment} payable", 'value' => round($annualTax * ($schedule[$instalment]['percent'] ?? 0), 2),
                    'format' => 'money', 'currency' => $company->currency],
            ],
            'exceptions' => $exceptions,
        ];
    }

    public function evidenceCsv(array $snapshot): array
    {
        $keys = ['instalment', 'due_date', 'percent', 'cumulative', 'tax_due'];

        return [
            'name' => 'qpd-estimate.csv',
            'headers' => ['QPD', 'Due', 'Share of annual', 'Cumulative', 'Estimated payment'],
            'rows' => collect($snapshot['rows'] ?? [])
                ->map(fn ($row) => array_map(fn ($key) => $row[$key] ?? '', $keys))->all(),
        ];
    }

    /** Per-taxpayer override on the rate's metadata, else the documented default. */
    protected function schedule(Company $company, Carbon $dueDate): array
    {
        $rate = $this->statutory->rateInForce('corporate_tax', $company->currency, $dueDate, $company->tenant_id);
        $override = $rate?->metadata['instalments'] ?? null;

        return is_array($override) && $override !== [] ? $override : self::SCHEDULE;
    }

    /** Whole calendar months spanned, counting both endpoints (Jan→Mar = 3). */
    protected function calendarMonths(Carbon $from, Carbon $to): int
    {
        return max(1, ($to->year - $from->year) * 12 + ($to->month - $from->month) + 1);
    }

    protected function dueDate(Carbon $periodTo, int $instalment): Carbon
    {
        $line = self::SCHEDULE[$instalment] ?? self::SCHEDULE[1];

        return Carbon::create($periodTo->year, $line['month'], $line['day'])->startOfDay();
    }

    protected function exception(string $type, string $severity, string $reference, string $message, float|string $amount = 0, ?string $currency = null): array
    {
        return compact('type', 'severity', 'reference', 'message', 'amount', 'currency');
    }
}
