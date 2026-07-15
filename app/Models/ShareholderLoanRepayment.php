<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShareholderLoanRepayment extends Model
{
    use Auditable, HasAttachments;

    protected $fillable = [
        'company_id', 'shareholder_loan_id', 'date', 'amount', 'reference',
        'money_account_id', 'journal_entry_id', 'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(ShareholderLoan::class, 'shareholder_loan_id');
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'money_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
