<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeLoan extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'employee_id', 'loan_no', 'issue_date', 'principal',
        'repayment_amount', 'total_repaid', 'outstanding_balance', 'status',
        'notes', 'money_account_id', 'journal_entry_id', 'created_by',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'principal' => 'decimal:2',
        'repayment_amount' => 'decimal:2',
        'total_repaid' => 'decimal:2',
        'outstanding_balance' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'money_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(EmployeeLoanPayment::class);
    }
}
