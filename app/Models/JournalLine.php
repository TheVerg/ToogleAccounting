<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class JournalLine extends Model
{
    protected $fillable = [
        'journal_entry_id', 'account_id', 'debit', 'credit', 'memo',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    /** Lines of posted entries are immutable, like the entries themselves. */
    protected static function booted(): void
    {
        $guard = function (self $line) {
            if ($line->entry()->value('status') !== 'draft') {
                throw new LogicException(
                    'Lines of a posted journal entry are immutable; post a reversing entry instead.'
                );
            }
        };

        static::updating($guard);
        static::deleting($guard);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
