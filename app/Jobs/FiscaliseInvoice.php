<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\Fdms\FiscalisationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Report an issued invoice to ZIMRA's FDMS. Queued with retries so an
 * offline connection or a ZIMRA outage never blocks invoicing — the
 * receipt is submitted when connectivity returns. Requires a queue
 * worker (php artisan queue:work).
 */
class FiscaliseInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Retry over ~an hour before flagging the receipt as failed. */
    public int $tries = 6;

    public function backoff(): array
    {
        return [30, 60, 300, 600, 1800];
    }

    public function __construct(public Invoice $invoice) {}

    public function handle(FiscalisationService $fiscalisation): void
    {
        $fiscalisation->fiscalise($this->invoice->fresh(['items.account', 'items.taxes', 'taxRate', 'company', 'contact']));
    }
}
