<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TaxGroup extends Model
{
    protected $fillable = ['company_id', 'name', 'is_default', 'is_active'];
    protected $casts = ['is_default' => 'boolean', 'is_active' => 'boolean'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }

    public function rates(): BelongsToMany
    {
        return $this->belongsToMany(TaxRate::class, 'tax_group_rates')
            ->withPivot(['calculation_order', 'is_compound'])->withTimestamps()
            ->orderByPivot('calculation_order');
    }
}
