<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceReturnEvent extends Model
{
    protected $fillable = ['compliance_return_id', 'action', 'from_status', 'to_status', 'comment', 'user_id'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
