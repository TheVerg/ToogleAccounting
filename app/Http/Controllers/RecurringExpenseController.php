<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Contact;
use App\Models\RecurringExpense;
use App\Models\TaxRate;
use App\Services\AccountingService;
use App\Support\CompanyRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class RecurringExpenseController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = $request->user()->current_company_id;
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'paused'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return Inertia::render('Expenses/Recurring', [
            'recurring' => RecurringExpense::where('company_id', $companyId)
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('is_active', $status === 'active'))
                ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('next_run_date', '>=', $from))
                ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('next_run_date', '<=', $to))
                ->with(['account:id,code,name', 'paidFromAccount:id,code,name', 'contact:id,name'])
                ->withCount('expenses')
                ->orderBy('name')->get(),
            'expenseAccounts' => Account::where('company_id', $companyId)
                ->where('type', 'expense')->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'moneyAccounts' => Account::where('company_id', $companyId)
                ->whereIn('subtype', ['cash', 'bank', 'petty_cash'])->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'vendors' => Contact::where('company_id', $companyId)
                ->whereIn('type', ['vendor', 'both'])->where('is_active', true)
                ->orderBy('name')->get(['id', 'name']),
            'taxRates' => TaxRate::where('is_active', true)
                ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
                ->get(['id', 'name', 'rate']),
            'dueCount' => RecurringExpense::where('company_id', $companyId)
                ->where('is_active', true)
                ->whereDate('next_run_date', '<=', now()->toDateString())
                ->count(),
            'filters' => [
                'status' => $filters['status'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = $request->user()->current_company_id;
        $data = $this->validated($request, $companyId);
        $data['next_run_date'] = $data['start_date'];

        RecurringExpense::create($data + ['company_id' => $companyId]);

        return back()->with('success', 'Recurring expense created.');
    }

    public function update(Request $request, RecurringExpense $recurringExpense): RedirectResponse
    {
        abort_unless($recurringExpense->company_id === $request->user()->current_company_id, 403);

        $data = $this->validated($request, $recurringExpense->company_id);
        $recurringExpense->update($data);

        return back()->with('success', 'Recurring expense updated.');
    }

    /** Generate real expenses for every schedule that is due. */
    public function runDue(Request $request, AccountingService $accounting): RedirectResponse
    {
        $user = $request->user();
        $company = $user->currentCompany;

        $due = RecurringExpense::where('company_id', $company->id)
            ->where('is_active', true)
            ->whereDate('next_run_date', '<=', now()->toDateString())
            ->get();

        $generated = 0;
        foreach ($due as $schedule) {
            // Lock the schedule row so concurrent runs cannot generate the
            // same occurrence twice; the due conditions are re-checked on
            // the locked, fresh copy.
            try {
                $generated += DB::transaction(function () use ($schedule, $company, $accounting, $user) {
                    $schedule = RecurringExpense::whereKey($schedule->id)->lockForUpdate()->first();
                    $count = 0;

                    while ($schedule->is_active
                        && $schedule->next_run_date->lte(now())
                        && (! $schedule->end_date || $schedule->next_run_date->lte($schedule->end_date))) {

                        $taxAmount = $schedule->taxRate
                            ? round((float) $schedule->amount * ((float) $schedule->taxRate->rate / 100), 2)
                            : 0;

                        $accounting->recordExpense($company, [
                            'date' => $schedule->next_run_date->toDateString(),
                            'account_id' => $schedule->account_id,
                            'paid_from_account_id' => $schedule->paid_from_account_id,
                            'amount' => (float) $schedule->amount,
                            'tax_rate_id' => $schedule->tax_rate_id,
                            'tax_amount' => $taxAmount,
                            'contact_id' => $schedule->contact_id,
                            'description' => $schedule->name.' ('.$schedule->frequency.')',
                            'recurring_expense_id' => $schedule->id,
                        ], $user->id);

                        $next = match ($schedule->frequency) {
                            'weekly' => $schedule->next_run_date->addWeek(),
                            'monthly' => $schedule->next_run_date->addMonthNoOverflow(),
                            'quarterly' => $schedule->next_run_date->addMonthsNoOverflow(3),
                            'yearly' => $schedule->next_run_date->addYear(),
                        };

                        $schedule->update([
                            'next_run_date' => $next->toDateString(),
                            'last_generated_at' => now(),
                        ]);
                        $schedule->refresh();
                        $count++;
                    }

                    return $count;
                });
            } catch (InvalidArgumentException $e) {
                return back()
                    ->with('error', 'Stopped at "'.$schedule->name.'": '.$e->getMessage()
                        .($generated ? " ({$generated} expense(s) were generated before stopping.)" : ''));
            }
        }

        return back()->with('success', $generated.' expense(s) generated from recurring schedules.');
    }

    protected function validated(Request $request, int $companyId): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'account_id' => ['required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId)->where('type', 'expense')],
            'paid_from_account_id' => ['required', 'integer', CompanyRules::moneyAccount($companyId)],
            'contact_id' => ['nullable', 'integer',
                Rule::exists('contacts', 'id')->where('company_id', $companyId)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'tax_rate_id' => ['nullable', 'integer', CompanyRules::taxRate($companyId)],
            'frequency' => ['required', Rule::in(['weekly', 'monthly', 'quarterly', 'yearly'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'is_active' => ['boolean'],
        ]);
    }
}
