<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only log of every receipt submitted (or queued) to FDMS —
 * the exact payload, hash chain link, and ZIMRA's response. Never
 * deleted: corrections are credit notes, which are receipts themselves.
 */
class FiscalReceipt extends Model
{
    protected $fillable = [
        'fiscal_device_id', 'fiscal_day_id', 'invoice_id', 'original_fiscal_receipt_id',
        'receipt_type', 'currency', 'receipt_counter', 'receipt_global_no',
        'invoice_no', 'receipt_date', 'total', 'payload', 'hash', 'device_signature',
        'server_receipt_id', 'server_signature', 'verification_url', 'qr_data',
        'status', 'error',
    ];

    protected $casts = [
        'receipt_date' => 'datetime',
        'total' => 'decimal:2',
        'payload' => 'array',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(FiscalDevice::class, 'fiscal_device_id');
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(FiscalDay::class, 'fiscal_day_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_fiscal_receipt_id');
    }
}
