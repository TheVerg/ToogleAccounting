<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use Auditable, HasAttachments, SoftDeletes;

    public const CONTRACT_TYPES = ['permanent', 'contract', 'temporary'];

    public const PAY_FREQUENCIES = ['monthly', 'weekly', 'biweekly'];

    protected $fillable = [
        'company_id', 'user_id', 'employee_no', 'first_name', 'last_name', 'email', 'phone',
        'national_id', 'tax_number', 'nssa_number', 'nssa_registered', 'pay_currency',
        'address', 'job_title', 'department', 'contract_type', 'pay_frequency',
        'start_date', 'end_date', 'base_salary', 'payment_account_id', 'is_active',
        'created_by', 'deleted_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'base_salary' => 'decimal:2',
        'is_active' => 'boolean',
        'nssa_registered' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(SalaryComponent::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(EmployeeLoan::class);
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payment_account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
