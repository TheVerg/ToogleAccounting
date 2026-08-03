<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\EquityTransaction;
use App\Models\Shareholder;
use App\Services\AccountingService;
use App\Support\CompanyRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class EquityController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = $request->user()->current_company_id;

        $transactions = EquityTransaction::where('company_id', $companyId)
            ->with(['moneyAccount:id,code,name', 'equityAccount:id,code,name',
                'attachments:id,attachable_id,attachable_type,original_name'])
            ->latest('date')->latest('id')
            ->paginate(15)->withQueryString();

        $contributions = (float) EquityTransaction::where('company_id', $companyId)
            ->where('type', 'contribution')->sum('amount');
        $drawings = (float) EquityTransaction::where('company_id', $companyId)
            ->where('type', 'drawing')->sum('amount');
        $shareIssues = EquityTransaction::where('company_id', $companyId)
            ->where('type', 'share_issue')
            ->selectRaw('COALESCE(SUM(share_capital_amount),0) as capital')
            ->selectRaw('COALESCE(SUM(share_premium_amount),0) as premium')
            ->selectRaw('COALESCE(SUM(shares_count),0) as shares')
            ->first();

        $shareCapital = round((float) $shareIssues->capital, 2);
        $sharePremium = round((float) $shareIssues->premium, 2);

        return Inertia::render('Equity/Index', [
            'transactions' => $transactions,
            'summary' => [
                'contributions' => round($contributions, 2),
                'drawings' => round($drawings, 2),
                'share_capital' => $shareCapital,
                'share_premium' => $sharePremium,
                'shares_issued' => (int) $shareIssues->shares,
                'net' => round($contributions - $drawings + $shareCapital + $sharePremium, 2),
            ],
            'moneyAccounts' => Account::where('company_id', $companyId)
                ->whereIn('subtype', ['cash', 'bank', 'petty_cash'])->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name', 'currency']),
            'shareholders' => Shareholder::where('company_id', $companyId)
                ->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, AccountingService $accounting): RedirectResponse
    {
        $user = $request->user();
        $companyId = $user->current_company_id;

        $data = $request->validate([
            'type' => ['required', Rule::in(['contribution', 'drawing', 'share_issue'])],
            'date' => ['required', 'date'],
            'owner_name' => ['required_unless:type,share_issue', 'nullable', 'string', 'max:255'],
            'shareholder_id' => ['required_if:type,share_issue', 'nullable', 'integer',
                Rule::exists('shareholders', 'id')->where('company_id', $companyId)
                    ->where('is_active', true)->whereNull('deleted_at')],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'shares_count' => ['required_if:type,share_issue', 'nullable', 'integer', 'min:1'],
            'share_capital_amount' => ['required_if:type,share_issue', 'nullable', 'numeric', 'min:0.01', 'lte:amount'],
            'money_account_id' => ['required', 'integer', CompanyRules::moneyAccount($companyId)],
            'description' => ['nullable', 'string'],
            'document' => CompanyRules::supportingDocument(),
        ]);

        try {
            $tx = $accounting->recordEquityTransaction($user->currentCompany, $data, $user->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['date' => $e->getMessage()]);
        }

        Attachment::storeFor($tx, $request->file('document'), $companyId, $user->id);

        return back()->with('success', match ($data['type']) {
            'contribution' => "Owner's contribution recorded.",
            'drawing' => "Owner's drawing recorded.",
            'share_issue' => 'Share issue recorded across share capital and share premium.',
        });
    }
}
