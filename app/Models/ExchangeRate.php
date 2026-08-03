<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use Auditable;

    protected $fillable = [
        'date', 'base_currency', 'quote_currency', 'rate', 'source', 'created_by', 'tenant_id',
    ];

    protected $casts = [
        'date' => 'date',
        'rate' => 'decimal:6',
    ];

    /**
     * Rates a tenant may see and use: the shared reference rates
     * (auto-fetched, tenant_id null) plus their own manual entries.
     */
    public function scopeVisibleToTenant($query, ?int $tenantId)
    {
        return $query->where(fn ($q) => $q->whereNull('tenant_id')
            ->when($tenantId, fn ($q2) => $q2->orWhere('tenant_id', $tenantId)));
    }
}
