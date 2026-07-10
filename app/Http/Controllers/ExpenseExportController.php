<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ExpenseExportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $companyIds = $this->reportingCompanyIds($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['ordinary', 'petty_cash', 'recurring'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'format' => ['nullable', Rule::in(['excel', 'pdf'])],
        ]);

        $expenses = Expense::whereIn('company_id', $companyIds)
            ->when($filters['status'] ?? null, function ($query, $status) {
                match ($status) {
                    'ordinary' => $query->where('is_petty_cash', false)->whereNull('recurring_expense_id'),
                    'petty_cash' => $query->where('is_petty_cash', true),
                    'recurring' => $query->whereNotNull('recurring_expense_id'),
                };
            })
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('date', '<=', $to))
            ->with(['company:id,name,parent_id', 'company.parent:id,name', 'contact:id,name', 'account:id,code,name', 'paidFromAccount:id,code,name'])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $headers = ['No.', 'Company', 'Parent Company', 'Date', 'Category', 'Paid From', 'Vendor', 'Description', 'Amount', 'Tax', 'Total'];
        $rows = $expenses->map(fn ($expense) => [
            $expense->expense_no,
            $expense->company?->name,
            $expense->company?->parent?->name,
            $expense->date?->toDateString(),
            $expense->account?->name,
            $expense->paidFromAccount?->name,
            $expense->contact?->name,
            $expense->description,
            $expense->amount,
            $expense->tax_amount,
            $expense->total,
        ])->all();

        $format = $filters['format'] ?? 'excel';
        $baseName = Str::of($filters['status'] ?? 'expenses')->replace('_', '-')
            .'-'.($filters['from'] ?? 'start').'-'.($filters['to'] ?? 'end');

        if ($format === 'pdf') {
            return Pdf::loadView('exports.table', [
                'title' => 'Expenses Export',
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
