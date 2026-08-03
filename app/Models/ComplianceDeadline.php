<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceDeadline extends Model
{
    protected $fillable = ['company_id', 'return_type', 'period_from', 'period_to', 'due_date', 'status', 'assigned_to', 'compliance_return_id', 'completed_at'];
    protected $casts = ['period_from' => 'date', 'period_to' => 'date', 'due_date' => 'date', 'completed_at' => 'datetime'];
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function complianceReturn(): BelongsTo { return $this->belongsTo(ComplianceReturn::class); }
}
