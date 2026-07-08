<?php

namespace App\Http\Controllers;

use App\Models\FinancialStatement;
use App\Services\StatementImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Import statements from filled CSV templates. Imported statements
 * enter the register as draft snapshots — same lifecycle as generated
 * ones (approve to lock, forced reasoned soft delete to remove).
 */
class StatementImportController extends Controller
{
    /** Download a pre-filled template for a statement type. */
    public function template(Request $request, StatementImportService $imports): Response
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(FinancialStatement::IMPORTABLE_TYPES)],
        ]);

        $template = $imports->template($data['type'], $request->user()->currentCompany);

        $csv = implode(',', $template['headers'])."\n";
        foreach ($template['rows'] as $row) {
            $csv .= implode(',', array_map(
                fn ($cell) => '"'.str_replace('"', '""', (string) $cell).'"',
                $row,
            ))."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$template['filename'].'.csv"',
        ]);
    }

    /** Upload a filled template into the register as a draft snapshot. */
    public function store(Request $request, StatementImportService $imports): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(FinancialStatement::IMPORTABLE_TYPES)],
            'from' => ['nullable', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $company = $request->user()->currentCompany;

        try {
            $snapshot = $imports->parse($data['type'], $request->file('file')->get());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        $statement = FinancialStatement::create([
            'company_id' => $company->id,
            'type' => $data['type'],
            'name' => FinancialStatement::TYPES[$data['type']].' — '.$company->name.' — '.$data['to'].' (imported)',
            'scope_label' => $company->name.' (imported)',
            'period_from' => $data['from'] ?? null,
            'period_to' => $data['to'],
            'status' => 'draft',
            'source' => 'imported',
            'columns' => $snapshot['columns'],
            'lines' => $snapshot['lines'],
            'generated_by' => $request->user()->id,
        ]);

        return redirect()->route('statements.register.show', $statement)
            ->with('success', $statement->name.' imported as a draft.');
    }
}
