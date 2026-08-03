<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tax by currency (docs/MULTICURRENCY.md Phase C): ZIMRA expects tax to
 * be declared and paid in the currency of trade — VAT collected in ZWG
 * is declared in ZWG, USD VAT in USD. This report shows, per currency,
 * the movement on the per-currency tax payable accounts both in the
 * currency of trade (the declarable figure) and in base currency.
 */
class TaxByCurrencyController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $company = $request->user()->currentCompany;
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.company_id', $company->id)
            ->whereIn('je.status', ['posted', 'reversed'])
            ->where('a.subtype', 'tax')
            ->where('a.type', 'liability')
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('je.date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('je.date', '<=', $to))
            ->groupBy('a.id', 'a.code', 'a.name', 'a.currency')
            ->orderBy('a.code')
            ->selectRaw("
                a.code, a.name,
                COALESCE(a.currency, ?) as currency,
                COALESCE(SUM(jl.currency_credit), 0) as output_tax,
                COALESCE(SUM(jl.currency_debit), 0) as input_tax,
                COALESCE(SUM(jl.currency_credit - jl.currency_debit), 0) as net_tax,
                COALESCE(SUM(jl.credit - jl.debit), 0) as net_tax_base
            ", [$company->currency])
            ->get();

        return Inertia::render('Reports/TaxByCurrency', [
            'rows' => $rows,
            'baseCurrency' => $company->currency,
            'filters' => [
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
        ]);
    }
}
