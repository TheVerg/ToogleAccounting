<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recurring item on an employee's salary structure: an allowance
 * (earning) or a deduction, applied automatically to every pay run.
 */
class SalaryComponent extends Model
{
    use Auditable;

    public const KINDS = ['earning', 'deduction'];

    public const CALC_TYPES = ['fixed', 'percent_of_basic'];

    protected $fillable = [
        'employee_id', 'kind', 'code', 'name', 'calc_type', 'amount', 'is_active',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Resolve the money value of this component against a basic salary. */
    public function resolve(float $basic): float
    {
        return $this->calc_type === 'percent_of_basic'
            ? round($basic * ((float) $this->amount / 100), 2)
            : round((float) $this->amount, 2);
    }
}
