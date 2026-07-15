<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DividendAllocation extends Model
{
    protected $fillable = [
        'company_id', 'dividend_id', 'shareholder_id', 'shareholder_name',
        'shares', 'percent', 'amount', 'paid_amount',
    ];

    protected $casts = [
        'shares' => 'decimal:2',
        'percent' => 'decimal:4',
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
    ];

    public function dividend(): BelongsTo
    {
        return $this->belongsTo(Dividend::class);
    }

    public function shareholder(): BelongsTo
    {
        return $this->belongsTo(Shareholder::class)->withTrashed();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(DividendPayment::class);
    }

    public function outstanding(): float
    {
        return round((float) $this->amount - (float) $this->paid_amount, 2);
    }
}
