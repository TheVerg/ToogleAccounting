<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // Lazy closure: evaluated when the response is rendered, i.e.
            // AFTER EnsureCurrentCompany has pinned the permissions team to
            // the selected company. Roles/permissions are per company, so
            // resolving them earlier would yield an empty set.
            'auth' => function () use ($request) {
                $user = $request->user();

                if ($user && $user->current_company_id) {
                    setPermissionsTeamId($user->current_company_id);
                    $user->unsetRelation('roles')->unsetRelation('permissions');
                }

                return [
                    'user' => $user ? [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'roles' => $user->getRoleNames(),
                        'permissions' => $user->getAllPermissions()->pluck('name'),
                        'is_system_admin' => $user->isSystemAdmin(),
                    ] : null,
                    'companies' => $user ? $user->accessibleCompanies()->map(fn ($c) => [
                        'id' => $c->id, 'name' => $c->name, 'code' => $c->code, 'type' => $c->type,
                    ]) : [],
                    'currentCompany' => $user?->currentCompany
                        ? [
                            'id' => $user->currentCompany->id,
                            'name' => $user->currentCompany->name,
                            'code' => $user->currentCompany->code,
                            'type' => $user->currentCompany->type,
                            'currency' => $user->currentCompany->currency,
                        ] : null,
                ];
            },
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'temporaryPassword' => $request->session()->get('temporary_password'),
            ],
        ];
    }
}
