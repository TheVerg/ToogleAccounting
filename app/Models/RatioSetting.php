<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company's target range for one financial ratio. Absent rows fall
 * back to the defaults in ReportService::RATIO_DEFINITIONS.
 */
class RatioSetting extends Model
{
    use Auditable;

    protected $fillable = ['company_id', 'key', 'target_min', 'target_max'];

    protected $casts = [
        'target_min' => 'float',
        'target_max' => 'float',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
