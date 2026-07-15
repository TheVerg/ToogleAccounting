<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Dividend extends Model
{
    use Auditable, HasAttachments;

    protected $fillable = [
        'company_id', 'dividend_no', 'date', 'total_amount', 'status',
        'description', 'journal_entry_id', 'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'total_amount' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(DividendAllocation::class);
    }

    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(DividendPayment::class, DividendAllocation::class);
    }

    /** Recompute declared / partially_paid / paid from the allocations. */
    public function refreshStatus(): void
    {
        if ($this->status === 'reversed') {
            return;
        }

        $paid = (float) $this->allocations()->sum('paid_amount');
        $this->update([
            'status' => $paid + 0.005 >= (float) $this->total_amount
                ? 'paid'
                : ($paid > 0 ? 'partially_paid' : 'declared'),
        ]);
    }
}
