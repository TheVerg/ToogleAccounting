<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\SupportQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform admin inbox: queries from every business. The tenant global
 * scope is bypassed so all tenants' queries are visible and updatable.
 */
class QueryController extends Controller
{
    public function index(Request $request): Response
    {
        $queries = SupportQuery::withoutGlobalScope('tenant')
            ->with(['tenant:id,name', 'user'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(fn ($q) => [
                'id' => $q->id,
                'business' => $q->tenant?->name,
                'user' => $q->user?->name,
                'user_email' => $q->user?->email,
                'type' => $q->type,
                'subject' => $q->subject,
                'message' => $q->message,
                'status' => $q->status,
                'admin_response' => $q->admin_response,
                'created_at' => $q->created_at->format('d M Y H:i'),
            ]);

        return Inertia::render('Platform/Queries', [
            'queries' => $queries,
            'filters' => ['status' => $request->query('status', '')],
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $query = SupportQuery::withoutGlobalScope('tenant')->findOrFail($id);

        $data = $request->validate([
            'status' => ['required', Rule::in(SupportQuery::STATUSES)],
            'admin_response' => ['nullable', 'string', 'max:5000'],
        ]);

        $responseChanged = ($data['admin_response'] ?? null) !== null
            && $data['admin_response'] !== $query->admin_response;

        $query->update([
            ...$data,
            'responded_at' => $responseChanged ? now() : $query->responded_at,
        ]);

        return back()->with('success', 'Query updated.');
    }
}
