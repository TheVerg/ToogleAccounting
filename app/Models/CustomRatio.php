<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A company-defined ratio (numerator ÷ denominator over the report's
 * figure catalogue) with its own target range.
 */
class CustomRatio extends Model
{
    use Auditable, SoftDeletes;

    public const SECTIONS = ['liquidity', 'profitability', 'leverage', 'efficiency', 'custom'];

    protected $fillable = [
        'company_id', 'label', 'section', 'numerator', 'denominator',
        'format', 'target_min', 'target_max',
    ];

    protected $casts = [
        'target_min' => 'float',
        'target_max' => 'float',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
