<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class InvoicePdfController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice): Response
    {
        abort_unless(in_array($invoice->company_id, $this->reportingCompanyIds($request), true), 403);

        $invoice->load([
            'company',
            'contact',
            'items.account:id,code,name', 'items.taxes', 'items.taxGroup:id,name',
            'payments',
            'taxRate:id,name,rate', 'taxGroup:id,name',
            'fiscalReceipts.device',
            'fiscalReceipts.day',
        ]);

        // Fiscalised invoices must carry the ZIMRA QR verification code.
        $fiscal = $invoice->fiscalReceipt();
        $fiscalQr = null;
        if ($fiscal?->verification_url) {
            try {
                $fiscalQr = (new Builder)->build(
                    writer: new PngWriter,
                    data: $fiscal->verification_url,
                    size: 140,
                    margin: 0,
                )->getDataUri();
            } catch (Throwable) {
                $fiscalQr = null; // never block the PDF over a QR render
            }
        }

        $filename = Str::slug($invoice->invoice_no.'-'.$invoice->contact?->name, '-').'.pdf';

        return Pdf::loadView('exports.invoice', [
            'invoice' => $invoice,
            'logo' => $this->logoDataUri(),
            'fiscal' => $fiscal,
            'fiscalQr' => $fiscalQr,
        ])->download($filename);
    }
}
