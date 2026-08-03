<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Month-end revaluation of open foreign-currency balances
 * (docs/MULTICURRENCY.md Phase D).
 *
 * Foreign-currency money accounts and unpaid foreign-currency invoices
 * gain or lose base-currency value as rates move before settlement.
 * At month-end close, their base value is restated at the closing rate
 * and the difference posted to "Exchange Gains/Losses (Unrealised)".
 *
 * The entry auto-reverses on the first day of the next period, so
 * settlement-time realised gains/losses (which measure against the
 * original booked rate) stay correct and repeated closes never
 * double-count.
 */
class FxRevaluationService
{
    public function __construct(
        protected JournalService $journal,
        protected ChartOfAccountsService $chart,
        protected ExchangeRateService $fx,
    ) {}

    /**
     * Post the revaluation as at $asOf (the last day of the period being
     * closed) plus its auto-reversal the next day. Returns the
     * revaluation entry, or null when there is nothing to revalue.
     */
    public function revalueAtClose(Company $company, Carbon $asOf, ?int $userId = null): ?JournalEntry
    {
        $base = $company->currency;
        $lines = [];

        // Foreign-currency money accounts: restate the base value of the
        // currency balance held at the closing rate.
        $moneyAccounts = Account::where('company_id', $company->id)
            ->whereIn('subtype', Account::MONEY_SUBTYPES)
            ->whereNotNull('currency')
            ->where('currency', '!=', $base)
            ->get();

        foreach ($moneyAccounts as $account) {
            $sums = DB::table('journal_lines as jl')
                ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                ->where('jl.account_id', $account->id)
                ->whereIn('je.status', ['posted', 'reversed'])
                ->whereDate('je.date', '<=', $asOf->toDateString())
                ->selectRaw('
                    COALESCE(SUM(jl.debit - jl.credit), 0) as base_balance,
                    COALESCE(SUM(jl.currency_debit - jl.currency_credit), 0) as currency_balance
                ')
                ->first();

            $currencyBalance = round((float) $sums->currency_balance, 2);
            $baseBalance = round((float) $sums->base_balance, 2);
            if ($currencyBalance == 0.0 && $baseBalance == 0.0) {
                continue;
            }

            $rate = $this->fx->rateFor($company, $account->currency, $asOf);
            $adjustment = round($this->fx->toBase($currencyBalance, $rate) - $baseBalance, 2);
            if (abs($adjustment) < 0.01) {
                continue;
            }

            $lines[] = [
                'account_id' => $account->id,
                'debit' => $adjustment > 0 ? $adjustment : 0,
                'credit' => $adjustment < 0 ? -$adjustment : 0,
                'memo' => 'Revaluation of '.$account->currency.' balance at closing rate',
                'currency' => $account->currency,
                'fx_rate' => $rate,
                'currency_debit' => 0,
                'currency_credit' => 0,
            ];
        }

        // Unpaid foreign-currency invoices: restate the receivable's
        // remaining booked base value at the closing rate, per currency.
        $receivable = $this->chart->findBySubtype($company, 'receivable');
        $openByCurrency = Invoice::where('company_id', $company->id)
            ->whereIn('status', ['sent', 'partially_paid'])
            ->whereNotNull('currency')
            ->where('currency', '!=', $base)
            ->whereDate('issue_date', '<=', $asOf->toDateString())
            ->get()
            ->groupBy('currency');

        foreach ($openByCurrency as $currency => $invoices) {
            $outstanding = round($invoices->sum(fn ($i) => (float) $i->total - (float) $i->amount_paid), 2);
            $bookedBase = round($invoices->sum(fn ($i) => (float) $i->base_total - (float) $i->base_amount_paid), 2);
            if ($outstanding == 0.0 && $bookedBase == 0.0) {
                continue;
            }

            $rate = $this->fx->rateFor($company, $currency, $asOf);
            $adjustment = round($this->fx->toBase($outstanding, $rate) - $bookedBase, 2);
            if (abs($adjustment) < 0.01) {
                continue;
            }

            $lines[] = [
                'account_id' => $receivable->id,
                'debit' => $adjustment > 0 ? $adjustment : 0,
                'credit' => $adjustment < 0 ? -$adjustment : 0,
                'memo' => 'Revaluation of open '.$currency.' invoices at closing rate',
                'currency' => $currency,
                'fx_rate' => $rate,
                'currency_debit' => 0,
                'currency_credit' => 0,
            ];
        }

        if ($lines === []) {
            return null;
        }

        // Net gain (assets restated up) credits the unrealised FX account.
        $net = round(
            array_sum(array_map(fn ($l) => (float) $l['debit'] - (float) $l['credit'], $lines)),
            2,
        );
        $fxAccount = $this->chart->fxAccount($company, realised: false);
        $lines[] = [
            'account_id' => $fxAccount->id,
            'debit' => $net < 0 ? -$net : 0,
            'credit' => $net > 0 ? $net : 0,
            'memo' => 'Unrealised exchange '.($net > 0 ? 'gain' : 'loss').' at period close',
        ];

        $entry = $this->journal->post(
            $company,
            $asOf->toDateString(),
            'FX revaluation at close of '.$asOf->format('F Y'),
            $lines,
            null,
            'FX-REVAL',
            $userId,
        );

        // Auto-reverse next day so open balances go back to their booked
        // rates for settlement accounting.
        $reversalLines = array_map(fn ($l) => [
            ...$l,
            'debit' => $l['credit'],
            'credit' => $l['debit'],
            'memo' => 'Reversal: '.($l['memo'] ?? ''),
        ], $lines);

        $this->journal->post(
            $company,
            $asOf->copy()->addDay()->toDateString(),
            'Reversal of FX revaluation at close of '.$asOf->format('F Y'),
            $reversalLines,
            null,
            'FX-REVAL-REVERSAL',
            $userId,
        );

        return $entry;
    }
}
