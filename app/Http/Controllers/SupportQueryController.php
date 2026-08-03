<?php

namespace App\Http\Controllers;

use App\Models\SupportQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Business-side support queries: any user of a tenant can send a query,
 * improvement request, or issue to the platform admin and follow up on
 * the response. The tenant global scope keeps each business to its own.
 */
class SupportQueryController extends Controller
{
    public function index(Request $request): Response
    {
        $queries = SupportQuery::query()
            ->with('user')
            ->latest()
            ->get()
            ->map(fn ($q) => [
                'id' => $q->id,
                'type' => $q->type,
                'subject' => $q->subject,
                'message' => $q->message,
                'status' => $q->status,
                'admin_response' => $q->admin_response,
                'responded_at' => $q->responded_at?->format('d M Y'),
                'user' => $q->user?->name,
                'created_at' => $q->created_at->format('d M Y'),
            ]);

        return Inertia::render('Support/Queries', ['queries' => $queries]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(SupportQuery::TYPES)],
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        SupportQuery::create([
            ...$data,
            'user_id' => $request->user()->id,
        ]);

        return back()->with('success', 'Your query has been sent. We will get back to you.');
    }
}
