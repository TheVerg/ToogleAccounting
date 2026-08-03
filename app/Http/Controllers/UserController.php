<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UserController extends Controller
{
    public function index(): Response
    {
        $assignments = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->get([
                'model_has_roles.model_id as user_id',
                'model_has_roles.team_id as company_id',
                'roles.name as role',
            ])
            ->groupBy('user_id');

        // Company/User queries are tenant-scoped globally; employees are
        // scoped through their company.
        $tenantCompanyIds = Company::pluck('id');

        return Inertia::render('Admin/Users', [
            'users' => User::with(['companies:id,name', 'employee:id,user_id,company_id,first_name,last_name'])->orderBy('name')->get()
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'must_change_password' => $u->must_change_password,
                    'employee' => $u->employee
                        ? [
                            'id' => $u->employee->id,
                            'full_name' => $u->employee->full_name,
                            'company_id' => $u->employee->company_id,
                        ]
                        : null,
                    'assignments' => $assignments->get($u->id, collect())
                        ->map(fn ($a) => ['company_id' => (int) $a->company_id, 'role' => $a->role])
                        ->values(),
                    'companies' => $u->companies->map(fn ($c) => ['id' => $c->id, 'name' => $c->name]),
                ]),
            'roles' => Role::orderBy('name')->pluck('name'),
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            'employees' => Employee::with('company:id,name', 'user:id,name,email')
                ->whereIn('company_id', $tenantCompanyIds)
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get()
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'full_name' => $e->full_name,
                    'employee_no' => $e->employee_no,
                    'company_id' => $e->company_id,
                    'company_name' => $e->company?->name,
                    'user_id' => $e->user_id,
                    'user_name' => $e->user?->name,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Only this tenant's companies/employees can be referenced; the
        // new user inherits the creator's tenant automatically.
        $tenantCompanyIds = Company::pluck('id')->all();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'employee_id' => ['nullable', 'integer',
                Rule::exists('employees', 'id')->whereIn('company_id', $tenantCompanyIds)],
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.company_id' => ['required', 'integer', 'distinct', Rule::in($tenantCompanyIds)],
            'assignments.*.role' => ['required', 'string', 'exists:roles,name'],
        ]);
        $this->validateEmployeeLink($request, $data['employee_id'] ?? null);

        $temporaryPassword = Str::password(16);

        DB::transaction(function () use ($data, $temporaryPassword) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($temporaryPassword),
                'must_change_password' => true,
                'current_company_id' => $data['assignments'][0]['company_id'],
            ]);
            $this->syncAccessAudited($user, $data['assignments']);
            $this->syncEmployeeLink($user, $data['employee_id'] ?? null);
            AuditLog::record('temporary_password_issued', $user);
        });

        return back()
            ->with('success', 'User created. Share the temporary password securely; it will not be shown again.')
            ->with('temporary_password', ['email' => $data['email'], 'password' => $temporaryPassword]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $tenantCompanyIds = Company::pluck('id')->all();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'employee_id' => ['nullable', 'integer',
                Rule::exists('employees', 'id')->whereIn('company_id', $tenantCompanyIds)],
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.company_id' => ['required', 'integer', 'distinct', Rule::in($tenantCompanyIds)],
            'assignments.*.role' => ['required', 'string', 'exists:roles,name'],
        ]);
        $this->validateEmployeeLink($request, $data['employee_id'] ?? null, $user);

        DB::transaction(function () use ($data, $user) {
            $user->fill(['name' => $data['name'], 'email' => $data['email']]);
            $user->save();
            $this->syncAccessAudited($user, $data['assignments']);
            $this->syncEmployeeLink($user, $data['employee_id'] ?? null);

            $companyIds = collect($data['assignments'])->pluck('company_id')->all();
            if ($user->current_company_id && ! in_array($user->current_company_id, $companyIds, true) && ! $user->isSystemAdmin()) {
                $user->forceFill(['current_company_id' => $companyIds[0]])->save();
            }
        });

        return back()->with('success', 'User updated.');
    }

    public function resetPassword(User $user): RedirectResponse
    {
        $temporaryPassword = Str::password(16);

        $user->forceFill([
            'password' => Hash::make($temporaryPassword),
            'must_change_password' => true,
            'remember_token' => Str::random(60),
        ])->save();

        DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        AuditLog::record('temporary_password_issued', $user);

        return back()
            ->with('success', 'Temporary password generated. The user must replace it at next login.')
            ->with('temporary_password', ['email' => $user->email, 'password' => $temporaryPassword]);
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_if($user->id === $request->user()->id, 422, 'You cannot delete your own account.');
        $user->delete();

        return back()->with('success', 'User deleted.');
    }

    /**
     * Replace the user's per-company role assignments (their grade in
     * each company) and company access, auditing what changed.
     */
    protected function syncAccessAudited(User $user, array $assignments): void
    {
        $userType = $user->getMorphClass();
        $companyNames = Company::pluck('name', 'id');
        $describe = fn ($companyId, $role) => ($companyNames[$companyId] ?? $companyId).': '.$role;

        $old = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $userType)
            ->where('model_has_roles.model_id', $user->id)
            ->get(['model_has_roles.team_id', 'roles.name'])
            ->map(fn ($r) => $describe($r->team_id, $r->name))
            ->sort()->values()->all();

        $roleIds = Role::pluck('id', 'name');

        DB::table('model_has_roles')
            ->where('model_type', $userType)
            ->where('model_id', $user->id)
            ->delete();
        DB::table('model_has_roles')->insert(collect($assignments)->map(fn ($a) => [
            'role_id' => $roleIds[$a['role']],
            'model_type' => $userType,
            'model_id' => $user->id,
            'team_id' => $a['company_id'],
        ])->all());

        $user->companies()->sync(collect($assignments)->pluck('company_id'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $new = collect($assignments)
            ->map(fn ($a) => $describe($a['company_id'], $a['role']))
            ->sort()->values()->all();

        if ($old !== $new) {
            AuditLog::record('access_changed', $user, ['assignments' => ['old' => $old, 'new' => $new]]);
        }
    }

    protected function validateEmployeeLink(Request $request, ?int $employeeId, ?User $user = null): void
    {
        if (! $employeeId) {
            return;
        }

        $linkedToOther = Employee::whereKey($employeeId)
            ->whereNotNull('user_id')
            ->when($user, fn ($query) => $query->where('user_id', '!=', $user->id))
            ->exists();

        if ($linkedToOther) {
            throw ValidationException::withMessages([
                'employee_id' => 'That employee is already linked to another user.',
            ]);
        }
    }

    protected function syncEmployeeLink(User $user, ?int $employeeId): void
    {
        Employee::where('user_id', $user->id)->update(['user_id' => null]);

        if ($employeeId) {
            Employee::whereKey($employeeId)->update(['user_id' => $user->id]);
        }
    }
}
