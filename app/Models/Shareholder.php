<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shareholder extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'email', 'phone', 'shares_held', 'notes', 'is_active',
    ];

    protected $casts = [
        'shares_held' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function equityTransactions(): HasMany
    {
        return $this->hasMany(EquityTransaction::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(ShareholderLoan::class);
    }
}
