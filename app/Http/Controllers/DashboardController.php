<?php

namespace App\Http\Controllers;

use App\Models\JournalEntry;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ReportService $reports): Response
    {
        $company = $request->user()->currentCompany;
        $scopeOptions = $this->cashflowScopeOptions($company);
        $cashflowScope = (string) $request->query('cashflow_scope', $scopeOptions[0]['value']);
        $selectedScope = collect($scopeOptions)->firstWhere('value', $cashflowScope) ?? $scopeOptions[0];
        $ids = $selectedScope['company_ids'];

        $monthStart = now()->startOfMonth()->toDateString();
        $today = now()->toDateString();
        $yearStart = now()->startOfYear()->toDateString();
        $currentYear = (int) now()->year;
        $cashflowYear = (int) $request->query('cashflow_year', $currentYear);
        $cashflowYear = $cashflowYear >= 2000 && $cashflowYear <= 2100 ? $cashflowYear : $currentYear;

        $firstEntryDate = JournalEntry::whereIn('company_id', $ids)->min('date');
        $firstYear = $firstEntryDate ? (int) Carbon::parse($firstEntryDate)->year : $currentYear;
        $yearOptions = range(min($firstYear, $cashflowYear), max($currentYear, $cashflowYear));
        rsort($yearOptions);

        $currentCompanyIds = [$company->id];
        $plMonth = $reports->profitLoss($currentCompanyIds, $monthStart, $today);
        $plYear = $reports->profitLoss($currentCompanyIds, $yearStart, $today);
        $cashflowFrom = Carbon::create($cashflowYear, 1, 1)->toDateString();
        $cashflowTo = Carbon::create($cashflowYear, 12, 31)->toDateString();

        return Inertia::render('Dashboard', [
            'moneyBalances' => $reports->moneyBalances($currentCompanyIds),
            'cashflow' => $reports->cashFlow($ids, $cashflowFrom, $cashflowTo),
            'cashflowYear' => $cashflowYear,
            'cashflowYearOptions' => $yearOptions,
            'cashflowScope' => $selectedScope['value'],
            'cashflowScopeLabel' => $selectedScope['label'],
            'cashflowScopeOptions' => $scopeOptions,
            'month' => [
                'income' => $plMonth['total_income'],
                'expenses' => $plMonth['total_expenses'],
                'net' => $plMonth['net_profit'],
            ],
            'year' => [
                'income' => $plYear['total_income'],
                'expenses' => $plYear['total_expenses'],
                'net' => $plYear['net_profit'],
            ],
            'recentEntries' => $company->journalEntries()
                ->with('lines.account')
                ->latest('date')->latest('id')
                ->limit(8)->get(),
        ]);
    }

    protected function cashflowScopeOptions($company): array
    {
        $subsidiaries = $company->isHolding()
            ? $company->subsidiaries()->orderBy('name')->get(['id', 'name', 'type'])
            : collect();

        if (! $company->isHolding()) {
            return [[
                'value' => 'company_'.$company->id,
                'label' => $company->name,
                'type' => $company->type,
                'company_ids' => [$company->id],
            ]];
        }

        $companyOptions = collect([$company])
            ->merge($subsidiaries)
            ->map(fn ($c) => [
                'value' => 'company_'.$c->id,
                'label' => $c->name,
                'type' => $c->type,
                'company_ids' => [$c->id],
            ])
            ->values()
            ->all();

        return [
            [
                'value' => 'consolidated',
                'label' => $company->name.' consolidated',
                'type' => 'consolidated',
                'company_ids' => collect([$company->id])->merge($subsidiaries->pluck('id'))->values()->all(),
            ],
            ...$companyOptions,
        ];
    }
}
