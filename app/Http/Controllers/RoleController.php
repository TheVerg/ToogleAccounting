<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permissions section — system-admin gated. Roles carry sets of
 * permissions; users are assigned roles in the Users screen.
 */
class RoleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Roles', [
            'roles' => Role::with('permissions:id,name')->orderBy('name')->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'permissions' => $r->permissions->pluck('name'),
                    'is_protected' => $r->name === 'system-admin',
                ]),
            'allPermissions' => Permission::orderBy('name')->pluck('name'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:roles,name'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        // team_id stays null: roles are global definitions, assigned
        // per company on the Users screen.
        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web', 'team_id' => null]);
        $role->syncPermissions($data['permissions'] ?? []);

        return back()->with('success', 'Role created.');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->name === 'system-admin', 422, 'The system-admin role cannot be modified.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($role->id)],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role->update(['name' => $data['name']]);
        $role->syncPermissions($data['permissions'] ?? []);

        return back()->with('success', 'Role updated.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        abort_if($role->name === 'system-admin', 422, 'The system-admin role cannot be deleted.');
        abort_if(
            DB::table('model_has_roles')->where('role_id', $role->id)->exists(),
            422, 'Role is assigned to users; reassign them first.',
        );

        $role->delete();

        return back()->with('success', 'Role deleted.');
    }
}
