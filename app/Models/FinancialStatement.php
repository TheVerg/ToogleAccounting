<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A generated statement, frozen at generation time. Draft until
 * approved; approved statements are locked and can only be removed by
 * a forced soft delete that records who removed them and when.
 */
class FinancialStatement extends Model
{
    use Auditable, SoftDeletes;

    public const TYPES = [
        'trial-balance' => 'Trial Balance',
        'profit-loss' => 'Profit & Loss',
        'balance-sheet' => 'Balance Sheet',
        'cash-flow' => 'Cash Flow',
        'tax-summary' => 'Tax Summary',
        'ratios' => 'Financial Ratios',
        'budget-vs-actual' => 'Budget vs Actual',
    ];

    /** Types a filled template can be imported for (ratios are always computed). */
    public const IMPORTABLE_TYPES = [
        'trial-balance', 'profit-loss', 'balance-sheet', 'cash-flow', 'tax-summary', 'budget-vs-actual',
    ];

    protected $fillable = [
        'company_id', 'type', 'name', 'scope_label', 'period_from', 'period_to', 'source',
        'status', 'columns', 'lines', 'generated_by', 'approved_by', 'approved_at', 'deleted_by',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'columns' => 'array',
        'lines' => 'array',
        'approved_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
