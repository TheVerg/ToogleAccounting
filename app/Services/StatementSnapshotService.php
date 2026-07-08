<?php

namespace App\Services;

use App\Models\Company;

/**
 * Renders a statement into a frozen, flat list of lines:
 *   { code, name, values: number[]|string[], depth, line_type }
 * line_type: section_header | line | account | subtotal | total | check
 * The viewer indents by depth and collapses under section headers.
 */
class StatementSnapshotService
{
    public function __construct(protected ReportService $reports) {}

    /** @return array{columns: string[], lines: array} */
    public function build(string $type, array $companyIds, ?string $from, string $to, Company $company): array
    {
        return match ($type) {
            'trial-balance' => $this->trialBalance($companyIds, $from, $to),
            'profit-loss' => $this->profitLoss($companyIds, $from, $to, $company),
            'balance-sheet' => $this->balanceSheet($companyIds, $to, $company),
            'cash-flow' => $this->cashFlow($companyIds, $from, $to),
            'tax-summary' => $this->taxSummary($companyIds, $from, $to),
            'ratios' => $this->ratios($companyIds, $from, $to, $company),
            'budget-vs-actual' => $this->budgetVsActual($company, (int) substr($to, 0, 4)),
        };
    }

    protected function budgetVsActual(Company $company, int $year): array
    {
        $r = $this->reports->budgetVsActual($company, $year);
        $lines = [];
        $totals = ['budget' => 0.0, 'actual' => 0.0];
        foreach (['income' => 'Income', 'expenses' => 'Expenses'] as $key => $label) {
            $lines[] = $this->line('', $label, [], 0, 'section_header');
            foreach ($r[$key] as $row) {
                $lines[] = $this->line($row['code'], $row['name'],
                    [$row['budget'], $row['actual'], $row['variance']], 1, 'account');
                $totals['budget'] = round($totals['budget'] + $row['budget'], 2);
                $totals['actual'] = round($totals['actual'] + $row['actual'], 2);
            }
        }
        $lines[] = $this->line('', 'TOTAL', [
            $totals['budget'], $totals['actual'], round($totals['budget'] - $totals['actual'], 2),
        ], 0, 'total');
        $lines[] = $this->line('', 'CALCULATION CHECK', [
            '', '', round($totals['budget'] - $totals['actual'] - round($totals['budget'] - $totals['actual'], 2), 2),
        ], 0, 'check');

        return ['columns' => ['Budget', 'Actual', 'Variance'], 'lines' => $lines];
    }

    protected function line(string $code, string $name, array $values, int $depth, string $type): array
    {
        return ['code' => $code, 'name' => $name, 'values' => $values, 'depth' => $depth, 'line_type' => $type];
    }

    /** Grouped rows (P&L / BS shape) -> flat lines. */
    protected function flattenGroups(array $groups): array
    {
        $lines = [];
        foreach ($groups as $group) {
            $lines[] = $this->line('', $group['label'], [], 0, 'section_header');
            foreach ($group['rows'] as $row) {
                $isRollup = ! empty($row['children']);
                $lines[] = $this->line($row['code'], $row['name'], [$isRollup ? '' : $row['amount']], 1, $isRollup ? 'line' : 'account');
                foreach ($row['children'] ?? [] as $child) {
                    $lines[] = $this->line($child['code'], $child['name'], [$child['amount']], 2, 'account');
                }
            }
            $lines[] = $this->line('', 'Total '.$group['label'], [$group['total']], 0, 'subtotal');
        }

        return $lines;
    }

    protected function trialBalance(array $ids, ?string $from, string $to): array
    {
        $r = $this->reports->trialBalance($ids, $from, $to);
        $lines = collect($r['rows'])
            ->map(fn ($x) => $this->line($x['code'], $x['name'], [$x['debit'], $x['credit']], 0, 'account'))
            ->all();
        $lines[] = $this->line('', 'TOTAL', [$r['total_debit'], $r['total_credit']], 0, 'total');
        $lines[] = $this->line('', 'BALANCE CHECK', [
            round($r['total_debit'] - $r['total_credit'], 2), '',
        ], 0, 'check');

        return ['columns' => ['Debit', 'Credit'], 'lines' => $lines];
    }

    protected function profitLoss(array $ids, ?string $from, string $to, Company $company): array
    {
        $r = $this->reports->profitLoss($ids, $from, $to, $company);
        $lines = $r['groups']
            ? $this->flattenGroups($r['groups'])
            : [
                ...collect($r['income'])->map(fn ($x) => $this->line($x['code'], $x['name'], [$x['amount']], 1, 'account')),
                $this->line('', 'Total income', [$r['total_income']], 0, 'subtotal'),
                ...collect($r['expenses'])->map(fn ($x) => $this->line($x['code'], $x['name'], [$x['amount']], 1, 'account')),
                $this->line('', 'Total expenses', [$r['total_expenses']], 0, 'subtotal'),
            ];
        $lines[] = $this->line('', 'NET '.($r['net_profit'] >= 0 ? 'PROFIT' : 'LOSS'), [$r['net_profit']], 0, 'total');
        $lines[] = $this->line('', 'CALCULATION CHECK', [
            round($r['total_income'] - $r['total_expenses'] - $r['net_profit'], 2),
        ], 0, 'check');

        return ['columns' => ['Amount'], 'lines' => $lines];
    }

