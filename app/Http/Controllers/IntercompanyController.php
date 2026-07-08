<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\IntercompanyTransfer;
use App\Services\AccountingService;
use App\Support\CompanyRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class IntercompanyController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $companyIds = $user->accessibleCompanies()->pluck('id');

        return Inertia::render('Intercompany/Index', [
            'transfers' => IntercompanyTransfer::where(function ($q) use ($companyIds) {
                $q->whereIn('from_company_id', $companyIds)
                    ->orWhereIn('to_company_id', $companyIds);
            })
                ->with(['fromCompany:id,name', 'toCompany:id,name',
                    'attachments:id,attachable_id,attachable_type,original_name'])
                ->latest('date')->latest('id')
                ->paginate(15)->withQueryString(),
            'companies' => $user->accessibleCompanies()->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'type' => $c->type,
            ])->values(),
            'moneyAccountsByCompany' => Account::whereIn('company_id', $companyIds)
                ->whereIn('subtype', ['cash', 'bank', 'petty_cash'])
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'company_id', 'code', 'name'])
                ->groupBy('company_id'),
        ]);
    }

    public function store(Request $request, AccountingService $accounting): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'from_company_id' => ['required', 'integer', 'exists:companies,id'],
            'to_company_id' => ['required', 'integer', 'exists:companies,id', 'different:from_company_id'],
            'type' => ['required', Rule::in(['loan', 'capital'])],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'from_money_account_id' => ['required', 'integer',
                CompanyRules::moneyAccount((int) $request->input('from_company_id'))],
            'to_money_account_id' => ['required', 'integer',
                CompanyRules::moneyAccount((int) $request->input('to_company_id'))],
            'description' => ['nullable', 'string'],
            'document' => CompanyRules::supportingDocument(),
        ]);

        abort_unless(
            $user->canAccessCompany(Company::findOrFail($data['from_company_id']))
            && $user->canAccessCompany(Company::findOrFail($data['to_company_id'])),
            403,
        );

        try {
            $transfer = $accounting->recordIntercompanyTransfer($data, $user->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['date' => $e->getMessage()]);
        }

        Attachment::storeFor($transfer, $request->file('document'), (int) $data['from_company_id'], $user->id);

        return back()->with('success', 'Intercompany '.$data['type'].' recorded in both companies\' books.');
    }
}
