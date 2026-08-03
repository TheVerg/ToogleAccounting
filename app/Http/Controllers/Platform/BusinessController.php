<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform admin: every business (tenant) registered on the platform.
 * Tenant is not tenant-scoped; the per-tenant counts must bypass the
 * tenant global scope explicitly.
 */
class BusinessController extends Controller
{
    public function index(): Response
    {
        $tenants = Tenant::query()
            ->withCount([
                'users' => fn ($q) => $q->withoutGlobalScope('tenant'),
                'companies' => fn ($q) => $q->withoutGlobalScope('tenant'),
                'supportQueries as open_queries_count' => fn ($q) => $q
                    ->withoutGlobalScope('tenant')
                    ->where('status', '!=', 'resolved'),
            ])
            ->orderByDesc('created_at')
            ->get();

        // Earliest user per tenant = the registering owner.
        $owners = User::withoutGlobalScopes()
            ->whereIn('tenant_id', $tenants->pluck('id'))
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'name', 'email'])
            ->groupBy('tenant_id')
            ->map->first();

        return Inertia::render('Platform/Businesses', [
            'businesses' => $tenants->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'plan' => $t->plan,
                'status' => $t->status,
                'is_active' => $t->is_active,
                'trial_ends_at' => $t->trial_ends_at?->format('d M Y'),
                'users_count' => $t->users_count,
                'companies_count' => $t->companies_count,
                'open_queries_count' => $t->open_queries_count,
                'owner_name' => $owners->get($t->id)?->name,
                'owner_email' => $owners->get($t->id)?->email,
                'registered_at' => $t->created_at->format('d M Y'),
            ]),
        ]);
    }
}
