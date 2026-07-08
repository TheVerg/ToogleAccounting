<?php

namespace App\Http\Controllers;

use App\Models\FinancialStatement;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Financial statements are period-based registered documents: the type
 * pages display generated snapshots for a selected reporting period.
 * Calculation happens once, at generation time (StatementRegister);
 * these pages only open the saved, official view.
 */
class ReportController extends Controller
{
    public function __construct(protected ReportService $reports) {}

    /**
     * Resolve which companies a generation run covers.
     * scope=current (default) | consolidated | a specific company id.
     */
    protected function scope(Request $request): array
    {
        $user = $request->user();
        $scope = $request->query('scope', $request->input('scope', 'current'));

        if ($scope === 'consolidated') {
            $ids = $user->accessibleCompanies()->pluck('id')->all();

            return [$ids, 'Consolidated (all companies)'];
        }

        if (is_numeric($scope)) {
            $company = $user->accessibleCompanies()->firstWhere('id', (int) $scope);
            abort_unless($company, 403);

            return [[$company->id], $company->name];
        }

        $company = $user->currentCompany;

        return [[$company->id], $company->name];
    }

    protected function dates(Request $request): array
    {
        return [
            $request->query('from', now()->startOfYear()->toDateString()),
            $request->query('to', now()->toDateString()),
        ];
    }

    /** Landing page: the list of available statements. */
    public function index(): Response
    {
        return Inertia::render('Reports/Index', [
            'statements' => [
                ['name' => 'Trial Balance', 'description' => 'Every account with its net debit or credit balance.', 'href' => '/statements/trial-balance'],
                ['name' => 'Profit & Loss', 'description' => 'Income and expenses grouped per the chart of accounts, with drill-down.', 'href' => '/statements/profit-loss'],
                ['name' => 'Balance Sheet', 'description' => 'Assets, liabilities and equity as at a date, with drill-down.', 'href' => '/statements/balance-sheet'],
                ['name' => 'Cash Flow', 'description' => 'Direct-method cash movements: operating, investing, financing.', 'href' => '/statements/cash-flow'],
                ['name' => 'Budget vs Actual', 'description' => 'Fiscal-year budget compared with posted actuals (live analysis).', 'href' => '/statements/budget-vs-actual'],
                ['name' => 'Tax Summary', 'description' => 'Output tax, input tax and net tax due for the period.', 'href' => '/statements/tax-summary'],
                ['name' => 'Financial Ratios', 'description' => 'Liquidity, profitability, leverage and efficiency against your targets.', 'href' => '/statements/ratios'],
            ],
        ]);
    }

    /**
     * A statement type page: registered snapshots for this type with a
     * period selector. Defaults to the newest approved statement, falling
     * back to the newest draft.
     */
    public function view(Request $request, string $type): Response
    {
        abort_unless(array_key_exists($type, FinancialStatement::TYPES), 404);

        $companyId = $request->user()->current_company_id;

        $available = FinancialStatement::where('company_id', $companyId)
            ->where('type', $type)
            ->orderByDesc('period_to')->orderByDesc('id')
            ->get(['id', 'name', 'scope_label', 'period_from', 'period_to', 'status', 'approved_at']);

        $selectedId = (int) $request->query('statement', 0);
        $selected = $selectedId
            ? $available->firstWhere('id', $selectedId)
            : ($available->firstWhere('status', 'approved') ?? $available->first());

        return Inertia::render('Reports/StatementView', [
            'type' => $type,
            'typeLabel' => FinancialStatement::TYPES[$type],
            'available' => $available,
            'statement' => $selected
                ? FinancialStatement::with(['generator:id,name', 'approver:id,name'])->find($selected->id)
                : null,
            'canApprove' => $request->user()->can('companies.manage'),
        ]);
    }

    /** Budget vs Actual stays a live analysis (planning tool, not a registered document). */
    public function budgetVsActual(Request $request): Response
    {
        $company = $request->user()->currentCompany;
        $year = (int) $request->query('year', now()->year);

        return Inertia::render('Reports/BudgetVsActual', [
            'report' => $this->reports->budgetVsActual($company, $year),
            'scopeLabel' => $company->name,
            'filters' => ['year' => $year],
        ]);
    }
}
