<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use App\Services\Compliance\ComplianceReturnTypeRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceReturn extends Model
{
    use Auditable, HasAttachments;

    protected $fillable = [
        'company_id', 'return_type', 'period_from', 'period_to', 'instalment', 'due_date', 'status',
        'amendment_no', 'amends_return_id', 'snapshot', 'notes', 'filing_reference',
        'prepared_by', 'prepared_at', 'reviewed_by', 'reviewed_at',
        'approved_by', 'approved_at', 'filed_by', 'filed_at',
    ];

    protected $casts = [
        'period_from' => 'date', 'period_to' => 'date', 'due_date' => 'date',
        'snapshot' => 'array', 'prepared_at' => 'datetime', 'reviewed_at' => 'datetime',
        'approved_at' => 'datetime', 'filed_at' => 'datetime',
    ];

    /**
     * Whether this return has a working-paper builder. Rows created before a
     * type was withdrawn from the registry still exist and stay readable, but
     * must not be prepared, approved or filed on another type's figures.
     */
    public function hasWorkspace(): bool
    {
        return app(ComplianceReturnTypeRegistry::class)->has($this->return_type);
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function adjustments(): HasMany { return $this->hasMany(ComplianceAdjustment::class); }
    public function events(): HasMany { return $this->hasMany(ComplianceReturnEvent::class); }
    public function deadline(): HasMany { return $this->hasMany(ComplianceDeadline::class); }
}