    protected function balanceSheet(array $ids, string $to, Company $company): array
    {
        $r = $this->reports->balanceSheet($ids, $to, $company);
        if ($r['groups']) {
            $lines = $this->flattenGroups($r['groups']);
        } else {
            $lines = [];
            foreach ([['Assets', 'assets'], ['Liabilities', 'liabilities'], ['Equity', 'equity']] as [$label, $key]) {
                $lines[] = $this->line('', $label, [], 0, 'section_header');
                foreach ($r[$key] as $row) {
                    $lines[] = $this->line($row['code'], $row['name'], [$row['amount']], 1, 'account');
                }
            }
        }
        $lines[] = $this->line('', 'Current earnings', [$r['current_earnings']], 1, 'account');
        $lines[] = $this->line('', 'TOTAL ASSETS', [$r['total_assets']], 0, 'total');
        $lines[] = $this->line('', 'TOTAL LIABILITIES + EQUITY', [$r['total_liabilities_equity']], 0, 'total');
        $lines[] = $this->line('', 'BALANCE CHECK', [
            round($r['total_assets'] - $r['total_liabilities_equity'], 2),
        ], 0, 'check');

        return ['columns' => ['Amount'], 'lines' => $lines];
    }

    protected function cashFlow(array $ids, ?string $from, string $to): array
    {
        $r = $this->reports->cashFlow($ids, $from, $to);
        $lines = [];
        foreach ($r['sections'] as $key => $s) {
            $label = is_string($key) ? ucfirst($key) : ($s['label'] ?? 'Section');
            $in = (float) ($s['inflows'] ?? 0);
            $out = (float) ($s['outflows'] ?? 0);
            $lines[] = $this->line('', $label, [$in, $out, $s['net'] ?? round($in - $out, 2)], 0, 'line');
        }
        $lines[] = $this->line('', 'Opening balance', ['', '', $r['opening_balance']], 0, 'subtotal');
        $lines[] = $this->line('', 'Net change', ['', '', $r['net_change']], 0, 'subtotal');
        $lines[] = $this->line('', 'Closing balance', ['', '', $r['closing_balance']], 0, 'total');
        $lines[] = $this->line('', 'CASH FLOW CHECK', [
            '', '', round($r['opening_balance'] + $r['net_change'] - $r['closing_balance'], 2),
        ], 0, 'check');

        return ['columns' => ['Inflows', 'Outflows', 'Net'], 'lines' => $lines];
    }

    protected function taxSummary(array $ids, ?string $from, string $to): array
    {
        $r = $this->reports->taxSummary($ids, $from, $to);

        return ['columns' => ['Amount'], 'lines' => [
            $this->line('', 'Output tax', [$r['output_tax']], 0, 'account'),
            $this->line('', 'Input tax', [$r['input_tax']], 0, 'account'),
            $this->line('', 'Net tax due', [$r['net_tax_due']], 0, 'total'),
            $this->line('', 'CALCULATION CHECK', [
                round($r['output_tax'] - $r['input_tax'] - $r['net_tax_due'], 2),
            ], 0, 'check'),
        ]];
    }

    protected function ratios(array $ids, ?string $from, string $to, Company $company): array
    {
        $r = $this->reports->ratios($ids, $from ?? $to, $to, $company);
        $lines = [];
        foreach ($r['sections'] as $section) {
            $lines[] = $this->line('', $section['title'], [], 0, 'section_header');
            foreach ($section['rows'] as $row) {
                $value = $row['value'] === null ? 'n/a' : $row['value'].($row['format'] === '%' ? '%' : '');
                $status = $row['within'] === null ? '' : ($row['within'] ? 'Within target' : 'Outside target');
                $lines[] = $this->line('', $row['label'], [$value, $row['benchmark'] ?? '', $status], 1, 'account');
            }
        }
        $outsideTarget = collect($r['sections'])
            ->flatMap(fn ($section) => $section['rows'])
            ->filter(fn ($row) => $row['within'] === false)
            ->count();
        $lines[] = $this->line('', 'RATIO ALERT CHECK', ['', '', (string) $outsideTarget], 0, 'check');

        return ['columns' => ['Value', 'Target', 'Status'], 'lines' => $lines];
    }
}
