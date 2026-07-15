<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanySwitchController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DividendController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeLoanController;
use App\Http\Controllers\EquityController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ExpenseExportController;
use App\Http\Controllers\IntercompanyController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceExportController;
use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\JournalEntryController;
use App\Http\Controllers\OpeningBalanceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentExportController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PayrollPayslipController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RatioBenchmarkController;
use App\Http\Controllers\RecurringExpenseController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ShareholderController;
use App\Http\Controllers\ShareholderLoanController;
use App\Http\Controllers\StatementExportController;
use App\Http\Controllers\StatementImportController;
use App\Http\Controllers\StatementRegisterController;
use App\Http\Controllers\TaxRateController;
use App\Http\Controllers\TransactionOmitController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

Route::middleware(['auth', 'company'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::post('/switch-company', CompanySwitchController::class)->name('company.switch');
    Route::get('/attachments/{attachment}/preview', [AttachmentController::class, 'preview'])->name('attachments.preview');
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'download'])->name('attachments.download');

    // Chart of accounts (categories)
    Route::middleware('permission:accounts.view')->group(function () {
        Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
    });
    Route::middleware('permission:accounts.manage')->group(function () {
        Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::put('/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
        Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');
    });

    // Journal
    Route::get('/journal', [JournalEntryController::class, 'index'])
        ->middleware('permission:journals.view')->name('journal.index');
    Route::post('/journal', [JournalEntryController::class, 'store'])
        ->middleware('permission:journals.create')->name('journal.store');
    Route::post('/journal/{entry}/reverse', [JournalEntryController::class, 'reverse'])
        ->middleware('permission:journals.reverse')->name('journal.reverse');
    Route::delete('/journal/{entry}/omit', [JournalEntryController::class, 'omit'])->name('journal.omit');

    // Omit transactions from their source areas (system admin only, enforced in controller)
    Route::delete('/income/invoices/{invoice}/omit', [TransactionOmitController::class, 'invoice'])->name('invoices.omit');
    Route::delete('/expenses/{expense}/omit', [TransactionOmitController::class, 'expense'])->name('expenses.omit');
    Route::delete('/payments/{payment}/omit', [TransactionOmitController::class, 'payment'])->name('payments.omit');
    Route::delete('/equity/{equityTransaction}/omit', [TransactionOmitController::class, 'equity'])->name('equity.omit');
    Route::delete('/shareholder-loans/{shareholderLoan}/omit', [TransactionOmitController::class, 'shareholderLoan'])->name('shareholder-loans.omit');
    Route::delete('/shareholder-loans/repayments/{repayment}/omit', [TransactionOmitController::class, 'shareholderLoanRepayment'])->name('shareholder-loans.repayments.omit');
    Route::delete('/equity/dividends/{dividend}/omit', [TransactionOmitController::class, 'dividend'])->name('dividends.omit');
    Route::delete('/equity/dividends/payments/{dividendPayment}/omit', [TransactionOmitController::class, 'dividendPayment'])->name('dividends.payments.omit');

    // Contacts
    Route::get('/contacts', [ContactController::class, 'index'])
        ->middleware('permission:contacts.view')->name('contacts.index');
    Route::post('/contacts', [ContactController::class, 'store'])
        ->middleware('permission:contacts.manage')->name('contacts.store');
    Route::put('/contacts/{contact}', [ContactController::class, 'update'])
        ->middleware('permission:contacts.manage')->name('contacts.update');
    Route::delete('/contacts/{contact}', [ContactController::class, 'destroy'])
        ->middleware('permission:contacts.manage')->name('contacts.destroy');
    Route::get('/contacts/import/template', [ContactController::class, 'importTemplate'])
        ->middleware('permission:contacts.manage')->name('contacts.import.template');
    Route::post('/contacts/import', [ContactController::class, 'import'])
        ->middleware('permission:contacts.manage')->name('contacts.import');

    // Income / revenue
    Route::get('/income/invoices', [InvoiceController::class, 'index'])
        ->middleware('permission:invoices.view')->name('invoices.index');
    Route::get('/income/invoices/export', InvoiceExportController::class)
        ->middleware('permission:invoices.view')->name('invoices.export');
    Route::get('/income/invoices/{invoice}/pdf', InvoicePdfController::class)
        ->middleware('permission:invoices.view')->name('invoices.pdf');
    Route::middleware('permission:invoices.manage')->group(function () {
        Route::post('/income/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
        Route::post('/income/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
        Route::post('/income/invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])->name('invoices.payments.store');
    });

    // Payments
    Route::get('/payments', [PaymentController::class, 'index'])
        ->middleware('permission:payments.view')->name('payments.index');
    Route::get('/payments/export', PaymentExportController::class)
        ->middleware('permission:payments.view')->name('payments.export');
    Route::post('/payments', [PaymentController::class, 'store'])
        ->middleware('permission:payments.manage')->name('payments.store');

    // Expenses (one-off, petty cash, recurring)
    Route::get('/expenses', [ExpenseController::class, 'index'])
        ->middleware('permission:expenses.view')->name('expenses.index');
    Route::get('/expenses/export', ExpenseExportController::class)
        ->middleware('permission:expenses.view')->name('expenses.export');
    Route::post('/expenses', [ExpenseController::class, 'store'])
        ->middleware('permission:expenses.manage')->name('expenses.store');
    Route::get('/expenses/recurring', [RecurringExpenseController::class, 'index'])
        ->middleware('permission:expenses.view')->name('expenses.recurring.index');
    Route::middleware('permission:expenses.manage')->group(function () {
        Route::post('/expenses/recurring', [RecurringExpenseController::class, 'store'])->name('expenses.recurring.store');
        Route::put('/expenses/recurring/{recurringExpense}', [RecurringExpenseController::class, 'update'])->name('expenses.recurring.update');
        Route::post('/expenses/recurring/run-due', [RecurringExpenseController::class, 'runDue'])->name('expenses.recurring.run');
    });

    // Employees
    Route::get('/employees', [EmployeeController::class, 'index'])
        ->middleware('permission:payroll.view')->name('employees.index');
    Route::get('/employees/{employee}', [EmployeeController::class, 'show'])
        ->middleware('permission:payroll.view')->name('employees.show');
    Route::middleware('permission:payroll.manage')->group(function () {
        Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
        Route::post('/employees/{employee}/assignments', [EmployeeController::class, 'storeAssignment'])->name('employees.assignments.store');
        Route::put('/employees/{employee}/assignments/{assignment}', [EmployeeController::class, 'updateAssignment'])->name('employees.assignments.update');
        Route::delete('/employees/{employee}/assignments/{assignment}', [EmployeeController::class, 'destroyAssignment'])->name('employees.assignments.destroy');
        Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])->name('employees.destroy');
        Route::post('/employees/{employee}/contract', [EmployeeController::class, 'storeContract'])->name('employees.contract.store');
        Route::post('/employees/{employee}/components', [EmployeeController::class, 'storeComponent'])->name('employees.components.store');
        Route::delete('/employees/{employee}/components/{component}', [EmployeeController::class, 'destroyComponent'])->name('employees.components.destroy');
    });

    // Employee loans
    Route::get('/employee-loans', [EmployeeLoanController::class, 'index'])
        ->middleware('permission:payroll.view')->name('employee-loans.index');
    Route::post('/employee-loans', [EmployeeLoanController::class, 'store'])
        ->middleware('permission:payroll.manage')->name('employee-loans.store');

    // Payroll
    Route::get('/payroll', [PayrollController::class, 'index'])
        ->middleware('permission:payroll.view')->name('payroll.index');
    Route::get('/payroll/{payrollRun}', [PayrollController::class, 'show'])
        ->middleware('permission:payroll.view')->name('payroll.show');
    Route::get('/payroll/{payrollRun}/items/{payrollItem}/payslip', PayrollPayslipController::class)
        ->middleware('permission:payroll.view')->name('payroll.items.payslip');
    Route::middleware('permission:payroll.manage')->group(function () {
        Route::post('/payroll', [PayrollController::class, 'store'])->name('payroll.store');
        Route::post('/payroll/{payrollRun}/approve', [PayrollController::class, 'approve'])->name('payroll.approve');
        Route::delete('/payroll/{payrollRun}', [PayrollController::class, 'destroy'])->name('payroll.destroy');
    });

    // Owner's equity
    Route::get('/equity', [EquityController::class, 'index'])
        ->middleware('permission:equity.view')->name('equity.index');
    Route::post('/equity', [EquityController::class, 'store'])
        ->middleware('permission:equity.manage')->name('equity.store');

    // Dividends (tab of the Equity page)
    Route::get('/equity/dividends', [DividendController::class, 'index'])
        ->middleware('permission:equity.view')->name('dividends.index');
    Route::post('/equity/dividends', [DividendController::class, 'store'])
        ->middleware('permission:equity.manage')->name('dividends.store');
    Route::post('/equity/dividends/allocations/{allocation}/payments', [DividendController::class, 'storePayment'])
        ->middleware('permission:equity.manage')->name('dividends.payments.store');

    // Shareholder register (master data)
    Route::get('/shareholders', [ShareholderController::class, 'index'])
        ->middleware('permission:equity.view')->name('shareholders.index');
    Route::middleware('permission:equity.manage')->group(function () {
        Route::post('/shareholders', [ShareholderController::class, 'store'])->name('shareholders.store');
        Route::put('/shareholders/{shareholder}', [ShareholderController::class, 'update'])->name('shareholders.update');
        Route::delete('/shareholders/{shareholder}', [ShareholderController::class, 'destroy'])->name('shareholders.destroy');
    });

    // Shareholder loans (liability funding from shareholders)
    Route::get('/shareholder-loans', [ShareholderLoanController::class, 'index'])
        ->middleware('permission:equity.view')->name('shareholder-loans.index');
    Route::post('/shareholder-loans', [ShareholderLoanController::class, 'store'])
        ->middleware('permission:equity.manage')->name('shareholder-loans.store');
    Route::post('/shareholder-loans/{shareholderLoan}/repayments', [ShareholderLoanController::class, 'storeRepayment'])
        ->middleware('permission:equity.manage')->name('shareholder-loans.repayments.store');

    // Intercompany funding
    Route::get('/intercompany', [IntercompanyController::class, 'index'])
        ->middleware('permission:intercompany.manage')->name('intercompany.index');
    Route::post('/intercompany', [IntercompanyController::class, 'store'])
        ->middleware('permission:intercompany.manage')->name('intercompany.store');

    // Budgets
    Route::get('/budgets', [BudgetController::class, 'index'])
        ->middleware('permission:budgets.view')->name('budgets.index');
    Route::middleware('permission:budgets.manage')->group(function () {
        Route::post('/budgets', [BudgetController::class, 'store'])->name('budgets.store');
        Route::put('/budgets/{budget}/lines', [BudgetController::class, 'updateLines'])->name('budgets.lines.update');
        Route::delete('/budgets/{budget}', [BudgetController::class, 'destroy'])->name('budgets.destroy');
    });
    Route::get('/budgets/{budget}', [BudgetController::class, 'show'])
        ->middleware('permission:budgets.view')->name('budgets.show');

    // Ratio benchmarks (master data)
    Route::get('/settings/ratio-benchmarks', [RatioBenchmarkController::class, 'index'])
        ->middleware('permission:reports.view')->name('settings.ratios');
    Route::middleware('permission:companies.manage')->group(function () {
        Route::put('/settings/ratio-benchmarks', [RatioBenchmarkController::class, 'update'])->name('settings.ratios.update');
        Route::delete('/settings/ratio-benchmarks', [RatioBenchmarkController::class, 'reset'])->name('settings.ratios.reset');
        Route::post('/settings/ratio-benchmarks/custom', [RatioBenchmarkController::class, 'storeCustom'])->name('settings.ratios.custom.store');
        Route::delete('/settings/ratio-benchmarks/custom/{customRatio}', [RatioBenchmarkController::class, 'destroyCustom'])->name('settings.ratios.custom.destroy');
    });

    // Tax rates
    Route::get('/taxes/rates', [TaxRateController::class, 'index'])
        ->middleware('permission:taxes.manage')->name('taxes.rates.index');
    Route::post('/taxes/rates', [TaxRateController::class, 'store'])
        ->middleware('permission:taxes.manage')->name('taxes.rates.store');
    Route::put('/taxes/rates/{taxRate}', [TaxRateController::class, 'update'])
        ->middleware('permission:taxes.manage')->name('taxes.rates.update');
    Route::delete('/taxes/rates/{taxRate}', [TaxRateController::class, 'destroy'])
        ->middleware('permission:taxes.manage')->name('taxes.rates.destroy');

    // Statements
    Route::get('/analysis', [ReportController::class, 'analysis'])
        ->middleware('permission:reports.view')->name('reports.analysis');
    Route::get('/analysis/{type}', [ReportController::class, 'analysisView'])
        ->middleware('permission:reports.view')->name('reports.analysis.view');

    Route::middleware('permission:reports.view')->prefix('statements')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/register', [StatementRegisterController::class, 'register'])->name('statements.register');
        Route::post('/register', [StatementRegisterController::class, 'store'])->name('statements.register.store');
        Route::get('/import/template', [StatementImportController::class, 'template'])->name('statements.import.template');
        Route::post('/import', [StatementImportController::class, 'store'])->name('statements.import');
        Route::get('/register/{financialStatement}', [StatementRegisterController::class, 'show'])
            ->withTrashed()->name('statements.register.show');
        Route::get('/register/{financialStatement}/export', StatementExportController::class)
            ->name('statements.register.export');
        Route::middleware('permission:companies.manage')->group(function () {
            Route::post('/register/{financialStatement}/approve', [StatementRegisterController::class, 'approve'])->name('statements.register.approve');
            Route::delete('/register/{financialStatement}', [StatementRegisterController::class, 'destroy'])->name('statements.register.destroy');
        });
        Route::get('/budget-vs-actual', [ReportController::class, 'budgetVsActual'])->name('reports.budget-vs-actual');
        // Live ledger views (not registered snapshots).
        Route::get('/general-ledger', [ReportController::class, 'generalLedger'])->name('reports.general-ledger');
        Route::get('/cash-book', [ReportController::class, 'cashBook'])->name('reports.cash-book');
        // Statement type pages: period-based views of registered snapshots.
        Route::get('/{type}', [ReportController::class, 'view'])
            ->whereIn('type', ['trial-balance', 'profit-loss', 'balance-sheet', 'cash-flow', 'tax-summary', 'ratios'])
            ->name('reports.view');
    });

    // Companies (admin)
    Route::get('/companies', [CompanyController::class, 'index'])
        ->middleware('permission:companies.view')->name('companies.index');
    Route::post('/companies', [CompanyController::class, 'store'])
        ->middleware('permission:companies.manage')->name('companies.store');
    Route::put('/companies/{company}', [CompanyController::class, 'update'])
        ->middleware('permission:companies.manage')->name('companies.update');
    Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])
        ->middleware('permission:companies.manage')->name('companies.destroy');

    // Opening balances (master data; system admin only, enforced in controller)
    Route::get('/settings/opening-balances', [OpeningBalanceController::class, 'index'])->name('settings.opening-balances.index');
    Route::post('/settings/opening-balances', [OpeningBalanceController::class, 'store'])->name('settings.opening-balances.store');

    // User administration (system admin)
    Route::middleware('permission:users.manage')->group(function () {
        Route::get('/admin/audit-logs', [AuditLogController::class, 'index'])->name('admin.audit-logs.index');
        Route::get('/admin/users', [UserController::class, 'index'])->name('admin.users.index');
        Route::post('/admin/users', [UserController::class, 'store'])->name('admin.users.store');
        Route::put('/admin/users/{user}', [UserController::class, 'update'])->name('admin.users.update');
        Route::post('/admin/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('admin.users.password.reset');
        Route::delete('/admin/users/{user}', [UserController::class, 'destroy'])->name('admin.users.destroy');
    });

    // Permissions section (system admin)
    Route::middleware('permission:roles.manage')->group(function () {
        Route::get('/admin/roles', [RoleController::class, 'index'])->name('admin.roles.index');
        Route::post('/admin/roles', [RoleController::class, 'store'])->name('admin.roles.store');
        Route::put('/admin/roles/{role}', [RoleController::class, 'update'])->name('admin.roles.update');
        Route::delete('/admin/roles/{role}', [RoleController::class, 'destroy'])->name('admin.roles.destroy');
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
