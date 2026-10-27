<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class PayrollTaxBand extends Model
{
    use Auditable;

    protected $fillable = ['tenant_id', 'jurisdiction', 'currency', 'frequency', 'effective_from', 'effective_to', 'lower_bound', 'upper_bound', 'rate', 'deduction', 'source_url', 'is_approved'];

    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date', 'rate' => 'decimal:6', 'deduction' => 'decimal:2', 'is_approved' => 'boolean'];

    /**
     * Bands a tenant may see and use: the shared national tables
     * (tenant_id null) plus its own overrides.
     */
    public function scopeVisibleToTenant($query, ?int $tenantId)
    {
        return $query->where(fn ($q) => $q->whereNull('tenant_id')
            ->when($tenantId, fn ($q2) => $q2->orWhere('tenant_id', $tenantId)));
    }
}
