<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\ComplianceAdjustment;
use App\Models\ComplianceDeadline;
use App\Models\ComplianceReturn;
use App\Models\ComplianceTask;
use App\Services\Compliance\ComplianceWorkspaceService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ComplianceController extends Controller
{
    public function index(Request $request, ComplianceWorkspaceService $workspace): Response
    {
        $company = $request->user()->currentCompany;
        $filters = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'return_id' => ['nullable', 'integer'],
        ]);
        $from = $filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? now()->endOfMonth()->toDateString();
        $selected = isset($filters['return_id'])
            ? ComplianceReturn::where('company_id', $company->id)->with(['adjustments', 'events.user', 'attachments'])->findOrFail($filters['return_id'])
            : null;
        if ($selected) {
            $from = $selected->period_from->toDateString();
            $to = $selected->period_to->toDateString();
        }

        return Inertia::render('Compliance/Index', [
            'filters' => compact('from', 'to'),
            'workspace' => $selected && ! in_array($selected->status, ['draft'], true) && $selected->snapshot
                ? $selected->snapshot
                : $workspace->build($company, $from, $to, $selected),
            'returns' => ComplianceReturn::where('company_id', $company->id)->latest('period_to')->latest('id')->get(),
            'selectedReturn' => $selected,
            'deadlines' => ComplianceDeadline::where('company_id', $company->id)
                ->with('assignee:id,name')->orderBy('due_date')->get(),
            'tasks' => ComplianceTask::where('company_id', $company->id)->with('assignee:id,name')->latest()->get(),
            'users' => $company->users()->orderBy('name')->get(['users.id', 'users.name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $company = $request->user()->currentCompany;
        $data = $request->validate([
            'return_type' => ['required', Rule::in(['VAT7', 'PAYE-P2', 'QPD', 'ITF12C'])],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'due_date' => ['required', 'date', 'after_or_equal:period_to'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $existing = ComplianceReturn::query()
            ->where('company_id', $company->id)
            ->where('return_type', $data['return_type'])
            ->whereDate('period_from', $data['period_from'])
            ->whereDate('period_to', $data['period_to'])
            ->where('amendment_no', 0)
            ->first();
        if ($existing) {
            return redirect()->route('compliance.index', ['return_id' => $existing->id])
                ->with('error', 'A '.$data['return_type'].' return already exists for this reporting period. The existing return has been opened.');
        }

        try {
            $return = DB::transaction(function () use ($company, $data, $request) {
                $return = ComplianceReturn::create($data + ['company_id' => $company->id]);
                $return->events()->create(['action' => 'created', 'to_status' => 'draft', 'user_id' => $request->user()->id]);
                ComplianceDeadline::updateOrCreate(
                    ['company_id' => $company->id, 'return_type' => $data['return_type'], 'period_from' => $data['period_from'], 'period_to' => $data['period_to']],
                    ['due_date' => $data['due_date'], 'compliance_return_id' => $return->id, 'status' => 'open']
                );
                return $return;
            });
        } catch (UniqueConstraintViolationException) {
            $existing = ComplianceReturn::query()
                ->where('company_id', $company->id)
                ->where('return_type', $data['return_type'])
                ->whereDate('period_from', $data['period_from'])
                ->whereDate('period_to', $data['period_to'])
                ->where('amendment_no', 0)
                ->firstOrFail();

            return redirect()->route('compliance.index', ['return_id' => $existing->id])
                ->with('error', 'A '.$data['return_type'].' return already exists for this reporting period. The existing return has been opened.');
        }

        return redirect()->route('compliance.index', ['return_id' => $return->id])->with('success', 'Compliance return workspace created.');
    }

    public function addAdjustment(Request $request, ComplianceReturn $complianceReturn): RedirectResponse
    {
        $this->authorizeReturn($request, $complianceReturn);
        if ($complianceReturn->status !== 'draft') {
            return back()->with('error', 'Adjustments are locked after preparation. Reopen the return before changing adjustments.');
        }
        $data = $request->validate([
            'currency' => ['required', 'string', 'size:3'],
            'category' => ['required', Rule::in(['imported_goods', 'imported_services', 'vat_withholding', 'output_adjustment', 'input_adjustment', 'other'])],
            'description' => ['required', 'string', 'max:255'],
            'tax_amount' => ['required', 'numeric', 'between:-999999999999.99,999999999999.99'],
        ]);
        $complianceReturn->adjustments()->create($data + ['currency' => strtoupper($data['currency']), 'created_by' => $request->user()->id]);
        return back()->with('success', 'VAT adjustment added.');
    }

    public function deleteAdjustment(Request $request, ComplianceReturn $complianceReturn, ComplianceAdjustment $adjustment): RedirectResponse
    {
        $this->authorizeReturn($request, $complianceReturn);
        if ($adjustment->compliance_return_id !== $complianceReturn->id || $complianceReturn->status !== 'draft') {
            return back()->with('error', 'This adjustment cannot be removed because it does not belong to this draft return or the return is locked.');
        }
        $adjustment->delete();
        return back()->with('success', 'Adjustment removed.');
    }

    public function transition(Request $request, ComplianceReturn $complianceReturn, ComplianceWorkspaceService $workspace): RedirectResponse
    {
        $this->authorizeReturn($request, $complianceReturn);
        $data = $request->validate([
            'action' => ['required', Rule::in(['prepare', 'review', 'approve', 'file', 'reopen'])],
            'comment' => ['nullable', 'string', 'max:1000'],
            'filing_reference' => ['nullable', 'string', 'max:255'],
            'acknowledgement' => ['nullable', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg'],
        ]);
        $action = $data['action'];
        if (in_array($action, ['review', 'approve', 'file'], true)) {
            abort_unless($request->user()->can('compliance.review'), 403);
        }
        $map = [
            'prepare' => ['draft', 'prepared'], 'review' => ['prepared', 'reviewed'],
            'approve' => ['reviewed', 'approved'], 'file' => ['approved', 'filed'],
            'reopen' => ['prepared', 'draft'],
        ];
        [$expected, $next] = $map[$action];
        if ($complianceReturn->status !== $expected) {
            return back()->with('error', "Return must be {$expected} before it can be {$action}d.");
        }
        if (in_array($action, ['review', 'approve'], true)) {
            if ((int) $complianceReturn->prepared_by === (int) $request->user()->id) {
                return back()->with('error', 'Maker-checker control requires another user to review and approve this return.');
            }
        }
        if ($action === 'file') {
            if (blank($data['filing_reference'] ?? null)) {
                return back()->with('error', 'Enter the TaRMS filing or acknowledgement reference before marking the return as filed.');
            }
        }

        $snapshot = null;
        if ($action === 'prepare') {
            $snapshot = $workspace->build(
                $complianceReturn->company,
                $complianceReturn->period_from->toDateString(),
                $complianceReturn->period_to->toDateString(),
                $complianceReturn,
            );
            $blockers = collect($snapshot['exceptions'])->where('severity', 'blocking');
            if ($blockers->isNotEmpty()) {
                $references = $blockers->pluck('reference')->unique()->implode(', ');

                return back()->with('error',
                    'Preparation blocked by '.$blockers->count().' reconciliation exception(s): '.$references.'. Open the Exceptions tab to resolve them.'
                );
            }
        }

        DB::transaction(function () use ($request, $complianceReturn, $action, $next, $data, $snapshot) {
            $old = $complianceReturn->status;
            $updates = ['status' => $next];
            if ($action === 'prepare') {
                $updates += ['snapshot' => $snapshot, 'prepared_by' => $request->user()->id, 'prepared_at' => now()];
            } elseif ($action === 'review') {
                $updates += ['reviewed_by' => $request->user()->id, 'reviewed_at' => now()];
            } elseif ($action === 'approve') {
                $updates += ['approved_by' => $request->user()->id, 'approved_at' => now()];
                $lockDate = $complianceReturn->period_to->copy()->addDay();
                if (! $complianceReturn->company->locked_before || $lockDate->gt($complianceReturn->company->locked_before)) {
                    $complianceReturn->company->update(['locked_before' => $lockDate]);
                }
            } elseif ($action === 'file') {
                $updates += ['filed_by' => $request->user()->id, 'filed_at' => now(), 'filing_reference' => $data['filing_reference']];
                ComplianceDeadline::where('compliance_return_id', $complianceReturn->id)->update(['status' => 'completed', 'completed_at' => now()]);
            }
            $complianceReturn->update($updates);
            $complianceReturn->events()->create(['action' => $action, 'from_status' => $old, 'to_status' => $next, 'comment' => $data['comment'] ?? null, 'user_id' => $request->user()->id]);
        });
        if ($request->hasFile('acknowledgement')) {
            Attachment::storeFor($complianceReturn, $request->file('acknowledgement'), $complianceReturn->company_id, $request->user()->id);
        }
        return back()->with('success', 'Return moved to '.$next.'.');
    }

    public function storeDeadline(Request $request): RedirectResponse
    {
        $company = $request->user()->currentCompany;
        $data = $request->validate([
            'return_type' => ['required', 'string', 'max:20'], 'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'], 'due_date' => ['required', 'date'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('company_user', 'user_id')->where('company_id', $company->id)],
        ]);
        ComplianceDeadline::updateOrCreate(
            ['company_id' => $company->id, 'return_type' => strtoupper($data['return_type']), 'period_from' => $data['period_from'], 'period_to' => $data['period_to']],
            $data + ['company_id' => $company->id]
        );
        return back()->with('success', 'Compliance deadline saved.');
    }

    public function amend(Request $request, ComplianceReturn $complianceReturn): RedirectResponse
    {
        $this->authorizeReturn($request, $complianceReturn);
        if (! in_array($complianceReturn->status, ['approved', 'filed'], true)) {
            return back()->with('error', 'Only approved or filed returns can be amended.');
        }
        $amendment = DB::transaction(function () use ($request, $complianceReturn) {
            $next = ComplianceReturn::where('company_id', $complianceReturn->company_id)
                ->where('return_type', $complianceReturn->return_type)
                ->whereDate('period_from', $complianceReturn->period_from)
                ->whereDate('period_to', $complianceReturn->period_to)->max('amendment_no') + 1;
            $amendment = ComplianceReturn::create([
                'company_id' => $complianceReturn->company_id, 'return_type' => $complianceReturn->return_type,
                'period_from' => $complianceReturn->period_from, 'period_to' => $complianceReturn->period_to,
                'due_date' => now()->addDays(7), 'status' => 'draft', 'amendment_no' => $next,
                'amends_return_id' => $complianceReturn->id, 'notes' => 'Amendment to return #'.$complianceReturn->id,
            ]);
            foreach ($complianceReturn->adjustments as $adjustment) {
                $amendment->adjustments()->create($adjustment->only(['currency', 'category', 'description', 'tax_amount']) + ['created_by' => $request->user()->id]);
            }
            $amendment->events()->create(['action' => 'amendment_created', 'to_status' => 'draft', 'comment' => 'Amends return #'.$complianceReturn->id, 'user_id' => $request->user()->id]);
            return $amendment;
        });
        return redirect()->route('compliance.index', ['return_id' => $amendment->id])->with('success', 'Amendment workspace created.');
    }

    public function storeTask(Request $request): RedirectResponse
    {
        $company = $request->user()->currentCompany;
        $data = $request->validate([
            'compliance_return_id' => ['nullable', Rule::exists('compliance_returns', 'id')->where('company_id', $company->id)],
            'type' => ['required', Rule::in(['missing_document', 'client_query', 'checklist'])],
            'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'],
            'due_date' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('company_user', 'user_id')->where('company_id', $company->id)],
        ]);
        ComplianceTask::create($data + ['company_id' => $company->id, 'requested_by' => $request->user()->id]);
        return back()->with('success', 'Compliance task created.');
    }

    public function completeTask(Request $request, ComplianceTask $task): RedirectResponse
    {
        abort_unless($task->company_id === $request->user()->current_company_id, 403);
        $task->update(['status' => 'completed', 'completed_at' => now()]);
        return back()->with('success', 'Compliance task completed.');
    }

    protected function authorizeReturn(Request $request, ComplianceReturn $return): void
    {
        abort_unless($return->company_id === $request->user()->current_company_id, 403);
    }
}
