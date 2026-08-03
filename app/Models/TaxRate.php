<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaxRate extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'rate', 'default_price_mode', 'calculation_order',
        'is_compound', 'is_fiscal_tax', 'is_active', 'zimra_tax_id', 'zimra_tax_code',
    ];

    protected $casts = [
        'rate' => 'decimal:3',
        'is_active' => 'boolean',
        'is_compound' => 'boolean',
        'is_fiscal_tax' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(TaxGroup::class, 'tax_group_rates')->withPivot(['calculation_order', 'is_compound']);
    }
}
