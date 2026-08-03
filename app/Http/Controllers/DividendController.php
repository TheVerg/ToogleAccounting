<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Dividend;
use App\Models\DividendAllocation;
use App\Models\Shareholder;
use App\Services\AccountingService;
use App\Support\CompanyRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class DividendController extends Controller
{
    public function index(Request $request, AccountingService $accounting): Response
    {
        $user = $request->user();
        $companyId = $user->current_company_id;

        $dividends = Dividend::where('company_id', $companyId)
            ->with([
                'attachments:id,attachable_id,attachable_type,original_name',
                'allocations' => fn ($q) => $q->orderBy('shareholder_name')
                    ->with(['payments' => fn ($p) => $p->latest('date')->latest('id')
                        ->with(['moneyAccount:id,code,name',
                            'attachments:id,attachable_id,attachable_type,original_name'])]),
            ])
            ->latest('date')->latest('id')
            ->paginate(10)->withQueryString();

        $declared = (float) Dividend::where('company_id', $companyId)
            ->where('status', '!=', 'reversed')->sum('total_amount');
        $paid = (float) DividendAllocation::where('company_id', $companyId)
            ->whereHas('dividend', fn ($q) => $q->where('status', '!=', 'reversed'))
            ->sum('paid_amount');

        $shareholders = Shareholder::where('company_id', $companyId)
            ->where('is_active', true)->where('shares_held', '>', 0)
            ->orderBy('name')->get(['id', 'name', 'shares_held']);
        $totalShares = (float) $shareholders->sum('shares_held');

        return Inertia::render('Equity/Dividends', [
            'dividends' => $dividends,
            'summary' => [
                'distributable' => max($accounting->distributableProfit($user->currentCompany), 0),
                'declared' => round($declared, 2),
                'unpaid' => round($declared - $paid, 2),
            ],
            'shareholders' => $shareholders->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'shares_held' => (float) $s->shares_held,
                'percent' => $totalShares > 0 ? round((float) $s->shares_held / $totalShares * 100, 2) : 0,
            ])->values(),
            'moneyAccounts' => Account::where('company_id', $companyId)
                ->whereIn('subtype', Account::MONEY_SUBTYPES)->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name', 'currency']),
        ]);
    }

    public function store(Request $request, AccountingService $accounting): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'date' => ['required', 'date'],
            'total_amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string'],
            'document' => CompanyRules::supportingDocument(),
        ]);

        try {
            $dividend = $accounting->declareDividend($user->currentCompany, $data, $user->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['total_amount' => $e->getMessage()]);
        }

        Attachment::storeFor($dividend, $request->file('document'), $user->current_company_id, $user->id);

        return back()->with('success', 'Dividend declared and split across the shareholder register.');
    }

    public function storePayment(Request $request, DividendAllocation $allocation, AccountingService $accounting): RedirectResponse
    {
        $user = $request->user();
        $companyId = $user->current_company_id;
        abort_unless($allocation->company_id === $companyId, 403);

        $data = $request->validate([
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'money_account_id' => ['required', 'integer', CompanyRules::moneyAccount($companyId)],
            'reference' => ['nullable', 'string', 'max:255'],
            'document' => CompanyRules::supportingDocument(),
        ]);

        try {
            $payment = $accounting->recordDividendPayment($allocation, $data, $user->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        Attachment::storeFor($payment, $request->file('document'), $companyId, $user->id);

        return back()->with('success', 'Dividend payment recorded and posted to the ledger.');
    }
}
