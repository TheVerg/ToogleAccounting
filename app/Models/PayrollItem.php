<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single employee's computed pay slip within a run: a frozen
 * breakdown of earnings and deductions.
 */
class PayrollItem extends Model
{
    protected $fillable = [
        'payroll_run_id', 'employee_id', 'employee_name', 'job_title', 'currency',
        'basic', 'earnings_total', 'deductions_total', 'gross', 'net', 'employer_costs_total', 'lines',
    ];

    protected $casts = [
        'basic' => 'decimal:2',
        'earnings_total' => 'decimal:2',
        'deductions_total' => 'decimal:2',
        'gross' => 'decimal:2',
        'net' => 'decimal:2',
        'employer_costs_total' => 'decimal:2',
        'lines' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
