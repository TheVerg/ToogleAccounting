<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Export one account's ledger (general ledger or cash book view) for a
 * period as CSV or PDF, including the balance brought forward and the
 * closing balance.
 */
class LedgerExportController extends Controller
{
    public function __invoke(Request $request, ReportService $reports): Response
    {
        $company = $request->user()->currentCompany;
        $filters = $request->validate([
            'account' => ['required', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'format' => ['nullable', Rule::in(['excel', 'pdf'])],
        ]);

        $account = Account::where('company_id', $company->id)->findOrFail($filters['account']);
        $from = $filters['from'] ?? now()->startOfYear()->toDateString();
        $to = $filters['to'] ?? now()->toDateString();

        $report = $reports->generalLedger([$company->id], $account, $from, $to);

        $headers = ['Date', 'Entry', 'Description', 'Memo', 'Contra Account(s)', 'Reference', 'Debit', 'Credit', 'Balance'];
        $rows = [[$from, '', 'Balance brought forward', '', '', '', '', '', $report['opening_balance']]];
        foreach ($report['rows'] as $row) {
            $rows[] = [
                $row['date'], $row['entry_no'], $row['description'], $row['memo'],
                $row['counterpart'], $row['reference'],
                $row['debit'] ?: '', $row['credit'] ?: '', $row['balance'],
            ];
        }
        $rows[] = [$to, '', 'Closing balance carried forward', '', '', '',
            $report['total_debit'], $report['total_credit'], $report['closing_balance']];

        $title = 'Ledger — '.$account->code.' '.$account->name;
        $baseName = 'ledger-'.$account->code.'-'.$from.'-'.$to;

        if (($filters['format'] ?? 'excel') === 'pdf') {
            return Pdf::loadView('exports.table', [
                'title' => $title,
                'meta' => $company->name.' · Period: '.$from.' to '.$to,
                'headers' => $headers,
                'rows' => $rows,
                'logo' => $this->logoDataUri(),
            ])->download($baseName.'.pdf');
        }

        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, [$title.' · '.$company->name.' · '.$from.' to '.$to]);
        fputcsv($csv, $headers);
        foreach ($rows as $row) {
            fputcsv($csv, $row);
        }
        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);

        return response($content, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$baseName.'.csv"',
        ]);
    }
}
