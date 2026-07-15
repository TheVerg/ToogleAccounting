<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DividendPayment extends Model
{
    use Auditable, HasAttachments;

    protected $fillable = [
        'company_id', 'dividend_allocation_id', 'date', 'amount', 'reference',
        'money_account_id', 'journal_entry_id', 'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(DividendAllocation::class, 'dividend_allocation_id');
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
