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
 * Report a credit note to FDMS for a fiscalised invoice that has been
 * voided. The hash chain forbids deleting receipts — corrections are
 * credit notes referencing the original receipt.
 */
class FiscaliseCreditNote implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public function backoff(): array
    {
        return [30, 60, 300, 600, 1800];
    }

    public function __construct(public Invoice $invoice) {}

    public function handle(FiscalisationService $fiscalisation): void
    {
        $fiscalisation->creditNote($this->invoice->fresh(['items.account', 'items.taxes', 'taxRate', 'company', 'contact', 'fiscalReceipts']));
    }
}
