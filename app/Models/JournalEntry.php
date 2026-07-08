<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

class JournalEntry extends Model
{
    use HasAttachments;

    protected $fillable = [
        'company_id', 'entry_no', 'date', 'description', 'reference',
        'status', 'source_type', 'source_id', 'reversal_of_id',
        'created_by', 'posted_at',
    ];

    protected $casts = [
        'date' => 'date',
        'posted_at' => 'datetime',
    ];

    /**
     * Posted entries are immutable: the only permitted change is the
     * posted -> reversed status flip made when a reversing entry is posted.
     * Corrections are made by posting an equal-and-opposite entry.
     */
    protected static function booted(): void
    {
        static::updating(function (self $entry) {
            if ($entry->getOriginal('status') === 'draft') {
                return;
            }

            $isReversalFlip = array_keys($entry->getDirty()) === ['status']
                && $entry->getOriginal('status') === 'posted'
                && $entry->status === 'reversed';

            if (! $isReversalFlip) {
                throw new LogicException(
                    'Posted journal entries are immutable; post a reversing entry instead.'
                );
            }
        });

        static::deleting(function (self $entry) {
            if ($entry->status !== 'draft') {
                throw new LogicException(
                    'Posted journal entries cannot be deleted; post a reversing entry instead.'
                );
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_of_id');
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }
}
