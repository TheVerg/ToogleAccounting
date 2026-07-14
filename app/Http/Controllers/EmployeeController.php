<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\PayrollItem;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Support\CompanyRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = $request->user()->current_company_id;

        return Inertia::render('Employees/Index', [
            'employees' => Employee::whereHas('assignments', fn ($query) => $query->where('company_id', $companyId))
                ->with([
                    'user:id,name,email',
                    'assignments' => fn ($query) => $query->where('company_id', $companyId)->with('company:id,name'),
                ])
                ->withCount('components')
                ->orderBy('employee_no')->get(),
            'linkableUsers' => $this->linkableUsers(),
            'companies' => $request->user()->accessibleCompanies()->values(),
            'currentCompanyId' => $companyId,
        ]);
    }

    public function show(Request $request, Employee $employee): Response
    {
        $companyId = $request->user()->current_company_id;
        abort_unless($this->employeeCanBeViewedInCompany($employee, $companyId), 403);

        return Inertia::render('Employees/Show', [
            'employee' => $employee->load([
                'components',
                'assignments.company:id,name,parent_id',
                'paymentAccount:id,code,name',
                'user:id,name,email',
                'attachments:id,attachable_id,attachable_type,original_name,created_at',
            ]),
            'moneyAccounts' => Account::where('company_id', $companyId)
                ->whereIn('subtype', Account::MONEY_SUBTYPES)->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'linkableUsers' => $this->linkableUsers($employee->user_id),
            'companies' => $request->user()->accessibleCompanies()->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = $request->user()->current_company_id;
        $data = $this->validated($request, $companyId);
        $assignments = $data['assignments'];
        unset($data['assignments']);
        $defaultAssignment = collect($assignments)->firstWhere('company_id', $companyId) ?? $assignments[0];

        DB::transaction(function () use ($companyId, $data, $assignments, $defaultAssignment, $request) {
            $employee = Employee::create($data + [
                'company_id' => $companyId,
                'employee_no' => $this->nextEmployeeNo($companyId),
                'created_by' => $request->user()->id,
                'job_title' => $defaultAssignment['job_title'] ?? null,
                'department' => $defaultAssignment['department'] ?? null,
                'pay_frequency' => $defaultAssignment['pay_frequency'],
                'start_date' => $defaultAssignment['start_date'] ?? null,
                'end_date' => $defaultAssignment['end_date'] ?? null,
                'base_salary' => $defaultAssignment['base_salary'],
                'is_active' => $defaultAssignment['is_active'] ?? true,
            ]);

            foreach ($assignments as $assignment) {
                $employee->assignments()->create($assignment);
            }
        });

        return back()->with('success', 'Employee added.');
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless($this->employeeCanBeViewedInCompany($employee, $request->user()->current_company_id), 403);

        $data = $this->validateEmployee($request, $employee->company_id, includeAssignments: false);
        $employee->update($data);

        return back()->with('success', 'Employee updated.');
    }

    public function storeContract(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless($this->employeeCanBeViewedInCompany($employee, $request->user()->current_company_id), 403);

        $request->validate([
            'contract' => ['required', 'file', 'max:10240'],
        ]);

        Attachment::storeFor($employee, $request->file('contract'), $employee->company_id, $request->user()->id);

        return back()->with('success', 'Employment contract attached.');
    }

    public function storeAssignment(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless($request->user()->accessibleCompanies()->contains('id', $employee->company_id), 403);
        $data = $this->validateAssignment($request, $employee);
        $employee->assignments()->create($data);

        return back()->with('success', 'Company assignment added.');
    }

    public function updateAssignment(Request $request, Employee $employee, EmployeeAssignment $assignment): RedirectResponse
    {
        abort_unless($assignment->employee_id === $employee->id, 404);
        abort_unless($request->user()->accessibleCompanies()->contains('id', $assignment->company_id), 403);
        $assignment->update($this->validateAssignment($request, $employee, $assignment));

        return back()->with('success', 'Company assignment updated.');
    }

    public function destroyAssignment(Request $request, Employee $employee, EmployeeAssignment $assignment): RedirectResponse
    {
        abort_unless($assignment->employee_id === $employee->id, 404);
        abort_unless($request->user()->accessibleCompanies()->contains('id', $assignment->company_id), 403);

        if ($employee->assignments()->count() <= 1) {
            return back()->with('error', 'An employee must retain at least one company assignment.');
        }

        $assignment->delete();

        return back()->with('success', 'Company assignment removed.');
    }

    public function destroy(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless($this->employeeCanBeViewedInCompany($employee, $request->user()->current_company_id), 403);

        if (PayrollItem::where('employee_id', $employee->id)->exists()) {
            return back()->with('error', $employee->full_name.' appears on payroll runs. Deactivate the employee instead of deleting.');
        }

        $employee->forceFill(['deleted_by' => $request->user()->id])->save();
        $employee->delete();

        return back()->with('success', 'Employee deleted.');
    }

    public function storeComponent(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless($this->employeeCanBeViewedInCompany($employee, $request->user()->current_company_id), 403);

        $data = $request->validate([
            'kind' => ['required', Rule::in(SalaryComponent::KINDS)],
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:100'],
            'calc_type' => ['required', Rule::in(SalaryComponent::CALC_TYPES)],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        $employee->components()->create($data + ['is_active' => true]);

        return back()->with('success', 'Salary structure item added.');
    }

    public function destroyComponent(Request $request, Employee $employee, SalaryComponent $component): RedirectResponse
    {
        abort_unless($this->employeeCanBeViewedInCompany($employee, $request->user()->current_company_id), 403);
        abort_unless($component->employee_id === $employee->id, 404);

        $component->delete();

        return back()->with('success', 'Salary structure item removed.');
    }

    protected function validated(Request $request, int $companyId): array
    {
        return $this->validateEmployee($request, $companyId, includeAssignments: true);
    }

    protected function validateEmployee(Request $request, int $companyId, bool $includeAssignments): array
    {
        $rules = [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id'),
                Rule::unique('employees', 'user_id')->ignore($request->route('employee')),
            ],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'national_id' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:255'],
            'contract_type' => ['required', Rule::in(Employee::CONTRACT_TYPES)],
            'payment_account_id' => ['nullable', 'integer', CompanyRules::moneyAccount($companyId)],
        ];

        if ($includeAssignments) {
            $rules += [
                'assignments' => ['required', 'array', 'min:1'],
                'assignments.*.company_id' => ['required', 'integer', Rule::exists('companies', 'id')],
                'assignments.*.job_title' => ['nullable', 'string', 'max:120'],
                'assignments.*.department' => ['nullable', 'string', 'max:120'],
                'assignments.*.pay_frequency' => ['required', Rule::in(Employee::PAY_FREQUENCIES)],
                'assignments.*.start_date' => ['nullable', 'date'],
                'assignments.*.end_date' => ['nullable', 'date', 'after_or_equal:assignments.*.start_date'],
                'assignments.*.base_salary' => ['required', 'numeric', 'min:0'],
                'assignments.*.is_active' => ['boolean'],
            ];
        } else {
            $rules += [
                'job_title' => ['nullable', 'string', 'max:120'],
                'department' => ['nullable', 'string', 'max:120'],
                'pay_frequency' => ['required', Rule::in(Employee::PAY_FREQUENCIES)],
                'start_date' => ['nullable', 'date'],
                'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
                'base_salary' => ['required', 'numeric', 'min:0'],
                'is_active' => ['boolean'],
            ];
        }

        $data = $request->validate($rules);

        if ($includeAssignments) {
            $companyIds = collect($data['assignments'])->pluck('company_id');
            abort_if($companyIds->duplicates()->isNotEmpty(), 422, 'Each company can only be assigned once.');

            $accessibleIds = $request->user()->accessibleCompanies()->pluck('id');
            abort_if($companyIds->diff($accessibleIds)->isNotEmpty(), 403);

            $data['assignments'] = collect($data['assignments'])->map(fn ($assignment) => $assignment + ['is_active' => true])->all();
        }

        return $data;
    }

    protected function nextEmployeeNo(int $companyId): string
    {
        $seq = Employee::withTrashed()->where('company_id', $companyId)->lockForUpdate()->count() + 1;

        return 'EMP-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    protected function validateAssignment(Request $request, Employee $employee, ?EmployeeAssignment $assignment = null): array
    {
        $accessibleIds = $request->user()->accessibleCompanies()->pluck('id')->all();

        return $request->validate([
            'company_id' => [
                'required', 'integer', Rule::in($accessibleIds),
                Rule::unique('employee_assignments', 'company_id')
                    ->where('employee_id', $employee->id)->ignore($assignment),
            ],
            'job_title' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
            'pay_frequency' => ['required', Rule::in(Employee::PAY_FREQUENCIES)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'base_salary' => ['required', 'numeric', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    protected function linkableUsers(?int $currentUserId = null)
    {
        return User::query()
            ->where(function ($query) use ($currentUserId) {
                $query->whereDoesntHave('employee')
                    ->when($currentUserId, fn ($q) => $q->orWhereKey($currentUserId));
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    protected function employeeCanBeViewedInCompany(Employee $employee, int $companyId): bool
    {
        return $employee->company_id === $companyId
            || EmployeeAssignment::where('employee_id', $employee->id)->where('company_id', $companyId)->exists();
    }
}
