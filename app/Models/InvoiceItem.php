<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id', 'account_id', 'tax_group_id', 'description', 'quantity', 'unit_price',
        'amount', 'tax_amount', 'gross_amount', 'hs_code',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'gross_amount' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function taxGroup(): BelongsTo { return $this->belongsTo(TaxGroup::class); }
    public function taxes(): HasMany { return $this->hasMany(InvoiceItemTax::class); }
}
