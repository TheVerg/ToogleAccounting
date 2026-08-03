<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use Auditable, HasAttachments;

    protected $fillable = [
        'company_id', 'contact_id', 'invoice_no', 'issue_date', 'due_date',
        'status', 'subtotal', 'tax_mode', 'tax_group_id', 'tax_rate_id', 'tax_amount', 'total',
        'amount_paid', 'notes', 'journal_entry_id', 'created_by',
        'currency', 'exchange_rate',
        'base_subtotal', 'base_tax_amount', 'base_total', 'base_amount_paid',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'base_subtotal' => 'decimal:2',
        'base_tax_amount' => 'decimal:2',
        'base_total' => 'decimal:2',
        'base_amount_paid' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function fiscalReceipts(): HasMany
    {
        return $this->hasMany(FiscalReceipt::class);
    }

    /** The successful FDMS submission for this invoice, if any. */
    public function fiscalReceipt(): ?FiscalReceipt
    {
        return $this->fiscalReceipts
            ->where('status', 'submitted')
            ->where('receipt_type', 'FiscalInvoice')
            ->sortByDesc('id')
            ->first();
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function taxGroup(): BelongsTo { return $this->belongsTo(TaxGroup::class); }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function balanceDue(): float
    {
        return round((float) $this->total - (float) $this->amount_paid, 2);
    }
}
