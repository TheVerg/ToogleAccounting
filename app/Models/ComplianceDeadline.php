<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceDeadline extends Model
{
    /**
     * Everything the calendar can track. A deadline is a date, an owner and a
     * status — it needs no computation, so obligations the app cannot yet
     * compute (QPD, ITF12B/C, PAYE-P2, NSSA, ZIMDEF) are still trackable here
     * even though they cannot be opened as a return workspace.
     *
     * ITF12B is the provisional (QPD) return; ITF12C is the annual income tax
     * return — they are not interchangeable.
     */
    public const TYPES = ['VAT7', 'PAYE-P2', 'QPD', 'ITF12B', 'ITF12C', 'NSSA', 'ZIMDEF'];

    protected $fillable = ['company_id', 'return_type', 'period_from', 'period_to', 'instalment', 'due_date', 'status', 'assigned_to', 'compliance_return_id', 'completed_at'];
    protected $casts = ['period_from' => 'date', 'period_to' => 'date', 'due_date' => 'date', 'completed_at' => 'datetime'];
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function complianceReturn(): BelongsTo { return $this->belongsTo(ComplianceReturn::class); }
}
