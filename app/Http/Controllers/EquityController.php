<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\EquityTransaction;
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

        return Inertia::render('Equity/Index', [
            'transactions' => $transactions,
            'summary' => [
                'contributions' => round($contributions, 2),
                'drawings' => round($drawings, 2),
                'net' => round($contributions - $drawings, 2),
            ],
            'moneyAccounts' => Account::where('company_id', $companyId)
                ->whereIn('subtype', ['cash', 'bank', 'petty_cash'])->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, AccountingService $accounting): RedirectResponse
    {
        $user = $request->user();
        $companyId = $user->current_company_id;

        $data = $request->validate([
            'type' => ['required', Rule::in(['contribution', 'drawing'])],
            'date' => ['required', 'date'],
            'owner_name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
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

        return back()->with('success', $data['type'] === 'contribution'
            ? "Owner's contribution recorded."
            : "Owner's drawing recorded.");
    }
}
