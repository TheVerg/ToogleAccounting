<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarantees the authenticated user has a valid current company selected
 * (their working context) before entering any accounting screen.
 */
class EnsureCurrentCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $company = $user->currentCompany;

            if (! $company || ! $user->canAccessCompany($company)) {
                $fallback = $user->accessibleCompanies()->first();

                if (! $fallback) {
                    abort(403, 'You are not assigned to any company. Ask a system administrator.');
                }

                $user->forceFill(['current_company_id' => $fallback->id])->save();
                $user->setRelation('currentCompany', $fallback);
            }

            // Roles are per company (spatie teams): permission checks from
            // here on resolve against the selected company.
            setPermissionsTeamId($user->current_company_id);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }

        return $next($request);
    }
}
