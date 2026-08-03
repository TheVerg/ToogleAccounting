<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalDay extends Model
{
    protected $fillable = [
        'fiscal_device_id', 'fiscal_day_no', 'status', 'opened_at', 'closed_at',
        'receipt_counter', 'counters', 'error',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'counters' => 'array',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(FiscalDevice::class, 'fiscal_device_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(FiscalReceipt::class);
    }
}
