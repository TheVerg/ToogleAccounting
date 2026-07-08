<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Budget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = $request->user()->current_company_id;

        return Inertia::render('Budgets/Index', [
            'budgets' => Budget::where('company_id', $companyId)
                ->withCount('lines')
                ->orderByDesc('fiscal_year')->get(),
            'accounts' => Account::where('company_id', $companyId)
                ->whereIn('type', ['income', 'expense'])->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name', 'type']),
        ]);
    }

    public function show(Request $request, Budget $budget): Response
    {
        abort_unless($budget->company_id === $request->user()->current_company_id, 403);

        return Inertia::render('Budgets/Edit', [
            'budget' => $budget->load('lines'),
            'accounts' => Account::where('company_id', $budget->company_id)
                ->whereIn('type', ['income', 'expense'])->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name', 'type']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = $request->user()->current_company_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255',
                Rule::unique('budgets', 'name')
                    ->where('company_id', $companyId)
                    ->where('fiscal_year', $request->integer('fiscal_year'))],
            'fiscal_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'notes' => ['nullable', 'string'],
        ]);

        $budget = Budget::create($data + [
            'company_id' => $companyId,
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('budgets.show', $budget)->with('success', 'Budget created — now set the amounts.');
    }

    /** Replace the full monthly grid for this budget. */
    public function updateLines(Request $request, Budget $budget): RedirectResponse
    {
        abort_unless($budget->company_id === $request->user()->current_company_id, 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(['draft', 'active', 'archived'])],
            'lines' => ['array'],
            'lines.*.account_id' => ['required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $budget->company_id)],
            'lines.*.month' => ['required', 'integer', 'min:1', 'max:12'],
            'lines.*.amount' => ['required', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($budget, $data) {
            $budget->update(['status' => $data['status']]);
            $budget->lines()->delete();
            foreach ($data['lines'] ?? [] as $line) {
                if ((float) $line['amount'] > 0) {
                    $budget->lines()->create($line);
                }
            }
        });

        return back()->with('success', 'Budget saved.');
    }

    public function destroy(Request $request, Budget $budget): RedirectResponse
    {
        abort_unless($budget->company_id === $request->user()->current_company_id, 403);

        if ($budget->status !== 'draft') {
            return back()->with('error', 'Only draft budgets can be deleted.');
        }

        $budget->delete();

        return redirect()->route('budgets.index')->with('success', 'Budget deleted.');
    }
}
