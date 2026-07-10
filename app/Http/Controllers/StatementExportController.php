<?php

namespace App\Http\Controllers;

use App\Models\FinancialStatement;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exports a REGISTERED statement snapshot as Excel (CSV) or PDF.
 * Figures come from the frozen snapshot, never recomputed — what you
 * export is exactly what was generated and (possibly) approved.
 */
class StatementExportController extends Controller
{
    public function __invoke(Request $request, FinancialStatement $financialStatement): Response
    {
        abort_unless($this->canViewCompany($request, $financialStatement->company_id), 403);

        $format = $request->query('format', 'csv');
        $title = $financialStatement->name;
        $meta = $financialStatement->scope_label
            .' · '.($financialStatement->period_from?->toDateString() ?? '…')
            .' to '.$financialStatement->period_to->toDateString()
            .' · '.strtoupper($financialStatement->status)
            .($financialStatement->approved_at ? ' on '.$financialStatement->approved_at->toDateString() : '');
        $headers = array_merge(['Code', 'Item'], $financialStatement->columns);
        $filename = str($title)->slug();

        $rows = [];
        foreach ($financialStatement->lines as $line) {
            $indent = str_repeat('    ', (int) ($line['depth'] ?? 0));
            $name = in_array($line['line_type'], ['section_header', 'subtotal', 'total'], true)
                ? strtoupper($line['name'])
                : $indent.$line['name'];
            $rows[] = array_merge([$line['code'] ?? '', $name], $line['values'] ?? []);
        }

        if ($format === 'pdf') {
            $logo = $this->logoDataUri();

            return Pdf::loadView('exports.statement', compact('title', 'meta', 'headers', 'rows', 'logo'))
                ->download($filename.'.pdf');
        }

        $csv = implode(',', $headers)."\n";
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(
                fn ($cell) => '"'.str_replace('"', '""', (string) $cell).'"',
                $row,
            ))."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.csv"',
        ]);
    }
}
