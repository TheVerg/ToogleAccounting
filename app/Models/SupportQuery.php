<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'user_id', 'type', 'subject', 'message', 'status', 'admin_response', 'responded_at'])]
class SupportQuery extends Model
{
    use BelongsToTenant;

    public const TYPES = ['query', 'improvement', 'issue'];

    public const STATUSES = ['open', 'in_progress', 'resolved'];

    protected function casts(): array
    {
        return [
            'responded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScope('tenant');
    }
}
