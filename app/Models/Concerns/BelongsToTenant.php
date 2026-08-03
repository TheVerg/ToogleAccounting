<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Row-level tenancy. Every query on the model is automatically filtered
 * to the authenticated user's tenant, and new rows are stamped with it —
 * isolation is enforced at the query layer, not per controller.
 *
 * Unauthenticated contexts (login, password reset, console commands,
 * seeders) see no filter; a user without a tenant (legacy/tests) also
 * sees no filter.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $tenantId = Auth::hasUser() ? Auth::user()->tenant_id : null;
            if ($tenantId !== null) {
                $query->where($query->getModel()->qualifyColumn('tenant_id'), $tenantId);
            }
        });

        static::creating(function (Model $model) {
            if ($model->getAttribute('tenant_id') === null && Auth::hasUser()) {
                $model->setAttribute('tenant_id', Auth::user()->tenant_id);
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
