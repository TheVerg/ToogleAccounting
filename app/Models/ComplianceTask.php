<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceTask extends Model
{
    protected $fillable = ['company_id', 'compliance_return_id', 'type', 'title', 'description', 'status', 'due_date', 'assigned_to', 'requested_by', 'completed_at'];
    protected $casts = ['due_date' => 'date', 'completed_at' => 'datetime'];
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
}
