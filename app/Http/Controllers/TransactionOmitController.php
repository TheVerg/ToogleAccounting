<?php

namespace App\Http\Controllers;

use App\Models\Dividend;
use App\Models\DividendPayment;
use App\Models\EquityTransaction;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ShareholderLoan;
use App\Models\ShareholderLoanRepayment;
use App\Services\TransactionOmitService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Omit a transaction directly from its source area (Revenue, Expenses,
 * Payments, Owner's Equity). Same rules as the journal omit: system
 * admins only, reason required, audit logged.
 */
class TransactionOmitController extends Controller
{
    public function invoice(Request $request, Invoice $invoice, TransactionOmitService $service): RedirectResponse
    {
        return $this->omit($request, $invoice, $service);
    }

    public function expense(Request $request, Expense $expense, TransactionOmitService $service): RedirectResponse
    {
        return $this->omit($request, $expense, $service);
    }

    public function payment(Request $request, Payment $payment, TransactionOmitService $service): RedirectResponse
    {
        return $this->omit($request, $payment, $service);
    }

    public function equity(Request $request, EquityTransaction $equityTransaction, TransactionOmitService $service): RedirectResponse
    {
        return $this->omit($request, $equityTransaction, $service);
    }

    public function shareholderLoan(Request $request, ShareholderLoan $shareholderLoan, TransactionOmitService $service): RedirectResponse
    {
        return $this->omit($request, $shareholderLoan, $service);
    }

    public function shareholderLoanRepayment(Request $request, ShareholderLoanRepayment $repayment, TransactionOmitService $service): RedirectResponse
    {
        return $this->omit($request, $repayment, $service);
    }

    public function dividend(Request $request, Dividend $dividend, TransactionOmitService $service): RedirectResponse
    {
        return $this->omit($request, $dividend, $service);
    }

    public function dividendPayment(Request $request, DividendPayment $dividendPayment, TransactionOmitService $service): RedirectResponse
    {
        return $this->omit($request, $dividendPayment, $service);
    }

    protected function omit(Request $request, Model $source, TransactionOmitService $service): RedirectResponse
    {
        abort_unless($request->user()->isSystemAdmin(), 403);
        abort_unless($source->company_id === $request->user()->current_company_id, 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);

        try {
            $service->omitSource($source, $data['reason']);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'This transaction has dependent records and cannot be omitted yet. Reverse or remove those dependent records first.');
        }

        return back()->with('success', 'Transaction omitted from its source area and the journal. Statements will reflect the change.');
    }
}
