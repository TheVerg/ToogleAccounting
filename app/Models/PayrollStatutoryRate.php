<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class PayrollStatutoryRate extends Model
{
    use Auditable;

    protected $fillable = ['tenant_id', 'jurisdiction', 'code', 'currency', 'effective_from', 'effective_to', 'employee_rate', 'employer_rate', 'rate', 'ceiling', 'metadata', 'source_url', 'is_approved'];

    /** `rate` is for codes with no employee/employer split — corporate income tax. */
    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date', 'employee_rate' => 'decimal:6', 'employer_rate' => 'decimal:6', 'rate' => 'decimal:6', 'ceiling' => 'decimal:2', 'metadata' => 'array', 'is_approved' => 'boolean'];

    /**
     * Rates a tenant may see and use: the shared national rates
     * (tenant_id null) plus its own overrides.
     */
    public function scopeVisibleToTenant($query, ?int $tenantId)
    {
        return $query->where(fn ($q) => $q->whereNull('tenant_id')
            ->when($tenantId, fn ($q2) => $q2->orWhere('tenant_id', $tenantId)));
    }
}
