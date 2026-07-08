<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use App\Services\JournalService;
use App\Support\CompanyRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class JournalEntryController extends Controller
{
    public function index(Request $request): Response
    {
        $company = $request->user()->currentCompany;
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'posted', 'reversed'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return Inertia::render('Journal/Index', [
            'entries' => JournalEntry::where('company_id', $company->id)
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('date', '>=', $from))
                ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('date', '<=', $to))
                ->with(['lines.account:id,code,name',
                    'attachments:id,attachable_id,attachable_type,original_name'])
                ->latest('date')->latest('id')
                ->paginate(20)
                ->withQueryString(),
            'accounts' => Account::where('company_id', $company->id)
                ->where('is_active', true)->orderBy('code')
                ->get(['id', 'code', 'name', 'type']),
            'filters' => [
                'status' => $filters['status'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
        ]);
    }

    public function store(Request $request, JournalService $journal): RedirectResponse
    {
        $company = $request->user()->currentCompany;

        $data = $request->validate([
            'date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $company->id)],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
            'document' => CompanyRules::supportingDocument(),
        ]);

        try {
            $entry = $journal->post(
                $company,
                $data['date'],
                $data['description'],
                $data['lines'],
                null,
                $data['reference'] ?? null,
                $request->user()->id,
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()]);
        }

        Attachment::storeFor($entry, $request->file('document'), $company->id, $request->user()->id);

        return back()->with('success', 'Journal entry posted.');
    }

    public function reverse(Request $request, JournalEntry $entry, AccountingService $accounting): RedirectResponse
    {
        abort_unless($entry->company_id === $request->user()->current_company_id, 403);

        try {
            $accounting->reverseEntry($entry, now()->toDateString(), $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Entry reversed with an opposite entry.');
    }
}
