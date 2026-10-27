<?php

namespace App\Http\Controllers;

use App\Models\ComplianceReturn;
use App\Models\Expense;
use App\Services\Compliance\ComplianceReturnTypeRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class ComplianceEvidencePackController extends Controller
{
    public function __invoke(Request $request, ComplianceReturn $complianceReturn, ComplianceReturnTypeRegistry $registry)
    {
        abort_unless($complianceReturn->company_id === $request->user()->current_company_id, 403);
        abort_unless($complianceReturn->snapshot, 422, 'Prepare the return before generating an evidence pack.');
        abort_unless(class_exists(ZipArchive::class), 500, 'The PHP ZIP extension is required.');
        abort_unless($registry->has($complianceReturn->return_type), 422,
            'This return type has no working papers in this version, so no evidence pack can be produced.');

        $path = tempnam(sys_get_temp_dir(), 'compliance-pack-');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create evidence pack.');
        }

        $manifest = [
            'company' => $complianceReturn->company->name,
            'return_type' => $complianceReturn->return_type,
            'period_from' => $complianceReturn->period_from->toDateString(),
            'period_to' => $complianceReturn->period_to->toDateString(),
            'status' => $complianceReturn->status,
            'filing_reference' => $complianceReturn->filing_reference,
            'generated_at' => now()->toIso8601String(),
        ];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        // The working-papers CSV belongs to the return type, not to this controller.
        $papers = $registry->for($complianceReturn->return_type)->evidenceCsv($complianceReturn->snapshot);
        $zip->addFromString($papers['name'], $this->csv($papers['headers'], $papers['rows']));
        $zip->addFromString('reconciliation-exceptions.csv', $this->csv(
            ['Type', 'Severity', 'Reference', 'Message', 'Amount', 'Currency'],
            collect($complianceReturn->snapshot['exceptions'] ?? [])->map(fn ($e) => [
                $e['type'], $e['severity'], $e['reference'], $e['message'], $e['amount'] ?? 0, $e['currency'] ?? '',
            ])->all()
        ));
        $zip->addFromString('approval-history.csv', $this->csv(
            ['Date', 'Action', 'From', 'To', 'User', 'Comment'],
            $complianceReturn->events()->with('user')->get()->map(fn ($e) => [
                $e->created_at, $e->action, $e->from_status, $e->to_status, $e->user?->name, $e->comment,
            ])->all()
        ));

        $attachments = $complianceReturn->attachments->concat(
            Expense::where('company_id', $complianceReturn->company_id)
                ->whereBetween('date', [$complianceReturn->period_from, $complianceReturn->period_to])
                ->with('attachments')->get()->flatMap->attachments
        );
        foreach ($attachments as $attachment) {
            $absolute = Storage::disk('local')->path($attachment->path);
            if (is_file($absolute)) {
                $zip->addFile($absolute, 'evidence/'.$attachment->id.'-'.basename($attachment->original_name));
            }
        }
        $zip->close();

        $name = $complianceReturn->return_type.'-'.$complianceReturn->period_to->format('Y-m').'-evidence.zip';
        return response()->download($path, $name)->deleteFileAfterSend(true);
    }

    protected function csv(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers);
        foreach ($rows as $row) fputcsv($stream, $row);
        rewind($stream);
        return stream_get_contents($stream);
    }
}
