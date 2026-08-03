<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItemTax extends Model
{
    protected $fillable = [
        'invoice_item_id', 'tax_rate_id', 'tax_name', 'rate', 'calculation_order',
        'is_compound', 'is_fiscal_tax', 'zimra_tax_id', 'zimra_tax_code',
        'taxable_amount', 'tax_amount',
    ];
    protected $casts = [
        'rate' => 'decimal:3', 'is_compound' => 'boolean', 'is_fiscal_tax' => 'boolean',
        'taxable_amount' => 'decimal:2', 'tax_amount' => 'decimal:2',
    ];
    public function item(): BelongsTo { return $this->belongsTo(InvoiceItem::class, 'invoice_item_id'); }
    public function taxRate(): BelongsTo { return $this->belongsTo(TaxRate::class); }
}
