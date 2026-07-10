<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class InvoiceExportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $companyIds = $this->reportingCompanyIds($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'sent', 'partially_paid', 'paid', 'void'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'format' => ['nullable', Rule::in(['excel', 'pdf'])],
        ]);

        $invoices = Invoice::whereIn('company_id', $companyIds)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('issue_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('issue_date', '<=', $to))
            ->with(['company:id,name,parent_id', 'company.parent:id,name', 'contact:id,name'])
            ->orderBy('issue_date')
            ->orderBy('id')
            ->get();

        $headers = [
            'No.',
            'Company',
            'Parent Company',
            'Customer',
            'Issue Date',
            'Due Date',
            'Status',
            'Subtotal',
            'Tax',
            'Total',
            'Amount Paid',
            'Balance',
        ];
        $rows = $invoices->map(fn ($invoice) => [
            $invoice->invoice_no,
            $invoice->company?->name,
            $invoice->company?->parent?->name,
            $invoice->contact?->name,
            $invoice->issue_date?->toDateString(),
            $invoice->due_date?->toDateString(),
            str_replace('_', ' ', $invoice->status),
            $invoice->subtotal,
            $invoice->tax_amount,
            $invoice->total,
            $invoice->amount_paid,
            $invoice->balanceDue(),
        ])->all();

        $format = $filters['format'] ?? 'excel';
        $baseName = Str::of($filters['status'] ?? 'revenue')->replace('_', '-')
            .'-'.($filters['from'] ?? 'start').'-'.($filters['to'] ?? 'end');

        if ($format === 'pdf') {
            return Pdf::loadView('exports.table', [
                'title' => 'Revenue Export',
                'meta' => 'Period: '.($filters['from'] ?? 'start').' to '.($filters['to'] ?? 'end'),
                'headers' => $headers,
                'rows' => $rows,
                'logo' => $this->logoDataUri(),
            ])->download($baseName.'.pdf');
        }

        $csv = fopen('php://temp', 'r+');
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
