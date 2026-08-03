<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Company;

class ChartOfAccountsService
{
    /**
     * Default chart template applied to every new company so that
     * consolidated reports line up account-for-account.
     */
    public const TEMPLATE = [
        // Assets
        ['code' => '1000', 'name' => 'Cash on Hand', 'type' => 'asset', 'subtype' => 'cash', 'system' => true,
            'group' => 'Current Assets', 'line' => 'Cash and cash equivalents'],
        ['code' => '1010', 'name' => 'Bank Account', 'type' => 'asset', 'subtype' => 'bank', 'system' => true,
            'group' => 'Current Assets', 'line' => 'Cash and cash equivalents'],
        ['code' => '1020', 'name' => 'Petty Cash', 'type' => 'asset', 'subtype' => 'petty_cash', 'system' => true,
            'group' => 'Current Assets', 'line' => 'Cash and cash equivalents'],
        ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => 'asset', 'subtype' => 'receivable', 'system' => true,
            'group' => 'Current Assets', 'line' => 'Trade and other receivables'],
        ['code' => '1150', 'name' => 'Employee Loans Receivable', 'type' => 'asset', 'subtype' => 'employee_loan', 'system' => true,
            'group' => 'Current Assets', 'line' => 'Trade and other receivables'],
        ['code' => '1200', 'name' => 'Intercompany Receivable', 'type' => 'asset', 'subtype' => 'intercompany', 'system' => true,
            'group' => 'Current Assets', 'line' => 'Intercompany balances'],
        ['code' => '1300', 'name' => 'Investment in Subsidiaries', 'type' => 'asset', 'subtype' => 'intercompany_investment', 'system' => true,
            'group' => 'Non-current Assets'],
        ['code' => '1500', 'name' => 'Equipment', 'type' => 'asset', 'subtype' => 'fixed_asset',
            'group' => 'Non-current Assets', 'line' => 'Property, plant and equipment'],
        // Liabilities
        ['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability', 'subtype' => 'payable', 'system' => true,
            'group' => 'Current Liabilities', 'line' => 'Trade and other payables'],
        ['code' => '2100', 'name' => 'Tax Payable', 'type' => 'liability', 'subtype' => 'tax', 'system' => true,
            'group' => 'Current Liabilities'],
        ['code' => '2110', 'name' => 'Pension Payable', 'type' => 'liability', 'subtype' => 'pension', 'system' => true,
            'group' => 'Current Liabilities'],
        ['code' => '2120', 'name' => 'Payroll Deductions Payable', 'type' => 'liability', 'subtype' => 'payroll_deduction', 'system' => true,
            'group' => 'Current Liabilities'],
        ['code' => '2130', 'name' => 'Dividends Payable', 'type' => 'liability', 'subtype' => 'dividend_payable', 'system' => true,
            'group' => 'Current Liabilities'],
        ['code' => '2200', 'name' => 'Intercompany Payable', 'type' => 'liability', 'subtype' => 'intercompany', 'system' => true,
            'group' => 'Current Liabilities', 'line' => 'Intercompany balances'],
        ['code' => '2300', 'name' => 'Loans Payable', 'type' => 'liability', 'subtype' => 'loan',
            'group' => 'Non-current Liabilities'],
        ['code' => '2350', 'name' => 'Shareholder Loans', 'type' => 'liability', 'subtype' => 'shareholder_loan', 'system' => true,
            'group' => 'Non-current Liabilities'],
        // Equity
        ['code' => '3000', 'name' => "Owner's Capital", 'type' => 'equity', 'subtype' => 'owner_equity', 'system' => true,
            'group' => 'Equity'],
        ['code' => '3100', 'name' => "Owner's Drawings", 'type' => 'equity', 'subtype' => 'owner_drawings', 'system' => true,
            'group' => 'Equity', 'balance_type' => 'debit'], // contra-equity: subtracts
        ['code' => '3200', 'name' => 'Share Capital from Parent', 'type' => 'equity', 'subtype' => 'intercompany_capital', 'system' => true,
            'group' => 'Equity'],
        ['code' => '3300', 'name' => 'Share Capital', 'type' => 'equity', 'subtype' => 'share_capital', 'system' => true,
            'group' => 'Equity'],
        ['code' => '3400', 'name' => 'Share Premium', 'type' => 'equity', 'subtype' => 'share_premium', 'system' => true,
            'group' => 'Equity'],
        ['code' => '3900', 'name' => 'Retained Earnings', 'type' => 'equity', 'subtype' => 'retained_earnings', 'system' => true,
            'group' => 'Equity'],
        ['code' => '3950', 'name' => 'Dividends Declared', 'type' => 'equity', 'subtype' => 'dividends_declared', 'system' => true,
            'group' => 'Equity', 'balance_type' => 'debit'], // contra-equity: distributions reduce equity
        // Income
        ['code' => '4000', 'name' => 'Sales Revenue', 'type' => 'income', 'subtype' => 'sales', 'system' => true,
            'group' => 'Revenue'],
        ['code' => '4100', 'name' => 'Service Revenue', 'type' => 'income', 'subtype' => 'services',
            'group' => 'Revenue'],
        ['code' => '4900', 'name' => 'Other Income', 'type' => 'income', 'subtype' => 'other',
            'group' => 'Other Income'],
        // Expenses
        ['code' => '5000', 'name' => 'Rent Expense', 'type' => 'expense', 'subtype' => 'operating',
            'group' => 'Operating Expenses'],
        ['code' => '5010', 'name' => 'Salaries & Wages', 'type' => 'expense', 'subtype' => 'operating',
            'group' => 'Operating Expenses'],
        ['code' => '5020', 'name' => 'Utilities', 'type' => 'expense', 'subtype' => 'operating',
            'group' => 'Operating Expenses'],
        ['code' => '5030', 'name' => 'Internet & Communication', 'type' => 'expense', 'subtype' => 'operating',
            'group' => 'Operating Expenses'],
        ['code' => '5040', 'name' => 'Office Supplies', 'type' => 'expense', 'subtype' => 'operating',
            'group' => 'Operating Expenses'],
        ['code' => '5050', 'name' => 'Transport & Fuel', 'type' => 'expense', 'subtype' => 'operating',
            'group' => 'Operating Expenses'],
        ['code' => '5060', 'name' => 'Bank Charges', 'type' => 'expense', 'subtype' => 'operating',
            'group' => 'Finance Costs'],
        ['code' => '5070', 'name' => 'Professional Fees', 'type' => 'expense', 'subtype' => 'operating',
            'group' => 'Administrative Expenses'],
        ['code' => '5080', 'name' => 'Marketing & Advertising', 'type' => 'expense', 'subtype' => 'operating',
            'group' => 'Operating Expenses'],
        ['code' => '5900', 'name' => 'Miscellaneous Expense', 'type' => 'expense', 'subtype' => 'operating',
            'group' => 'Operating Expenses'],
        ['code' => '5950', 'name' => 'Tax Expense', 'type' => 'expense', 'subtype' => 'tax',
            'group' => 'Tax Expense'],
        // A net gain shows as a negative expense; with ZWG volatility this
        // is often a business's biggest hidden cost — keep it visible.
        ['code' => '5960', 'name' => 'Exchange Gains/Losses (Realised)', 'type' => 'expense', 'subtype' => 'fx_realised', 'system' => true,
            'group' => 'Finance Costs'],
        ['code' => '5970', 'name' => 'Exchange Gains/Losses (Unrealised)', 'type' => 'expense', 'subtype' => 'fx_unrealised', 'system' => true,
            'group' => 'Finance Costs'],
    ];

    public function seedDefaultChart(Company $company): void
    {
        foreach (self::TEMPLATE as $row) {
            $account = Account::firstOrCreate(
                ['company_id' => $company->id, 'code' => $row['code']],
                [
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'subtype' => $row['subtype'] ?? null,
                    'is_system' => $row['system'] ?? false,
                    'is_active' => true,
                    'statement_group' => $row['group'] ?? null,
                    'statement_line' => $row['line'] ?? null,
                    'balance_type' => $row['balance_type'] ?? null,
                ],
            );

            // Backfill template metadata on charts seeded before it existed,
            // without touching values someone has customised.
            if ($account->statement_group === null && isset($row['group'])) {
                $account->update([
                    'statement_group' => $row['group'],
                    'statement_line' => $row['line'] ?? null,
                    'balance_type' => $row['balance_type'] ?? $account->balance_type,
                ]);
            }
        }

        // Money accounts and the tax payable account transact in the
        // company's base currency unless the user says otherwise.
        Account::where('company_id', $company->id)
            ->whereNull('currency')
            ->where(fn ($q) => $q->whereIn('subtype', Account::MONEY_SUBTYPES)
                ->orWhere(fn ($q) => $q->where('subtype', 'tax')->where('type', 'liability')))
            ->update(['currency' => $company->currency]);
    }

    /**
     * Ensure the payroll liability accounts exist (for companies whose
     * chart was seeded before payroll). Idempotent.
     */
    public function ensurePayrollAccounts(Company $company): void
    {
        $accounts = [
            ['code' => '2110', 'name' => 'Pension Payable', 'subtype' => 'pension'],
            ['code' => '2120', 'name' => 'Payroll Deductions Payable', 'subtype' => 'payroll_deduction'],
        ];

        foreach ($accounts as $a) {
            Account::firstOrCreate(
                ['company_id' => $company->id, 'code' => $a['code']],
                [
                    'name' => $a['name'], 'type' => 'liability', 'subtype' => $a['subtype'],
                    'is_system' => true, 'is_active' => true,
                    'statement_group' => 'Current Liabilities',
                ],
            );
        }
    }

    public function ensureEmployeeLoanAccount(Company $company): Account
    {
        return Account::firstOrCreate(
            ['company_id' => $company->id, 'code' => '1150'],
            [
                'name' => 'Employee Loans Receivable',
                'type' => 'asset',
                'subtype' => 'employee_loan',
                'is_system' => true,
                'is_active' => true,
                'statement_group' => 'Current Assets',
                'statement_line' => 'Trade and other receivables',
            ],
        );
    }

    /**
     * Ensure the share capital / share premium equity accounts exist
     * (for companies whose chart was seeded before share issues). Idempotent.
     */
    public function ensureShareCapitalAccounts(Company $company): void
    {
        $accounts = [
            ['code' => '3300', 'name' => 'Share Capital', 'subtype' => 'share_capital'],
            ['code' => '3400', 'name' => 'Share Premium', 'subtype' => 'share_premium'],
        ];

        foreach ($accounts as $a) {
            Account::firstOrCreate(
                ['company_id' => $company->id, 'code' => $a['code']],
                [
                    'name' => $a['name'], 'type' => 'equity', 'subtype' => $a['subtype'],
                    'is_system' => true, 'is_active' => true,
                    'statement_group' => 'Equity',
                ],
            );
        }
    }

    /**
     * Ensure the dividend accounts exist (for companies whose chart was
     * seeded before dividends). Idempotent.
     */
    public function ensureDividendAccounts(Company $company): void
    {
        Account::firstOrCreate(
            ['company_id' => $company->id, 'code' => '2130'],
            [
                'name' => 'Dividends Payable', 'type' => 'liability', 'subtype' => 'dividend_payable',
                'is_system' => true, 'is_active' => true,
                'statement_group' => 'Current Liabilities',
            ],
        );
        Account::firstOrCreate(
            ['company_id' => $company->id, 'code' => '3950'],
            [
                'name' => 'Dividends Declared', 'type' => 'equity', 'subtype' => 'dividends_declared',
                'is_system' => true, 'is_active' => true,
                'statement_group' => 'Equity', 'balance_type' => 'debit',
            ],
        );
    }

    public function ensureShareholderLoanAccount(Company $company): Account
    {
        return Account::firstOrCreate(
            ['company_id' => $company->id, 'code' => '2350'],
            [
                'name' => 'Shareholder Loans',
                'type' => 'liability',
                'subtype' => 'shareholder_loan',
                'is_system' => true,
                'is_active' => true,
                'statement_group' => 'Non-current Liabilities',
            ],
        );
    }

    /**
     * The tax payable account for a currency. ZIMRA expects tax to be
     * declared and paid in the currency of trade, so each trading
     * currency gets its own tax payable account, visible on the face of
     * the balance sheet. The base-currency account is the template's
     * 2100; other currencies get their own account on first use.
     */
    public function taxAccountFor(Company $company, ?string $currency): Account
    {
        $currency = $currency ?: $company->currency;

        if ($currency === $company->currency) {
            $account = $this->findBySubtype($company, 'tax');
            if ($account->currency === null) {
                $account->update(['currency' => $currency]);
            }

            return $account;
        }

        $existing = Account::where('company_id', $company->id)
            ->where('subtype', 'tax')->where('type', 'liability')
            ->where('currency', $currency)
            ->orderBy('code')
            ->first();
        if ($existing) {
            return $existing;
        }

        // Allocate the next free code in the 2140+ range.
        $code = 2140;
        while (Account::where('company_id', $company->id)->where('code', (string) $code)->exists()) {
            $code++;
        }

        return Account::create([
            'company_id' => $company->id,
            'code' => (string) $code,
            'name' => 'Tax Payable — '.$currency,
            'type' => 'liability',
            'subtype' => 'tax',
            'currency' => $currency,
            'is_system' => true,
            'is_active' => true,
            'statement_group' => 'Current Liabilities',
        ]);
    }

    /** The realised or unrealised exchange gains/losses account. */
    public function fxAccount(Company $company, bool $realised = true): Account
    {
        $subtype = $realised ? 'fx_realised' : 'fx_unrealised';

        return Account::firstOrCreate(
            ['company_id' => $company->id, 'code' => $realised ? '5960' : '5970'],
            [
                'name' => 'Exchange Gains/Losses ('.($realised ? 'Realised' : 'Unrealised').')',
                'type' => 'expense',
                'subtype' => $subtype,
                'is_system' => true,
                'is_active' => true,
                'statement_group' => 'Finance Costs',
            ],
        );
    }

    public function find(Company $company, string $code): Account
    {
        return Account::where('company_id', $company->id)->where('code', $code)->firstOrFail();
    }

    public function findBySubtype(Company $company, string $subtype): Account
    {
        return Account::where('company_id', $company->id)
            ->where('subtype', $subtype)
            ->orderBy('code')
            ->firstOrFail();
    }
}
