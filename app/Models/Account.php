<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use Auditable, SoftDeletes;

    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    /** Account types whose normal balance is a debit. */
    public const DEBIT_NORMAL = ['asset', 'expense'];

    /** Subtypes representing actual money (cash/bank/petty cash). */
    public const MONEY_SUBTYPES = ['cash', 'bank', 'petty_cash'];

    public const STATEMENTS = ['balance_sheet', 'income_statement', 'cash_flow', 'equity_changes'];

    /** Standard statement groups — the basics every chart shares. */
    public const STANDARD_GROUPS = [
        'income_statement' => [
            'Revenue', 'Other Income', 'Cost of Sales',
            'Operating Expenses', 'Administrative Expenses', 'Finance Costs', 'Tax Expense',
        ],
        'balance_sheet' => [
            'Non-current Assets', 'Current Assets',
            'Current Liabilities', 'Non-current Liabilities', 'Equity',
        ],
    ];

    /** Standard roll-up lines (levels) accounts can aggregate into. */
    public const STANDARD_LINES = [
        'Cash and cash equivalents',
        'Trade and other receivables',
        'Trade and other payables',
        'Property, plant and equipment',
        'Intercompany balances',
    ];

    protected $fillable = [
        'company_id', 'code', 'name', 'type', 'subtype', 'currency',
        'description', 'is_system', 'is_active', 'default_hs_code',
        'statement', 'statement_group', 'statement_line', 'balance_type',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function isDebitNormal(): bool
    {
        return in_array($this->type, self::DEBIT_NORMAL, true);
    }

    /** The statement this account reports on (defaults by account type). */
    public function effectiveStatement(): string
    {
        return $this->statement
            ?? (in_array($this->type, ['income', 'expense'], true) ? 'income_statement' : 'balance_sheet');
    }

    /** Normal balance; when it differs from the type default the account is contra (flips sign). */
    public function effectiveBalanceType(): string
    {
        return $this->balance_type ?? ($this->isDebitNormal() ? 'debit' : 'credit');
    }

    /** True for accounts representing actual money (cash/bank/petty cash). */
    public function isMoneyAccount(): bool
    {
        return $this->type === 'asset'
            && in_array($this->subtype, self::MONEY_SUBTYPES, true);
    }

    /**
     * The currency transactions through this account happen in;
     * defaults to the company's base currency when not set.
     */
    public function effectiveCurrency(): string
    {
        return $this->currency ?? $this->company->currency;
    }
}
