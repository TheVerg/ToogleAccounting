<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\FinancialStatement;
use App\Services\StatementSnapshotService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The statement register: generated statements saved as frozen
 * snapshots with a draft -> approved lifecycle. Approved statements
 * are locked; drafts can be soft deleted, while approved statements
 * remain view-only in the register.
 */
class StatementRegisterController extends ReportController
{
    public function register(Request $request): Response
    {
        $companyId = $request->user()->current_company_id;
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(array_keys(FinancialStatement::TYPES))],
            'status' => ['nullable', Rule::in(['draft', 'approved', 'deleted'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $statements = FinancialStatement::withTrashed()
            ->where('company_id', $companyId)
            ->with(['generator:id,name', 'approver:id,name', 'deleter:id,name'])
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['status'] ?? null, function ($query, $status) {
                if ($status === 'deleted') {
                    $query->onlyTrashed();
                } else {
                    $query->whereNull('deleted_at')->where('status', $status);
                }
            })
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('period_to', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('period_to', '<=', $to))
            ->orderByDesc('period_to')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Reports/Register', [
            'statements' => $statements,
            'types' => FinancialStatement::TYPES,
            'filters' => [
                'type' => $filters['type'] ?? '',
                'status' => $filters['status'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'canApprove' => $request->user()->can('companies.manage'),
        ]);
    }

    public function store(Request $request, StatementSnapshotService $snapshots): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(FinancialStatement::TYPES))],
            'scope' => ['required', Rule::in(['current', 'consolidated'])],
            'from' => ['nullable', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $request->merge(['scope' => $data['scope']]);
        [$ids, $label] = $this->scope($request);
        $company = $request->user()->currentCompany;

        $snapshot = $snapshots->build($data['type'], $ids, $data['from'] ?? null, $data['to'], $company);

        $statement = FinancialStatement::create([
            'company_id' => $company->id,
            'type' => $data['type'],
            'name' => FinancialStatement::TYPES[$data['type']].' — '.$label.' — '.$data['to'],
            'scope_label' => $label,
            'period_from' => $data['from'] ?? null,
            'period_to' => $data['to'],
            'status' => 'draft',
            'columns' => $snapshot['columns'],
            'lines' => $snapshot['lines'],
            'generated_by' => $request->user()->id,
        ]);

        // Generated from a statement type page? Return there with it selected.
        if ($request->boolean('stay')) {
            return redirect('/statements/'.$data['type'].'?statement='.$statement->id)
                ->with('success', $statement->name.' generated (draft).');
        }

        return redirect()->route('statements.register.show', $statement)
            ->with('success', $statement->name.' generated (draft).');
    }

    public function show(Request $request, FinancialStatement $financialStatement): Response
    {
        abort_unless($financialStatement->company_id === $request->user()->current_company_id, 403);

        return Inertia::render('Reports/RegisterShow', [
            'statement' => $financialStatement->load(['generator:id,name', 'approver:id,name']),
            'canApprove' => $request->user()->can('companies.manage'),
        ]);
    }

    public function approve(Request $request, FinancialStatement $financialStatement): RedirectResponse
    {
        abort_unless($financialStatement->company_id === $request->user()->current_company_id, 403);

        if ($financialStatement->isApproved()) {
            return back()->with('error', 'This statement is already approved.');
        }

        // Only one approved statement per type and period.
        $duplicate = FinancialStatement::where('company_id', $financialStatement->company_id)
            ->where('type', $financialStatement->type)
            ->where('status', 'approved')
            ->whereDate('period_to', $financialStatement->period_to)
            ->where('scope_label', $financialStatement->scope_label)
            ->exists();
        if ($duplicate) {
            return back()->with('error', 'An approved '.FinancialStatement::TYPES[$financialStatement->type].' already exists for this period. Remove it first.');
        }

        $financialStatement->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);
        AuditLog::record('statement_approved', $financialStatement);

        return back()->with('success', $financialStatement->name.' approved and locked.');
    }

    public function destroy(Request $request, FinancialStatement $financialStatement): RedirectResponse
    {
        abort_unless($financialStatement->company_id === $request->user()->current_company_id, 403);

        if ($financialStatement->isApproved()) {
            return back()->with('error', 'Approved statements are locked and cannot be deleted. You can only view them.');
        }

        $financialStatement->forceFill(['deleted_by' => $request->user()->id])->save();
        $financialStatement->delete(); // soft delete — stays in the register with deleted_at

        return redirect()->route('statements.register')
            ->with('success', $financialStatement->name.' deleted (kept in the register with a deletion record).');
    }
}
