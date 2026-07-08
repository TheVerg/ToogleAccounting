<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Employee;
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
            'employees' => Employee::where('company_id', $companyId)
                ->with('user:id,name,email')
                ->withCount('components')
                ->orderBy('employee_no')->get(),
            'linkableUsers' => $this->linkableUsers(),
        ]);
    }

    public function show(Request $request, Employee $employee): Response
    {
        abort_unless($employee->company_id === $request->user()->current_company_id, 403);

        $companyId = $request->user()->current_company_id;

        return Inertia::render('Employees/Show', [
            'employee' => $employee->load('components', 'paymentAccount:id,code,name', 'user:id,name,email', 'attachments:id,attachable_id,attachable_type,original_name,created_at'),
            'moneyAccounts' => Account::where('company_id', $companyId)
                ->whereIn('subtype', Account::MONEY_SUBTYPES)->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'linkableUsers' => $this->linkableUsers($employee->user_id),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = $request->user()->current_company_id;
        $data = $this->validated($request, $companyId);

        DB::transaction(function () use ($companyId, $data, $request) {
            Employee::create($data + [
                'company_id' => $companyId,
                'employee_no' => $this->nextEmployeeNo($companyId),
                'created_by' => $request->user()->id,
            ]);
        });

        return back()->with('success', 'Employee added.');
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless($employee->company_id === $request->user()->current_company_id, 403);

        $employee->update($this->validated($request, $employee->company_id));

        return back()->with('success', 'Employee updated.');
    }

    public function storeContract(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless($employee->company_id === $request->user()->current_company_id, 403);

        $request->validate([
            'contract' => ['required', 'file', 'max:10240'],
        ]);

        Attachment::storeFor($employee, $request->file('contract'), $employee->company_id, $request->user()->id);

        return back()->with('success', 'Employment contract attached.');
    }

    public function destroy(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless($employee->company_id === $request->user()->current_company_id, 403);

        if (PayrollItem::where('employee_id', $employee->id)->exists()) {
            return back()->with('error', $employee->full_name.' appears on payroll runs. Deactivate the employee instead of deleting.');
        }

        $employee->forceFill(['deleted_by' => $request->user()->id])->save();
        $employee->delete();

        return back()->with('success', 'Employee deleted.');
    }

    public function storeComponent(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless($employee->company_id === $request->user()->current_company_id, 403);

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
        abort_unless($employee->company_id === $request->user()->current_company_id, 403);
        abort_unless($component->employee_id === $employee->id, 404);

        $component->delete();

        return back()->with('success', 'Salary structure item removed.');
    }

    protected function validated(Request $request, int $companyId): array
    {
        return $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id'),
                Rule::unique('employees', 'user_id')->ignore($request->route('employee')),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'national_id' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
            'contract_type' => ['required', Rule::in(Employee::CONTRACT_TYPES)],
            'pay_frequency' => ['required', Rule::in(Employee::PAY_FREQUENCIES)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'base_salary' => ['required', 'numeric', 'min:0'],
            'payment_account_id' => ['nullable', 'integer', CompanyRules::moneyAccount($companyId)],
            'is_active' => ['boolean'],
        ]);
    }

    protected function nextEmployeeNo(int $companyId): string
    {
        $seq = Employee::withTrashed()->where('company_id', $companyId)->lockForUpdate()->count() + 1;

        return 'EMP-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
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
}
