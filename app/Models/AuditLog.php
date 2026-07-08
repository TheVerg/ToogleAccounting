<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;

/**
 * Append-only trail of who changed what. Rows are written by the
 * Auditable model trait or by AuditLog::record() for events that are
 * not plain attribute changes (role assignments, reversals, ...).
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'company_id', 'action', 'auditable_type', 'auditable_id', 'changes',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Record a custom event, attributed to the authenticated user. */
    public static function record(string $action, ?Model $subject = null, ?array $changes = null, ?int $companyId = null): self
    {
        return static::create([
            'user_id' => Auth::id(),
            'company_id' => $companyId ?? $subject?->company_id ?? null,
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'changes' => $changes,
        ]);
    }
}
