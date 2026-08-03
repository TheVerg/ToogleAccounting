<?php

namespace App\Http\Controllers;

use App\Models\ComplianceDeadline;
use App\Models\ComplianceReturn;
use App\Models\ComplianceTask;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountantComplianceController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $companies = $request->user()->accessibleCompanies();
        $ids = $companies->pluck('id');
        $latest = ComplianceReturn::whereIn('company_id', $ids)->latest('period_to')->get()->unique('company_id')->keyBy('company_id');
        $deadlines = ComplianceDeadline::whereIn('company_id', $ids)->where('status', 'open')->orderBy('due_date')->get()->groupBy('company_id');
        $tasks = ComplianceTask::whereIn('company_id', $ids)->where('status', 'open')->get()->groupBy('company_id');

        return Inertia::render('Compliance/Accountant', [
            'companies' => $companies->map(function ($company) use ($latest, $deadlines, $tasks) {
                $return = $latest->get($company->id);
                $next = $deadlines->get($company->id)?->first();
                return [
                    'id' => $company->id, 'name' => $company->name, 'code' => $company->code,
                    'latest_return' => $return?->only(['id', 'return_type', 'period_to', 'status']),
                    'next_deadline' => $next?->only(['return_type', 'due_date', 'status']),
                    'open_deadlines' => $deadlines->get($company->id)?->count() ?? 0,
                    'overdue' => $deadlines->get($company->id)?->filter(fn ($d) => $d->due_date->isPast())->count() ?? 0,
                    'open_tasks' => $tasks->get($company->id)?->count() ?? 0,
                ];
            })->values(),
        ]);
    }
}
