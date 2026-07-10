<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Company;
use App\Models\CustomRatio;
use App\Models\RatioSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /** Subtypes eliminated when consolidating several companies. */
    public const INTERCOMPANY_SUBTYPES = [
        'intercompany', 'intercompany_investment', 'intercompany_capital',
    ];

    public const MONEY_SUBTYPES = ['cash', 'bank', 'petty_cash'];

    /**
     * Net movement per account over a period, keyed for consolidation by code.
     *
     * @param  int[]  $companyIds
     */
    protected function accountMovements(array $companyIds, ?string $from, ?string $to, bool $eliminate): Collection
    {
        $q = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->whereIn('je.status', ['posted', 'reversed'])
            ->whereIn('je.company_id', $companyIds)
            ->groupBy('a.code', 'a.name', 'a.type', 'a.subtype')
            ->select(
                'a.code', 'a.name', 'a.type', 'a.subtype',
                DB::raw('SUM(jl.debit) as debit'),
                DB::raw('SUM(jl.credit) as credit'),
                // Statement presentation metadata (MAX keeps grouping by code).
                DB::raw('MAX(a.statement_group) as statement_group'),
                DB::raw('MAX(a.statement_line) as statement_line'),
                DB::raw('MAX(a.balance_type) as balance_type'),
            )
            ->orderBy('a.code');

        if ($from) {
            $q->whereDate('je.date', '>=', $from);
        }
        if ($to) {
            $q->whereDate('je.date', '<=', $to);
        }
        if ($eliminate) {
            $q->where(function ($w) {
                $w->whereNull('a.subtype')
                    ->orWhereNotIn('a.subtype', self::INTERCOMPANY_SUBTYPES);
            });
        }

        return collect($q->get())->map(function ($r) {
            $r->debit = (float) $r->debit;
            $r->credit = (float) $r->credit;

            return $r;
        });
    }

    protected function shouldEliminate(array $companyIds): bool
    {
        return count($companyIds) > 1;
    }

    public function trialBalance(array $companyIds, ?string $from, ?string $to): array
    {
        $rows = $this->accountMovements($companyIds, $from, $to, $this->shouldEliminate($companyIds))
            ->map(function ($r) {
                $net = round($r->debit - $r->credit, 2);

                return [
                    'code' => $r->code,
                    'name' => $r->name,
                    'type' => $r->type,
                    'debit' => $net > 0 ? $net : 0,
                    'credit' => $net < 0 ? -$net : 0,
                ];
            })
            ->filter(fn ($r) => $r['debit'] != 0 || $r['credit'] != 0)
            ->values();

        return [
            'rows' => $rows,
            'total_debit' => round($rows->sum('debit'), 2),
            'total_credit' => round($rows->sum('credit'), 2),
        ];
    }

    public function profitLoss(array $companyIds, ?string $from, ?string $to, ?Company $layoutCompany = null): array
    {
        $rows = $this->accountMovements($companyIds, $from, $to, $this->shouldEliminate($companyIds));

        $income = $rows->where('type', 'income')
            ->map(fn ($r) => ['code' => $r->code, 'name' => $r->name,
                'amount' => round($r->credit - $r->debit, 2),
                'group' => $r->statement_group, 'line' => $r->statement_line])
            ->filter(fn ($r) => $r['amount'] != 0)->values();

        $expenses = $rows->where('type', 'expense')
            ->map(fn ($r) => ['code' => $r->code, 'name' => $r->name,
                'amount' => round($r->debit - $r->credit, 2),
                'group' => $r->statement_group, 'line' => $r->statement_line])
            ->filter(fn ($r) => $r['amount'] != 0)->values();

        $totalIncome = round($income->sum('amount'), 2);
        $totalExpenses = round($expenses->sum('amount'), 2);

        return [
            'income' => $income,
            'expenses' => $expenses,
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'net_profit' => round($totalIncome - $totalExpenses, 2),
            // Presentation structure computed from chart-of-accounts metadata.
            'groups' => $this->metadataGroups(['income' => $income->all(), 'expense' => $expenses->all()]),
        ];
    }

    /**
     * Group statement rows by the statement_group / statement_line tags
     * carried on each chart-of-accounts line item. Accounts sharing a
     * statement_line roll up into one presented line (e.g. "Cash and cash
     * equivalents" = cash on hand + bank). Contra accounts carry negative
     * ledger amounts, so they subtract from their group naturally.
     * Null when no account in scope is tagged.
     */
    protected function metadataGroups(array $rowsByType): ?array
    {
        $tagged = collect($rowsByType)->flatten(1)->contains(fn ($r) => ! empty($r['group']) || ! empty($r['line']));
        if (! $tagged) {
            return null;
        }

        $fallbackLabels = [
            'income' => 'Other Income', 'expense' => 'Other Expenses',
            'asset' => 'Other Assets', 'liability' => 'Other Liabilities', 'equity' => 'Other Equity',
        ];

        $groups = [];
        foreach ($rowsByType as $type => $rows) {
            foreach ($rows as $row) {
                $label = $row['group'] ?: ($fallbackLabels[$type] ?? 'Other');
                $key = $type.'|'.$label;

                $groups[$key] ??= [
                    'label' => $label, 'account_type' => $type,
                    'rows' => [], 'lines' => [], 'total' => 0.0, 'minCode' => $row['code'],
                ];
                $groups[$key]['total'] = round($groups[$key]['total'] + $row['amount'], 2);
                $groups[$key]['minCode'] = min($groups[$key]['minCode'], $row['code']);

                if (! empty($row['line'])) {
                    $line = &$groups[$key]['lines'][$row['line']];
                    $line['amount'] = round(($line['amount'] ?? 0) + $row['amount'], 2);
                    $line['codes'][] = $row['code'];
                    $line['children'][] = ['code' => $row['code'], 'name' => $row['name'], 'amount' => $row['amount']];
                    unset($line);
                } else {
                    $groups[$key]['rows'][] = ['code' => $row['code'], 'name' => $row['name'], 'amount' => $row['amount']];
                }
            }
        }

        return collect($groups)
            ->map(function ($g) {
                foreach ($g['lines'] as $label => $line) {
                    sort($line['codes']);
                    $g['rows'][] = [
                        'code' => $line['codes'][0].(count($line['codes']) > 1 ? ' +'.(count($line['codes']) - 1) : ''),
                        'name' => $label,
                        'amount' => $line['amount'],
                        'rollup' => count($line['codes']),
                        'children' => collect($line['children'])->sortBy('code')->values()->all(),
                    ];
                }
                usort($g['rows'], fn ($a, $b) => strcmp($a['code'], $b['code']));

                return $g;
            })
            ->sortBy('minCode')
            ->map(fn ($g) => collect($g)->except(['lines', 'minCode'])->all())
            ->values()
            ->all();
    }

    public function balanceSheet(array $companyIds, string $asOf, ?Company $layoutCompany = null): array
    {
        $rows = $this->accountMovements($companyIds, null, $asOf, $this->shouldEliminate($companyIds));

        $section = function (string $type, bool $debitNormal) use ($rows) {
            return $rows->where('type', $type)
                ->map(fn ($r) => ['code' => $r->code, 'name' => $r->name,
                    'amount' => round($debitNormal ? $r->debit - $r->credit : $r->credit - $r->debit, 2),
                    'group' => $r->statement_group, 'line' => $r->statement_line])
                ->filter(fn ($r) => $r['amount'] != 0)->values();
        };

        $assets = $section('asset', true);
        $liabilities = $section('liability', false);
        $equity = $section('equity', false);

        // Profit accumulated to date rolls into equity so the sheet balances.
        $income = $rows->where('type', 'income')->sum(fn ($r) => $r->credit - $r->debit);
        $expense = $rows->where('type', 'expense')->sum(fn ($r) => $r->debit - $r->credit);
        $earnings = round($income - $expense, 2);

        $totalAssets = round($assets->sum('amount'), 2);
        $totalLiabilities = round($liabilities->sum('amount'), 2);
        $totalEquity = round($equity->sum('amount') + $earnings, 2);

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'current_earnings' => $earnings,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity' => $totalEquity,
            'total_liabilities_equity' => round($totalLiabilities + $totalEquity, 2),
            'balanced' => abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.01,
            'groups' => $this->metadataGroups([
                'asset' => $assets->all(),
                'liability' => $liabilities->all(),
                'equity' => $equity->all(),
            ]),
        ];
    }

    /**
     * Direct-method cash flow: every posted line hitting a money account,
     * classified by the counterpart accounts on the same entry.
     */
    public function cashFlow(array $companyIds, ?string $from, ?string $to): array
    {
        $eliminate = $this->shouldEliminate($companyIds);

        $lines = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->whereIn('je.status', ['posted', 'reversed'])
            ->whereIn('je.company_id', $companyIds)
            ->when($from, fn ($q) => $q->whereDate('je.date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('je.date', '<=', $to))
            ->select('jl.*', 'je.id as entry_id', 'a.type as acc_type', 'a.subtype as acc_subtype')
            ->get();

        $byEntry = $lines->groupBy('entry_id');
        $sections = [
            'operating' => ['inflows' => 0.0, 'outflows' => 0.0],
            'investing' => ['inflows' => 0.0, 'outflows' => 0.0],
            'financing' => ['inflows' => 0.0, 'outflows' => 0.0],
        ];

        foreach ($byEntry as $entryLines) {
            $moneyLines = $entryLines->filter(fn ($l) => in_array($l->acc_subtype, self::MONEY_SUBTYPES, true));
            if ($moneyLines->isEmpty()) {
                continue;
            }
            $others = $entryLines->reject(fn ($l) => in_array($l->acc_subtype, self::MONEY_SUBTYPES, true));
            if ($others->isEmpty()) {
                continue; // pure transfer between money accounts
            }

            $net = round($moneyLines->sum('debit') - $moneyLines->sum('credit'), 2);
            if ($net == 0.0) {
                continue;
            }

            // Skip intercompany flows in consolidated view (they cancel out).
            $counterpart = $others->first();
            if ($eliminate && in_array($counterpart->acc_subtype, self::INTERCOMPANY_SUBTYPES, true)) {
                continue;
            }

            $section = match (true) {
                in_array($counterpart->acc_subtype, self::INTERCOMPANY_SUBTYPES, true) => 'financing',
                $counterpart->acc_type === 'equity' => 'financing',
                $counterpart->acc_subtype === 'loan' => 'financing',
                $counterpart->acc_subtype === 'fixed_asset' => 'investing',
                default => 'operating',
            };

            if ($net > 0) {
                $sections[$section]['inflows'] += $net;
            } else {
                $sections[$section]['outflows'] += -$net;
            }
        }

        $result = [];
        $netChange = 0.0;
        foreach ($sections as $name => $s) {
            $net = round($s['inflows'] - $s['outflows'], 2);
            $result[$name] = [
                'inflows' => round($s['inflows'], 2),
                'outflows' => round($s['outflows'], 2),
                'net' => $net,
            ];
            $netChange += $net;
        }

        // Opening balance = money account movement before the period.
        $opening = 0.0;
        if ($from) {
            $opening = (float) DB::table('journal_lines as jl')
                ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                ->join('accounts as a', 'a.id', '=', 'jl.account_id')
                ->whereIn('je.status', ['posted', 'reversed'])
                ->whereIn('je.company_id', $companyIds)
                ->whereIn('a.subtype', self::MONEY_SUBTYPES)
                ->whereDate('je.date', '<', $from)
                ->selectRaw('COALESCE(SUM(jl.debit - jl.credit), 0) as bal')
                ->value('bal');
        }

        return [
            'sections' => $result,
            'net_change' => round($netChange, 2),
            'opening_balance' => round($opening, 2),
            'closing_balance' => round($opening + $netChange, 2),
        ];
    }

    /**
     * Calendar-year monthly cash in/out trend for dashboard charting.
     *
     * @param  int[]  $companyIds
     */
    public function monthlyCashFlowTrend(array $companyIds, int $year): array
    {
        $start = Carbon::create($year, 1, 1)->startOfMonth();

        return collect(range(0, 11))->map(function (int $offset) use ($companyIds, $start) {
            $month = $start->copy()->addMonths($offset);
            $cashFlow = $this->cashFlow(
                $companyIds,
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            );

            $inflows = collect($cashFlow['sections'])->sum('inflows');
            $outflows = collect($cashFlow['sections'])->sum('outflows');

            return [
                'label' => $month->format('M'),
                'period' => $month->format('Y-m'),
                'inflows' => round($inflows, 2),
                'outflows' => round($outflows, 2),
                'net' => round($inflows - $outflows, 2),
                'closing_balance' => $cashFlow['closing_balance'],
            ];
        })->all();
    }

    public function budgetVsActual(Company $company, int $fiscalYear): array
    {
        $budget = Budget::with('lines.account')
            ->where('company_id', $company->id)
            ->where('fiscal_year', $fiscalYear)
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END")
            ->first();

        $actuals = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->whereIn('je.status', ['posted', 'reversed'])
            ->where('je.company_id', $company->id)
            ->whereIn('a.type', ['income', 'expense'])
            ->whereBetween('je.date', [$fiscalYear.'-01-01', $fiscalYear.'-12-31'])
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')
            ->select('a.id', 'a.code', 'a.name', 'a.type',
                DB::raw('SUM(jl.debit) as debit'), DB::raw('SUM(jl.credit) as credit'))
            ->get()
            ->keyBy('id');

        $rows = collect();
        $budgetByAccount = $budget
            ? $budget->lines->groupBy('account_id')->map(fn ($ls) => round($ls->sum('amount'), 2))
            : collect();

        $accountIds = $budgetByAccount->keys()->merge($actuals->keys())->unique();

        foreach ($accountIds as $accountId) {
            $account = $actuals->get($accountId)
                ?? optional($budget?->lines->firstWhere('account_id', $accountId))->account;
            if (! $account) {
                continue;
            }

            $type = $account->type;
            $actual = 0.0;
            if ($actuals->has($accountId)) {
                $a = $actuals[$accountId];
                $actual = $type === 'income'
                    ? round($a->credit - $a->debit, 2)
                    : round($a->debit - $a->credit, 2);
            }

            $budgeted = (float) ($budgetByAccount[$accountId] ?? 0);
            $rows->push([
                'code' => $account->code,
                'name' => $account->name,
                'type' => $type,
                'budget' => $budgeted,
                'actual' => $actual,
                'variance' => round($budgeted - $actual, 2),
            ]);
        }

        $rows = $rows->sortBy('code')->values();

        return [
            'budget_name' => $budget?->name,
            'fiscal_year' => $fiscalYear,
            'income' => $rows->where('type', 'income')->values(),
            'expenses' => $rows->where('type', 'expense')->values(),
        ];
    }

    /** Output tax charged on invoices vs input tax paid on expenses. */
    public function taxSummary(array $companyIds, ?string $from, ?string $to): array
    {
        $q = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->whereIn('je.status', ['posted', 'reversed'])
            ->whereIn('je.company_id', $companyIds)
            ->where('a.subtype', 'tax')
            ->where('a.type', 'liability')
            ->when($from, fn ($query) => $query->whereDate('je.date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('je.date', '<=', $to));

        $sums = $q->selectRaw('COALESCE(SUM(jl.credit),0) as output_tax, COALESCE(SUM(jl.debit),0) as input_tax')->first();

        $outputTax = round((float) $sums->output_tax, 2);
        $inputTax = round((float) $sums->input_tax, 2);

        return [
            'output_tax' => $outputTax,
            'input_tax' => $inputTax,
            'net_tax_due' => round($outputTax - $inputTax, 2),
        ];
    }

    /** Current balance of every money (cash/bank/petty cash) account. */
    public function moneyBalances(array $companyIds): Collection
    {
        return collect(DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->join('companies as c', 'c.id', '=', 'a.company_id')
            ->whereIn('je.status', ['posted', 'reversed'])
            ->whereIn('je.company_id', $companyIds)
            ->whereIn('a.subtype', self::MONEY_SUBTYPES)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.subtype', 'c.name')
            ->select('a.id', 'a.code', 'a.name', 'a.subtype', 'c.name as company_name',
                DB::raw('SUM(jl.debit - jl.credit) as balance'))
            ->orderBy('c.name')->orderBy('a.code')
            ->get())
            ->map(function ($r) {
                $r->balance = round((float) $r->balance, 2);

                return $r;
            });
    }

    /**
     * Ratio catalogue with formulas, formats and DEFAULT target ranges.
     * Companies override the targets per ratio via ratio_settings
     * (Master Data > Ratio Benchmarks); null min/max = no bound.
     */
    public const RATIO_DEFINITIONS = [
        [
            'title' => 'Liquidity',
            'ratios' => [
                ['key' => 'current_ratio', 'label' => 'Current Ratio', 'format' => 'x',
                    'formula' => 'Current assets ÷ current liabilities', 'min' => 1.5, 'max' => 3.0],
                ['key' => 'quick_ratio', 'label' => 'Quick Ratio (acid test)', 'format' => 'x',
                    'formula' => '(Current assets − inventory) ÷ current liabilities', 'min' => 1.0, 'max' => null],
                ['key' => 'cash_ratio', 'label' => 'Cash Ratio', 'format' => 'x',
                    'formula' => 'Cash & bank ÷ current liabilities', 'min' => 0.5, 'max' => null],
                ['key' => 'working_capital', 'label' => 'Working Capital', 'format' => 'money',
                    'formula' => 'Current assets − current liabilities', 'min' => 0.0, 'max' => null],
            ],
        ],
        [
            'title' => 'Profitability',
            'ratios' => [
                ['key' => 'net_margin', 'label' => 'Net Profit Margin', 'format' => '%',
                    'formula' => 'Net profit ÷ income', 'min' => 10.0, 'max' => null],
                ['key' => 'expense_ratio', 'label' => 'Operating Expense Ratio', 'format' => '%',
                    'formula' => 'Expenses ÷ income', 'min' => null, 'max' => 90.0],
                ['key' => 'roa', 'label' => 'Return on Assets (ROA)', 'format' => '%',
                    'formula' => 'Net profit ÷ total assets', 'min' => 5.0, 'max' => null],
                ['key' => 'roe', 'label' => 'Return on Equity (ROE)', 'format' => '%',
                    'formula' => 'Net profit ÷ total equity', 'min' => 15.0, 'max' => null],
            ],
        ],
        [
            'title' => 'Leverage',
            'ratios' => [
                ['key' => 'debt_to_equity', 'label' => 'Debt-to-Equity', 'format' => 'x',
                    'formula' => 'Total liabilities ÷ total equity', 'min' => null, 'max' => 2.0],
                ['key' => 'debt_ratio', 'label' => 'Debt Ratio', 'format' => 'x',
                    'formula' => 'Total liabilities ÷ total assets', 'min' => null, 'max' => 0.6],
                ['key' => 'equity_ratio', 'label' => 'Equity Ratio', 'format' => 'x',
                    'formula' => 'Total equity ÷ total assets', 'min' => 0.4, 'max' => null],
            ],
        ],
        [
            'title' => 'Efficiency',
            'ratios' => [
                ['key' => 'receivables_turnover', 'label' => 'Receivables Turnover', 'format' => 'x',
                    'formula' => 'Income (period) ÷ accounts receivable', 'min' => null, 'max' => null],
                ['key' => 'dso', 'label' => 'Days Sales Outstanding', 'format' => 'days',
                    'formula' => 'Receivables ÷ (income ÷ days in period)', 'min' => null, 'max' => 45.0],
            ],
        ],
    ];

    /**
     * Figures custom ratios may be built from (key => display label).
     * Values are computed inside ratios() for the selected scope/period.
     */
    public const RATIO_FIGURES = [
        'total_assets' => 'Total assets',
        'current_assets' => 'Current assets',
        'inventory' => 'Inventory',
        'cash' => 'Cash & bank',
        'receivables' => 'Accounts receivable',
        'total_liabilities' => 'Total liabilities',
        'current_liabilities' => 'Current liabilities',
        'total_equity' => 'Total equity',
        'income' => 'Income (period)',
        'expenses' => 'Expenses (period)',
        'net_profit' => 'Net profit (period)',
    ];

    /** Effective target ranges for a company: overrides merged over defaults. */
    public function ratioTargets(?Company $company): array
    {
        $overrides = $company
            ? RatioSetting::where('company_id', $company->id)->get()->keyBy('key')
            : collect();

        $targets = [];
        foreach (self::RATIO_DEFINITIONS as $section) {
            foreach ($section['ratios'] as $def) {
                $override = $overrides->get($def['key']);
                $targets[$def['key']] = $override
                    ? ['min' => $override->target_min, 'max' => $override->target_max, 'overridden' => true]
                    : ['min' => $def['min'], 'max' => $def['max'], 'overridden' => false];
            }
        }

        return $targets;
    }

    /**
     * Key financial ratios: balance-sheet figures as of $to, profitability
     * over $from..$to. Ratios whose denominator is zero come back null.
     * Target ranges come from $settingsCompany's configured benchmarks.
     */
    public function ratios(array $companyIds, string $from, string $to, ?Company $settingsCompany = null): array
    {
        $rows = $this->accountMovements($companyIds, null, $to, $this->shouldEliminate($companyIds));

        $debitBalance = fn ($r) => $r->debit - $r->credit;
        $creditBalance = fn ($r) => $r->credit - $r->debit;

        $assets = $rows->where('type', 'asset');
        $liabilities = $rows->where('type', 'liability');

        $nonCurrentAssetSubtypes = ['fixed_asset', 'intercompany_investment'];
        $totalAssets = round($assets->sum($debitBalance), 2);
        $currentAssets = round($assets->reject(fn ($r) => in_array($r->subtype, $nonCurrentAssetSubtypes, true))->sum($debitBalance), 2);
        $inventory = round($assets->where('subtype', 'inventory')->sum($debitBalance), 2);
        $cash = round($assets->filter(fn ($r) => in_array($r->subtype, self::MONEY_SUBTYPES, true))->sum($debitBalance), 2);
        $receivables = round($assets->where('subtype', 'receivable')->sum($debitBalance), 2);

        // Long-term loans are the only non-current liabilities in the template.
        $totalLiabilities = round($liabilities->sum($creditBalance), 2);
        $currentLiabilities = round($liabilities->reject(fn ($r) => $r->subtype === 'loan')->sum($creditBalance), 2);

        $allTimeEarnings = round(
            $rows->where('type', 'income')->sum($creditBalance)
            - $rows->where('type', 'expense')->sum($debitBalance),
            2,
        );
        $totalEquity = round($rows->where('type', 'equity')->sum($creditBalance) + $allTimeEarnings, 2);

        $pl = $this->profitLoss($companyIds, $from, $to);
        $income = $pl['total_income'];
        $expenses = $pl['total_expenses'];
        $netProfit = $pl['net_profit'];

        $periodDays = max(1, Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1);

        $ratio = fn (float $num, float $den) => $den != 0.0 ? round($num / $den, 2) : null;
        $pct = fn (float $num, float $den) => $den != 0.0 ? round($num / $den * 100, 1) : null;

        $values = [
            'current_ratio' => $ratio($currentAssets, $currentLiabilities),
            'quick_ratio' => $ratio($currentAssets - $inventory, $currentLiabilities),
            'cash_ratio' => $ratio($cash, $currentLiabilities),
            'working_capital' => round($currentAssets - $currentLiabilities, 2),
            'net_margin' => $pct($netProfit, $income),
            'expense_ratio' => $pct($expenses, $income),
            'roa' => $pct($netProfit, $totalAssets),
            'roe' => $pct($netProfit, $totalEquity),
            'debt_to_equity' => $ratio($totalLiabilities, $totalEquity),
            'debt_ratio' => $ratio($totalLiabilities, $totalAssets),
            'equity_ratio' => $ratio($totalEquity, $totalAssets),
            'receivables_turnover' => $ratio($income, $receivables),
            'dso' => $income != 0.0 ? round($receivables / ($income / $periodDays)) : null,
        ];

        $targets = $this->ratioTargets($settingsCompany);

        $sections = collect(self::RATIO_DEFINITIONS)->map(fn ($section) => [
            'title' => $section['title'],
            'rows' => collect($section['ratios'])->map(function ($def) use ($values, $targets) {
                $value = $values[$def['key']];
                $min = $targets[$def['key']]['min'];
                $max = $targets[$def['key']]['max'];

                return [
                    'key' => $def['key'],
                    'label' => $def['label'],
                    'formula' => $def['formula'],
                    'format' => $def['format'],
                    'value' => $value,
                    'benchmark' => $this->benchmarkLabel($min, $max, $def['format']),
                    'within' => $value === null || ($min === null && $max === null)
                        ? null
                        : (($min === null || $value >= $min) && ($max === null || $value <= $max)),
                ];
            })->values()->all(),
        ])->values()->all();

        // The company's own ratios slot into their chosen section.
        if ($settingsCompany) {
            $figures = [
                'total_assets' => $totalAssets,
                'current_assets' => $currentAssets,
                'inventory' => $inventory,
                'cash' => $cash,
                'receivables' => $receivables,
                'total_liabilities' => $totalLiabilities,
                'current_liabilities' => $currentLiabilities,
                'total_equity' => $totalEquity,
                'income' => $income,
                'expenses' => $expenses,
                'net_profit' => $netProfit,
            ];

            $sectionTitles = [
                'liquidity' => 'Liquidity', 'profitability' => 'Profitability',
                'leverage' => 'Leverage', 'efficiency' => 'Efficiency', 'custom' => 'Custom',
            ];

            CustomRatio::where('company_id', $settingsCompany->id)
                ->orderBy('label')->get()
                ->each(function ($custom) use (&$sections, $figures, $sectionTitles) {
                    $num = $figures[$custom->numerator] ?? 0.0;
                    $den = $figures[$custom->denominator] ?? 0.0;
                    $value = $den != 0.0
                        ? ($custom->format === '%' ? round($num / $den * 100, 1) : round($num / $den, 2))
                        : null;
                    $min = $custom->target_min;
                    $max = $custom->target_max;

                    $row = [
                        'key' => 'custom_'.$custom->id,
                        'id' => $custom->id,
                        'custom' => true,
                        'label' => $custom->label,
                        'formula' => (self::RATIO_FIGURES[$custom->numerator] ?? $custom->numerator)
                            .' ÷ '.(self::RATIO_FIGURES[$custom->denominator] ?? $custom->denominator),
                        'format' => $custom->format,
                        'value' => $value,
                        'benchmark' => $this->benchmarkLabel($min, $max, $custom->format),
                        'within' => $value === null || ($min === null && $max === null)
                            ? null
                            : (($min === null || $value >= $min) && ($max === null || $value <= $max)),
                    ];

                    $title = $sectionTitles[$custom->section] ?? 'Custom';
                    foreach ($sections as $i => $section) {
                        if ($section['title'] === $title) {
                            $sections[$i]['rows'][] = $row;

                            return;
                        }
                    }
                    $sections[] = ['title' => $title, 'rows' => [$row]];
                });
        }

        return [
            'inputs' => [
                ['label' => 'Total assets', 'value' => $totalAssets],
                ['label' => 'Current assets', 'value' => $currentAssets],
                ['label' => 'Total liabilities', 'value' => $totalLiabilities],
                ['label' => 'Current liabilities', 'value' => $currentLiabilities],
                ['label' => 'Total equity', 'value' => $totalEquity],
                ['label' => 'Income (period)', 'value' => $income],
                ['label' => 'Expenses (period)', 'value' => $expenses],
                ['label' => 'Net profit (period)', 'value' => $netProfit],
            ],
            'sections' => $sections,
        ];
    }

    protected function benchmarkLabel(?float $min, ?float $max, string $format): ?string
    {
        if ($min === null && $max === null) {
            return null;
        }

        $fmt = fn (float $v) => match ($format) {
            '%' => rtrim(rtrim(number_format($v, 1), '0'), '.').'%',
            'days' => round($v).' days',
            'money' => number_format($v, 2),
            default => rtrim(rtrim(number_format($v, 2), '0'), '.'),
        };

        return match (true) {
            $min !== null && $max !== null => $fmt($min).' – '.$fmt($max),
            $min !== null => '≥ '.$fmt($min),
            default => '≤ '.$fmt($max),
        };
    }
}
