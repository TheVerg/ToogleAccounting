<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Company;
use InvalidArgumentException;

/**
 * Statement import: CSV templates pre-filled with the expected line
 * items per statement type, and a parser that turns a filled template
 * back into a frozen snapshot (same flat-line contract as generation).
 * Integrity is validated on import (TB must balance, BS must balance).
 */
class StatementImportService
{
    /** @return array{filename: string, headers: string[], rows: array} */
    public function template(string $type, Company $company): array
    {
        $accounts = fn (array $types) => Account::where('company_id', $company->id)
            ->whereIn('type', $types)->where('is_active', true)->orderBy('code')->get();

        return match ($type) {
            'trial-balance' => [
                'filename' => 'trial-balance-template',
                'headers' => ['Code', 'Account', 'Debit', 'Credit'],
                'rows' => $accounts(Account::TYPES)
                    ->map(fn ($a) => [$a->code, $a->name, 0, 0])->all(),
            ],
            'profit-loss' => [
                'filename' => 'profit-loss-template',
                'headers' => ['Section', 'Code', 'Item', 'Amount'],
                'rows' => $accounts(['income', 'expense'])
                    ->map(fn ($a) => [$a->type === 'income' ? 'Income' : 'Expenses', $a->code, $a->name, 0])->all(),
            ],
            'balance-sheet' => [
                'filename' => 'balance-sheet-template',
                'headers' => ['Section', 'Code', 'Item', 'Amount'],
                'rows' => $accounts(['asset', 'liability', 'equity'])
                    ->map(fn ($a) => [
                        match ($a->type) {
                            'asset' => 'Assets', 'liability' => 'Liabilities', default => 'Equity',
                        }, $a->code, $a->name, 0,
                    ])
                    ->push(['Equity', '', 'Current earnings', 0])->all(),
            ],
            'cash-flow' => [
                'filename' => 'cash-flow-template',
                'headers' => ['Section', 'Inflows', 'Outflows'],
                'rows' => [['Operating', 0, 0], ['Investing', 0, 0], ['Financing', 0, 0], ['Opening balance', 0, 0]],
            ],
            'tax-summary' => [
                'filename' => 'tax-summary-template',
                'headers' => ['Item', 'Amount'],
                'rows' => [['Output tax', 0], ['Input tax', 0]],
            ],
            'budget-vs-actual' => [
                'filename' => 'budget-vs-actual-template',
                'headers' => ['Section', 'Code', 'Item', 'Budget', 'Actual'],
                'rows' => $accounts(['income', 'expense'])
                    ->map(fn ($a) => [$a->type === 'income' ? 'Income' : 'Expenses', $a->code, $a->name, 0, 0])->all(),
            ],
            default => throw new InvalidArgumentException('No template for this statement type.'),
        };
    }

