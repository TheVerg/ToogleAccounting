<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * Virtual Fiscal Device: this software registered with ZIMRA's FDMS as
 * the fiscal device for one company. Holds the client certificate and
 * (encrypted) private key that sign every receipt, plus the stateful
 * counters FDMS requires the device to maintain.
 */
class FiscalDevice extends Model
{
    use Auditable;

    /** Never write key material to the audit trail. */
    protected array $auditExcluded = ['private_key', 'certificate', 'config'];

    protected $fillable = [
        'company_id', 'device_id', 'serial_no', 'model_name', 'model_version',
        'environment', 'status', 'certificate', 'private_key',
        'certificate_valid_until', 'config',
        'last_fiscal_day_no', 'last_receipt_global_no', 'last_receipt_hash',
    ];

    protected $hidden = ['private_key', 'certificate'];

    protected $casts = [
        'config' => 'array',
        'certificate_valid_until' => 'date',
    ];

    /** The private key is encrypted at rest and never leaves the server. */
    protected function privateKey(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : Crypt::decryptString($value),
            set: fn (?string $value) => $value === null ? null : Crypt::encryptString($value),
        );
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function days(): HasMany
    {
        return $this->hasMany(FiscalDay::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(FiscalReceipt::class);
    }

    public function openDay(): ?FiscalDay
    {
        return $this->days()->where('status', 'open')->latest('fiscal_day_no')->first();
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->certificate !== null;
    }
}
