<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntercompanyTransfer extends Model
{
    use HasAttachments;

    protected $fillable = [
        'from_company_id', 'to_company_id', 'type', 'date', 'amount',
        'from_money_account_id', 'to_money_account_id', 'description',
        'from_journal_entry_id', 'to_journal_entry_id', 'created_by',
        'currency', 'from_exchange_rate', 'to_exchange_rate',
        'from_base_amount', 'to_base_amount',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'from_exchange_rate' => 'decimal:6',
        'to_exchange_rate' => 'decimal:6',
        'from_base_amount' => 'decimal:2',
        'to_base_amount' => 'decimal:2',
    ];

    public function fromCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'from_company_id');
    }

    public function toCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'to_company_id');
    }

    public function fromMoneyAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_money_account_id');
    }

    public function toMoneyAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_money_account_id');
    }

    public function fromJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'from_journal_entry_id');
    }

    public function toJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'to_journal_entry_id');
    }
}