    /**
     * Parse a filled template into snapshot columns + lines.
     *
     * @return array{columns: string[], lines: array}
     */
    public function parse(string $type, string $csv): array
    {
        $rows = array_values(array_filter(array_map('str_getcsv', preg_split('/\r\n|\r|\n/', trim($csv)))));
        if (count($rows) < 2) {
            throw new InvalidArgumentException('The file has no data rows.');
        }
        array_shift($rows); // header

        $num = fn ($v) => round((float) str_replace([',', ' '], '', (string) $v), 2);
        $line = fn (string $code, string $name, array $values, int $depth, string $lt) => compact('code', 'name', 'values', 'depth') + ['line_type' => $lt];

        return match ($type) {
            'trial-balance' => (function () use ($rows, $num, $line) {
                $lines = [];
                $debit = $credit = 0.0;
                foreach ($rows as $r) {
                    $d = $num($r[2] ?? 0);
                    $c = $num($r[3] ?? 0);
                    if ($d == 0.0 && $c == 0.0) {
                        continue;
                    }
                    $lines[] = $line($r[0] ?? '', $r[1] ?? '', [$d, $c], 0, 'account');
                    $debit = round($debit + $d, 2);
                    $credit = round($credit + $c, 2);
                }
                if (abs($debit - $credit) >= 0.01) {
                    throw new InvalidArgumentException("Imported trial balance does not balance (debits {$debit} vs credits {$credit}).");
                }
                $lines[] = $line('', 'TOTAL', [$debit, $credit], 0, 'total');

                return ['columns' => ['Debit', 'Credit'], 'lines' => $lines];
            })(),
            'profit-loss' => (function () use ($rows, $num, $line) {
                $sections = [];
                foreach ($rows as $r) {
                    $amount = $num($r[3] ?? 0);
                    if ($amount == 0.0) {
                        continue;
                    }
                    $sections[trim($r[0] ?? 'Other')][] = $line($r[1] ?? '', $r[2] ?? '', [$amount], 1, 'account');
                }
                $lines = [];
                $totals = [];
                foreach ($sections as $label => $items) {
                    $lines[] = $line('', $label, [], 0, 'section_header');
                    array_push($lines, ...$items);
                    $totals[$label] = round(array_sum(array_map(fn ($i) => $i['values'][0], $items)), 2);
                    $lines[] = $line('', 'Total '.strtolower($label), [$totals[$label]], 0, 'subtotal');
                }
                $net = round(($totals['Income'] ?? 0) - ($totals['Expenses'] ?? 0), 2);
                $lines[] = $line('', 'NET '.($net >= 0 ? 'PROFIT' : 'LOSS'), [$net], 0, 'total');

                return ['columns' => ['Amount'], 'lines' => $lines];
            })(),
            'balance-sheet' => (function () use ($rows, $num, $line) {
                $sections = [];
                foreach ($rows as $r) {
                    $amount = $num($r[3] ?? 0);
                    if ($amount == 0.0) {
                        continue;
                    }
                    $sections[trim($r[0] ?? 'Other')][] = $line($r[1] ?? '', $r[2] ?? '', [$amount], 1, 'account');
                }
                $lines = [];
                $totals = [];
                foreach ($sections as $label => $items) {
                    $lines[] = $line('', $label, [], 0, 'section_header');
                    array_push($lines, ...$items);
                    $totals[$label] = round(array_sum(array_map(fn ($i) => $i['values'][0], $items)), 2);
                    $lines[] = $line('', 'Total '.strtolower($label), [$totals[$label]], 0, 'subtotal');
                }
                $assets = $totals['Assets'] ?? 0;
                $liabEquity = round(($totals['Liabilitys'] ?? $totals['Liabilities'] ?? 0) + ($totals['Equity'] ?? 0), 2);
                if (abs($assets - $liabEquity) >= 0.01) {
                    throw new InvalidArgumentException("Imported balance sheet does not balance (assets {$assets} vs liabilities + equity {$liabEquity}).");
                }
                $lines[] = $line('', 'TOTAL ASSETS', [$assets], 0, 'total');
                $lines[] = $line('', 'TOTAL LIABILITIES + EQUITY', [$liabEquity], 0, 'total');

                return ['columns' => ['Amount'], 'lines' => $lines];
            })(),
            'cash-flow' => (function () use ($rows, $num, $line) {
                $lines = [];
                $net = 0.0;
                $opening = 0.0;
                foreach ($rows as $r) {
                    $label = trim($r[0] ?? '');
                    $in = $num($r[1] ?? 0);
                    $out = $num($r[2] ?? 0);
                    if (strcasecmp($label, 'Opening balance') === 0) {
                        $opening = $in != 0.0 ? $in : $num($r[1] ?? 0);

                        continue;
                    }
                    $lines[] = $line('', $label, [$in, $out, round($in - $out, 2)], 0, 'line');
                    $net = round($net + $in - $out, 2);
                }
                $lines[] = $line('', 'Opening balance', ['', '', $opening], 0, 'subtotal');
                $lines[] = $line('', 'Net change', ['', '', $net], 0, 'subtotal');
                $lines[] = $line('', 'Closing balance', ['', '', round($opening + $net, 2)], 0, 'total');

                return ['columns' => ['Inflows', 'Outflows', 'Net'], 'lines' => $lines];
            })(),
            'tax-summary' => (function () use ($rows, $num, $line) {
                $values = [];
                foreach ($rows as $r) {
                    $values[strtolower(trim($r[0] ?? ''))] = $num($r[1] ?? 0);
                }
                $output = $values['output tax'] ?? 0.0;
                $input = $values['input tax'] ?? 0.0;

                return ['columns' => ['Amount'], 'lines' => [
                    $line('', 'Output tax', [$output], 0, 'account'),
                    $line('', 'Input tax', [$input], 0, 'account'),
                    $line('', 'Net tax due', [round($output - $input, 2)], 0, 'total'),
                ]];
            })(),
            'budget-vs-actual' => (function () use ($rows, $num, $line) {
                $lines = [];
                $budget = $actual = 0.0;
                $section = null;
                foreach ($rows as $r) {
                    $b = $num($r[3] ?? 0);
                    $a = $num($r[4] ?? 0);
                    if ($b == 0.0 && $a == 0.0) {
                        continue;
                    }
                    if (($r[0] ?? '') !== $section) {
                        $section = $r[0];
                        $lines[] = $line('', $section, [], 0, 'section_header');
                    }
                    $lines[] = $line($r[1] ?? '', $r[2] ?? '', [$b, $a, round($b - $a, 2)], 1, 'account');
                    $budget = round($budget + $b, 2);
                    $actual = round($actual + $a, 2);
                }
                $lines[] = $line('', 'TOTAL', [$budget, $actual, round($budget - $actual, 2)], 0, 'total');

                return ['columns' => ['Budget', 'Actual', 'Variance'], 'lines' => $lines];
            })(),
            default => throw new InvalidArgumentException('This statement type cannot be imported.'),
        };
    }
}
