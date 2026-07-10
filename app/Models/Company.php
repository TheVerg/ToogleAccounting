<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Company extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'type', 'parent_id', 'currency', 'email', 'phone', 'address',
        'tax_number', 'bank_name', 'bank_account_name', 'bank_account_number',
        'bank_branch_code', 'bank_swift_code', 'payment_instructions',
        'is_active', 'locked_before',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'locked_before' => 'date',
    ];

    /** True when the date falls in a closed (locked) period. */
    public function isDateLocked(Carbon|string $date): bool
    {
        return $this->locked_before !== null
            && Carbon::parse($date)->lt($this->locked_before);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'parent_id');
    }

    public function subsidiaries(): HasMany
    {
        return $this->hasMany(Company::class, 'parent_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function isHolding(): bool
    {
        return $this->type === 'holding';
    }
}
