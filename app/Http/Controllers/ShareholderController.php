<?php

namespace App\Http\Controllers;

use App\Models\EquityTransaction;
use App\Models\Shareholder;
use App\Models\ShareholderLoan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ShareholderController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = $request->user()->current_company_id;

        $shareholders = Shareholder::where('company_id', $companyId)
            ->orderBy('name')->get();

        $totalShares = (float) $shareholders->where('is_active', true)->sum('shares_held');

        $loanTotals = ShareholderLoan::where('company_id', $companyId)
            ->where('status', '!=', 'reversed')
            ->whereNotNull('shareholder_id')
            ->groupBy('shareholder_id')
            ->selectRaw('shareholder_id, COALESCE(SUM(principal),0) as lent, COALESCE(SUM(outstanding_balance),0) as outstanding')
            ->get()->keyBy('shareholder_id');

        $issueTotals = EquityTransaction::where('company_id', $companyId)
            ->where('type', 'share_issue')
            ->whereNull('reversed_at')
            ->whereNotNull('shareholder_id')
            ->groupBy('shareholder_id')
            ->selectRaw('shareholder_id, COALESCE(SUM(amount),0) as invested, COALESCE(SUM(shares_count),0) as shares_issued')
            ->get()->keyBy('shareholder_id');

        return Inertia::render('Shareholders/Index', [
            'shareholders' => $shareholders->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'email' => $s->email,
                'phone' => $s->phone,
                'shares_held' => (float) $s->shares_held,
                'percent' => $s->is_active && $totalShares > 0
                    ? round((float) $s->shares_held / $totalShares * 100, 2)
                    : null,
                'invested' => round((float) ($issueTotals[$s->id]->invested ?? 0), 2),
                'shares_issued' => (float) ($issueTotals[$s->id]->shares_issued ?? 0),
                'loans_lent' => round((float) ($loanTotals[$s->id]->lent ?? 0), 2),
                'loans_outstanding' => round((float) ($loanTotals[$s->id]->outstanding ?? 0), 2),
                'notes' => $s->notes,
                'is_active' => $s->is_active,
            ])->values(),
            'summary' => [
                'count' => $shareholders->where('is_active', true)->count(),
                'total_shares' => $totalShares,
                'loans_outstanding' => round((float) $loanTotals->sum('outstanding'), 2),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = $request->user()->current_company_id;

        $data = $request->validate($this->rules($companyId));

        Shareholder::create($data + ['company_id' => $companyId]);

        return back()->with('success', 'Shareholder added to the register.');
    }

    public function update(Request $request, Shareholder $shareholder): RedirectResponse
    {
        $companyId = $request->user()->current_company_id;
        abort_unless($shareholder->company_id === $companyId, 403);

        $data = $request->validate($this->rules($companyId, $shareholder->id));

        $shareholder->update($data);

        return back()->with('success', 'Shareholder updated.');
    }

    public function destroy(Request $request, Shareholder $shareholder): RedirectResponse
    {
        abort_unless($shareholder->company_id === $request->user()->current_company_id, 403);

        $outstanding = (float) $shareholder->loans()->where('status', 'active')->sum('outstanding_balance');
        if ($outstanding > 0) {
            return back()->with('error', 'This shareholder has active loans outstanding; settle or omit those first.');
        }

        $shareholder->delete();

        return back()->with('success', 'Shareholder removed from the register. Their historical transactions are kept.');
    }

    protected function rules(int $companyId, ?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255',
                Rule::unique('shareholders', 'name')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->ignore($ignoreId)],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'shares_held' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
