<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquityTransaction extends Model
{
    use HasAttachments;

    protected $fillable = [
        'company_id', 'type', 'date', 'owner_name', 'shareholder_id', 'amount',
        'shares_count', 'share_capital_amount', 'share_premium_amount',
        'money_account_id', 'equity_account_id', 'premium_account_id', 'description',
        'journal_entry_id', 'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'shares_count' => 'integer',
        'share_capital_amount' => 'decimal:2',
        'share_premium_amount' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'money_account_id');
    }

    public function equityAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'equity_account_id');
    }

    public function premiumAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'premium_account_id');
    }

    public function shareholder(): BelongsTo
    {
        return $this->belongsTo(Shareholder::class)->withTrashed();
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
