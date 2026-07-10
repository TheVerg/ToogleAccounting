<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class PaymentExportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $companyIds = $this->reportingCompanyIds($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['outgoing', 'transfer'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'format' => ['nullable', Rule::in(['excel', 'pdf'])],
        ]);

        $payments = Payment::whereIn('company_id', $companyIds)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('kind', $status))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('date', '<=', $to))
            ->with([
                'company:id,name,parent_id',
                'company.parent:id,name',
                'contact:id,name',
                'fromAccount:id,code,name',
                'toAccount:id,code,name',
                'categoryAccount:id,code,name',
            ])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $headers = [
            'No.',
            'Company',
            'Parent Company',
            'Date',
            'Kind',
            'From',
            'To / Category',
            'Payee',
            'Method',
            'Reference',
            'Description',
            'Amount',
        ];
        $rows = $payments->map(fn ($payment) => [
            $payment->payment_no,
            $payment->company?->name,
            $payment->company?->parent?->name,
            $payment->date?->toDateString(),
            str_replace('_', ' ', $payment->kind),
            $payment->fromAccount?->name,
            $payment->kind === 'transfer' ? $payment->toAccount?->name : $payment->categoryAccount?->name,
            $payment->contact?->name,
            str_replace('_', ' ', (string) $payment->method),
            $payment->reference,
            $payment->description,
            $payment->amount,
        ])->all();

        $format = $filters['format'] ?? 'excel';
        $baseName = Str::of($filters['status'] ?? 'payments')->replace('_', '-')
            .'-'.($filters['from'] ?? 'start').'-'.($filters['to'] ?? 'end');

        if ($format === 'pdf') {
            return Pdf::loadView('exports.table', [
                'title' => 'Payments Export',
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
