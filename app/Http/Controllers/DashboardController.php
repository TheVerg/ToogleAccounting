<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ReportService $reports): Response
    {
        $company = $request->user()->currentCompany;
        $ids = [$company->id];

        $monthStart = now()->startOfMonth()->toDateString();
        $today = now()->toDateString();
        $yearStart = now()->startOfYear()->toDateString();

        $plMonth = $reports->profitLoss($ids, $monthStart, $today);
        $plYear = $reports->profitLoss($ids, $yearStart, $today);

        return Inertia::render('Dashboard', [
            'moneyBalances' => $reports->moneyBalances($ids),
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
}
