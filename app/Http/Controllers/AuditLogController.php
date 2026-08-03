<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $companyIds = $user->accessibleCompanies()->pluck('id')->all();

        // Each tenant reviews only its own activity; within the tenant,
        // non-admins are further limited to their companies.
        $tenantFilter = fn ($query) => $query
            ->when($user->tenant_id, fn ($q) => $q->where('tenant_id', $user->tenant_id));

        $actions = AuditLog::query()
            ->tap($tenantFilter)
            ->when(! $user->isSystemAdmin(), fn ($query) => $query->whereIn('company_id', $companyIds))
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        $subjects = AuditLog::query()
            ->tap($tenantFilter)
            ->when(! $user->isSystemAdmin(), fn ($query) => $query->whereIn('company_id', $companyIds))
            ->whereNotNull('auditable_type')
            ->distinct()
            ->orderBy('auditable_type')
            ->pluck('auditable_type')
            ->map(fn ($type) => ['value' => $type, 'label' => Str::headline(class_basename($type))])
            ->values();

        $filters = $request->validate([
            'action' => ['nullable', 'string', Rule::in($actions->all())],
            'company_id' => ['nullable', 'integer', Rule::in($companyIds)],
            'subject' => ['nullable', 'string', Rule::in($subjects->pluck('value')->all())],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $logs = AuditLog::query()
            ->tap($tenantFilter)
            ->with(['user:id,name,email', 'company:id,name'])
            ->when(! $user->isSystemAdmin(), fn ($query) => $query->whereIn('company_id', $companyIds))
            ->when($filters['action'] ?? null, fn ($query, $action) => $query->where('action', $action))
            ->when($filters['company_id'] ?? null, fn ($query, $companyId) => $query->where('company_id', $companyId))
            ->when($filters['subject'] ?? null, fn ($query, $subject) => $query->where('auditable_type', $subject))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('created_at', '<=', $to))
            ->latest('created_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'subject_type' => $log->auditable_type,
                'subject_label' => $log->auditable_type ? Str::headline(class_basename($log->auditable_type)) : 'System',
                'subject_id' => $log->auditable_id,
                'changes' => $log->changes,
                'created_at' => $log->created_at,
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                ] : null,
                'company' => $log->company ? [
                    'id' => $log->company->id,
                    'name' => $log->company->name,
                ] : null,
            ]);

        return Inertia::render('Admin/AuditLogs', [
            'logs' => $logs,
            'filters' => [
                'action' => $filters['action'] ?? '',
                'company_id' => $filters['company_id'] ?? '',
                'subject' => $filters['subject'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'actions' => $actions,
            'subjects' => $subjects,
            'companies' => Company::whereIn('id', $companyIds)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }
}
