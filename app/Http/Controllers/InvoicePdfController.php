<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class InvoicePdfController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice): Response
    {
        abort_unless(in_array($invoice->company_id, $this->reportingCompanyIds($request), true), 403);

        $invoice->load([
            'company',
            'contact',
            'items.account:id,code,name',
            'payments',
            'taxRate:id,name,rate',
        ]);

        $filename = Str::slug($invoice->invoice_no.'-'.$invoice->contact?->name, '-').'.pdf';

        return Pdf::loadView('exports.invoice', [
            'invoice' => $invoice,
            'logo' => $this->logoDataUri(),
        ])->download($filename);
    }
}
