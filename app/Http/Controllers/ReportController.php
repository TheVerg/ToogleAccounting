<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\FinancialStatement;
use App\Services\ReportService;
use App\Services\AnalysisService;
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
    public function __construct(protected ReportService $reports, protected AnalysisService $analysisReports) {}

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
                ['name' => 'General Ledger', 'description' => 'Account-by-account listing of every posting with a running balance (live).', 'href' => '/statements/general-ledger'],
                ['name' => 'Cash Book', 'description' => 'Receipts and payments through each cash and bank account, with running balance (live).', 'href' => '/statements/cash-book'],
                ['name' => 'Budget vs Actual', 'description' => 'Fiscal-year budget compared with posted actuals (live analysis).', 'href' => '/statements/budget-vs-actual'],
                ['name' => 'Tax Summary', 'description' => 'Output tax, input tax and net tax due for the period.', 'href' => '/statements/tax-summary'],
                ['name' => 'Financial Ratios', 'description' => 'Liquidity, profitability, leverage and efficiency against your targets.', 'href' => '/statements/ratios'],
            ],
        ]);
    }

    /** Landing page for operational and management analysis reports. */
    public function analysis(): Response
    {
        return Inertia::render('Reports/Analysis', [
            'reports' => [
                ['name' => 'Accounts Receivable Ageing', 'description' => 'Review unpaid customer balances by current, 30, 60, 90 and 90+ day ageing bands.', 'icon' => 'receivables', 'href' => '/analysis/receivables-ageing'],
                ['name' => 'Revenue Analysis', 'description' => 'Analyse revenue trends by period, customer, company and income account.', 'icon' => 'revenue', 'href' => '/analysis/revenue'],
                ['name' => 'Expense Analysis', 'description' => 'Understand spending by period, supplier, category, department and company.', 'icon' => 'expenses', 'href' => '/analysis/expenses'],
                ['name' => 'Payroll Summary', 'description' => 'Compare basic pay, earnings, deductions, gross pay and net pay across payroll periods.', 'icon' => 'payroll', 'href' => '/analysis/payroll'],
                ['name' => 'Employee Establishment and Contract Expiry', 'description' => 'Monitor headcount, assignments, departments and contracts approaching expiry.', 'icon' => 'employees', 'href' => '/analysis/employee-establishment'],
                ['name' => 'Subsidiary Performance', 'description' => 'Compare the financial performance and contribution of companies across the group.', 'icon' => 'subsidiaries', 'href' => '/analysis/subsidiary-performance'],
                ['name' => 'Intercompany Reconciliation', 'description' => 'Identify and reconcile transfers and balances recorded between related companies.', 'icon' => 'intercompany', 'href' => '/analysis/intercompany-reconciliation'],
                ['name' => 'Monthly Management Accounts Pack', 'description' => 'Prepare a consolidated monthly view of performance, position, cash, budgets and ratios.', 'icon' => 'management', 'href' => '/analysis/management-accounts'],
            ],
        ]);
    }

    public function analysisView(Request $request, string $type): Response
    {
        $types = ['receivables-ageing', 'revenue', 'expenses', 'payroll', 'employee-establishment', 'subsidiary-performance', 'intercompany-reconciliation', 'management-accounts'];
        abort_unless(in_array($type, $types, true), 404);

        $from = $request->date('from')?->toDateString() ?? now()->startOfMonth()->toDateString();
        $to = $request->date('to')?->toDateString() ?? now()->toDateString();
        abort_if($from > $to, 422, 'The start date must be before the end date.');
        $company = $request->user()->currentCompany;

        return Inertia::render('Reports/AnalysisView', [
            'report' => $this->analysisReports->generate($type, $this->reportingCompanyIds($request), $from, $to, $company),
            'filters' => compact('from', 'to'),
            'type' => $type,
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

        $companyIds = $this->reportingCompanyIds($request);

        $available = FinancialStatement::whereIn('company_id', $companyIds)
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
                ? FinancialStatement::with(['company:id,name,parent_id', 'company.parent:id,name', 'generator:id,name', 'approver:id,name'])->find($selected->id)
                : null,
            'canApprove' => $request->user()->can('companies.manage'),
        ]);
    }

    /**
     * General ledger: live per-account listing of every posting with a
     * running balance, for the current company.
     */
    public function generalLedger(Request $request): Response
    {
        $company = $request->user()->currentCompany;
        [$from, $to] = $this->dates($request);
        abort_if($from > $to, 422, 'The start date must be before the end date.');

        $accounts = Account::where('company_id', $company->id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'subtype', 'balance_type', 'currency']);

        $accountId = (int) $request->query('account', 0);
        $account = ($accountId ? $accounts->firstWhere('id', $accountId) : null) ?? $accounts->first();

        return Inertia::render('Reports/GeneralLedger', [
            'accounts' => $accounts->map->only(['id', 'code', 'name', 'type', 'currency']),
            'report' => $account
                ? $this->reports->generalLedger([$company->id], $account, $from, $to)
                : null,
            'filters' => ['account' => $account?->id, 'from' => $from, 'to' => $to],
            'scopeLabel' => $company->name,
            'months' => $this->reports->ledgerMonths($company),
            'lockedBefore' => $company->locked_before?->toDateString(),
        ]);
    }

    /**
     * Cash book: the general ledger of a cash/bank/petty-cash account
     * presented as receipts and payments with a running balance.
     */
    public function cashBook(Request $request): Response
    {
        $company = $request->user()->currentCompany;
        [$from, $to] = $this->dates($request);
        abort_if($from > $to, 422, 'The start date must be before the end date.');

        $moneyAccounts = Account::where('company_id', $company->id)
            ->whereIn('subtype', Account::MONEY_SUBTYPES)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'subtype', 'type', 'balance_type', 'currency']);

        $accountId = (int) $request->query('account', 0);
        $account = ($accountId ? $moneyAccounts->firstWhere('id', $accountId) : null) ?? $moneyAccounts->first();

        return Inertia::render('Reports/CashBook', [
            'moneyAccounts' => $moneyAccounts->map->only(['id', 'code', 'name', 'subtype', 'currency']),
            'report' => $account
                ? $this->reports->generalLedger([$company->id], $account, $from, $to)
                : null,
            'filters' => ['account' => $account?->id, 'from' => $from, 'to' => $to],
            'scopeLabel' => $company->name,
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
