<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceAdjustment extends Model
{
    protected $fillable = ['compliance_return_id', 'currency', 'category', 'description', 'tax_amount', 'created_by'];
    protected $casts = ['tax_amount' => 'decimal:2'];
    public function complianceReturn(): BelongsTo { return $this->belongsTo(ComplianceReturn::class); }
}
