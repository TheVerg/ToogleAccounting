<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShareholderLoan extends Model
{
    use Auditable, HasAttachments;

    protected $fillable = [
        'company_id', 'loan_no', 'lender_name', 'shareholder_id', 'date', 'principal',
        'total_repaid', 'outstanding_balance', 'status', 'description',
        'money_account_id', 'liability_account_id', 'journal_entry_id', 'created_by',
        'currency', 'exchange_rate', 'base_principal',
    ];

    protected $casts = [
        'date' => 'date',
        'principal' => 'decimal:2',
        'total_repaid' => 'decimal:2',
        'outstanding_balance' => 'decimal:2',
        'reversed_at' => 'datetime',
        'exchange_rate' => 'decimal:6',
        'base_principal' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function shareholder(): BelongsTo
    {
        return $this->belongsTo(Shareholder::class)->withTrashed();
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'money_account_id');
    }

    public function liabilityAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'liability_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(ShareholderLoanRepayment::class);
    }
}
