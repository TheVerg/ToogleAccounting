<?php

namespace App\Services\Fdms;

use App\Models\FiscalDay;
use App\Models\FiscalDevice;
use App\Models\FiscalReceipt;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Builds, signs, chains, and submits fiscal receipts (docs/FISCALISATION.md
 * Packages 2–4). FDMS is stateful: counters and the hash chain are
 * reserved under a device row lock, and a reserved receipt is immutable —
 * retries resubmit the stored payload, never rebuild it, so the chain
 * stays intact through outages.
 */
class FiscalisationService
{
    public function __construct(
        protected FdmsClient $client,
        protected ReceiptSigner $signer,
    ) {}

    /** Report an issued invoice to FDMS (idempotent per invoice). */
    public function fiscalise(Invoice $invoice): FiscalReceipt
    {
        $device = $this->activeDevice($invoice->company_id);

        $existing = FiscalReceipt::where('invoice_id', $invoice->id)
            ->where('receipt_type', 'FiscalInvoice')
            ->latest('id')->first();
        if ($existing?->status === 'submitted') {
            return $existing;
        }
        if ($existing?->status === 'invalid') {
            throw new RuntimeException('FDMS received this invoice with red validation errors; it cannot be retried unchanged.');
        }

        $receipt = $existing ?? $this->reserveReceipt($device, $invoice, 'FiscalInvoice');

        return $this->submit($receipt);
    }

    /** Report a credit note for a fiscalised (now voided) invoice. */
    public function creditNote(Invoice $invoice): FiscalReceipt
    {
        $device = $this->activeDevice($invoice->company_id);

        $original = FiscalReceipt::where('invoice_id', $invoice->id)
            ->where('receipt_type', 'FiscalInvoice')
            ->where('status', 'submitted')
            ->latest('id')->first();
        if (! $original) {
            throw new RuntimeException('This invoice has no fiscalised receipt to credit.');
        }

        $existing = FiscalReceipt::where('invoice_id', $invoice->id)
            ->where('receipt_type', 'CreditNote')
            ->latest('id')->first();
        if ($existing?->status === 'submitted') {
            return $existing;
        }
        if ($existing?->status === 'invalid') {
            throw new RuntimeException('FDMS received this credit note with red validation errors; it cannot be retried unchanged.');
        }

        $receipt = $existing ?? $this->reserveReceipt($device, $invoice, 'CreditNote', $original);

        return $this->submit($receipt);
    }

    /**
     * Reserve the counters and build the signed, immutable payload.
     * The chain (receipt counter, global number, previous hash) is
     * claimed under a row lock; the fiscal day auto-opens if needed.
     */
    protected function reserveReceipt(FiscalDevice $device, Invoice $invoice, string $type, ?FiscalReceipt $original = null): FiscalReceipt
    {
        return DB::transaction(function () use ($device, $invoice, $type, $original) {
            $device = FiscalDevice::whereKey($device->id)->lockForUpdate()->firstOrFail();
            $day = $device->openDay() ?? $this->openDay($device);

            $counter = $day->receipt_counter + 1;
            $globalNo = $device->last_receipt_global_no + 1;
            $currency = strtoupper($invoice->currency ?: $invoice->company->currency);
            $date = now(config('services.fdms.timezone'))->format('Y-m-d\TH:i:s');

            [$lines, $taxes, $payments, $total] = $this->buildReceiptData($device, $invoice, $type);

            $signString = $this->signer->receiptSignString(
                (int) $device->device_id, $type, $currency, $globalNo, $date, $total, $taxes,
                $counter > 1 ? $device->last_receipt_hash : null,
            );
            $hash = $this->signer->hash($signString);
            $signature = $this->signer->sign($signString, $device->private_key);

            $receipt = [
                'receiptType' => $type,
                'receiptCurrency' => $currency,
                'receiptCounter' => $counter,
                'receiptGlobalNo' => $globalNo,
                'invoiceNo' => $type === 'CreditNote' ? $invoice->invoice_no.'-CN' : $invoice->invoice_no,
                'receiptDate' => $date,
                'receiptLinesTaxInclusive' => $invoice->tax_mode === 'inclusive',
                'receiptLines' => $lines,
                'receiptTaxes' => $taxes,
                'receiptPayments' => $payments,
                'receiptTotal' => $total,
                'receiptPrintForm' => 'InvoiceA4',
                'receiptDeviceSignature' => ['hash' => $hash, 'signature' => $signature],
            ];

            if ($invoice->contact?->tin) {
                $receipt['buyerData'] = $this->buyerData($invoice);
            }
            if ($type === 'CreditNote') {
                $receipt['receiptNotes'] = 'Credit note for '.$invoice->invoice_no;
                $receipt['creditDebitNote'] = array_filter([
                    'receiptID' => $original?->server_receipt_id,
                    'deviceID' => (int) $device->device_id,
                    'receiptGlobalNo' => $original?->receipt_global_no,
                    'fiscalDayNo' => $original?->day?->fiscal_day_no,
                ], fn ($v) => $v !== null);
            }

            $qrBase = $device->config['qrUrl'] ?? $this->client->qrBaseUrl($device->environment);
            $receiptDate = now(config('services.fdms.timezone'));

            $model = FiscalReceipt::create([
                'fiscal_device_id' => $device->id,
                'fiscal_day_id' => $day->id,
                'invoice_id' => $invoice->id,
                'original_fiscal_receipt_id' => $original?->id,
                'receipt_type' => $type,
                'currency' => $currency,
                'receipt_counter' => $counter,
                'receipt_global_no' => $globalNo,
                'invoice_no' => $receipt['invoiceNo'],
                'receipt_date' => $date,
                'total' => $total,
                'payload' => ['receipt' => $receipt],
                'hash' => $hash,
                'device_signature' => $signature,
                'verification_url' => $this->signer->verificationUrl($qrBase, (int) $device->device_id, $receiptDate, $globalNo, $signature),
                'qr_data' => $this->signer->verificationCode($signature),
                'status' => 'pending',
            ]);

            $day->update(['receipt_counter' => $counter]);
            $device->update(['last_receipt_global_no' => $globalNo, 'last_receipt_hash' => $hash]);

            return $model;
        });
    }

    /** Submit a reserved receipt; failures rethrow so the queued job retries. */
    protected function submit(FiscalReceipt $receipt): FiscalReceipt
    {
        try {
            $response = $this->client->submitReceipt($receipt->device, $receipt->payload);
        } catch (Throwable $e) {
            $receipt->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);

            throw $e;
        }

        $errors = $response['validationErrors'] ?? [];
        $hasRedError = collect($errors)->contains(
            fn (array $error) => strcasecmp((string) ($error['validationErrorColor'] ?? ''), 'red') === 0
        );

        $receipt->update([
            'status' => $hasRedError ? 'invalid' : 'submitted',
            'server_receipt_id' => $response['receiptID'] ?? null,
            'server_signature' => isset($response['receiptServerSignature'])
                ? json_encode($response['receiptServerSignature'])
                : null,
            'error' => $errors === [] ? null : 'FDMS validation: '.json_encode($errors),
        ]);

        return $receipt->fresh();
    }

    /** Buyer data is sent only for identified taxpayers; FDMS requires name + TIN together. */
    protected function buyerData(Invoice $invoice): array
    {
        $contact = $invoice->contact;

        $data = [
            'buyerRegisterName' => $contact->name,
            'buyerTIN' => $contact->tin,
        ];

        if ($contact->trade_name) {
            $data['buyerTradeName'] = $contact->trade_name;
        }
        if ($contact->vat_number) {
            $data['vatNumber'] = $contact->vat_number;
        }

        $contacts = array_filter([
            'phoneNo' => $contact->phone ? mb_substr($contact->phone, 0, 20) : null,
            'email' => $contact->email,
        ]);
        if ($contacts !== []) {
            $data['buyerContacts'] = $contacts;
        }

        $address = array_filter([
            'province' => $contact->province,
            'city' => $contact->city,
            'street' => $contact->street ?: ($contact->billing_address ?: $contact->address),
            'houseNo' => $contact->house_no,
            'district' => $contact->district,
        ]);
        if ($address !== []) {
            $data['buyerAddress'] = $address;
        }

        return $data;
    }

    /** Open the next fiscal day (called with the device row locked). */
    public function openDay(FiscalDevice $device): FiscalDay
    {
        $dayNo = $device->last_fiscal_day_no + 1;
        $openedAt = now(config('services.fdms.timezone'));

        $response = $this->client->openDay($device, [
            'fiscalDayNo' => $dayNo,
            'fiscalDayOpened' => $openedAt->format('Y-m-d\TH:i:s'),
        ]);
        $dayNo = (int) ($response['fiscalDayNo'] ?? $dayNo);

        $day = FiscalDay::create([
            'fiscal_device_id' => $device->id,
            'fiscal_day_no' => $dayNo,
            'status' => 'open',
            'opened_at' => $openedAt,
        ]);

        // The hash chain restarts each fiscal day.
        $device->update(['last_fiscal_day_no' => $dayNo, 'last_receipt_hash' => null]);

        return $day;
    }

    /** Close the open fiscal day, submitting the Z-report counters. */
    public function closeDay(FiscalDevice $device): FiscalDay
    {
        $day = $device->openDay();
        if (! $day) {
            throw new RuntimeException('No fiscal day is open.');
        }
        if ($day->receipts()->whereIn('status', ['pending', 'failed', 'invalid'])->exists()) {
            throw new RuntimeException('Resolve all pending, failed, or invalid fiscal receipts before closing the fiscal day.');
        }

        $counters = $this->buildDayCounters($day);

        $signString = $this->signer->closeDaySignString(
            (int) $device->device_id,
            $day->fiscal_day_no,
            $day->opened_at->timezone(config('services.fdms.timezone'))->format('Y-m-d'),
            $counters,
        );

        $payload = [
            'fiscalDayNo' => $day->fiscal_day_no,
            'fiscalDayCounters' => array_values(array_filter(
                $counters,
                fn ($c) => round((float) $c['fiscalCounterValue'], 2) != 0.0,
            )),
            'fiscalDayDeviceSignature' => [
                'hash' => $this->signer->hash($signString),
                'signature' => $this->signer->sign($signString, $device->private_key),
            ],
            'receiptCounter' => $day->receipt_counter,
        ];

        try {
            $this->client->closeDay($device, $payload);
        } catch (Throwable $e) {
            $day->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);

            throw $e;
        }

        $day->update([
            'status' => 'closed',
            'closed_at' => now(),
            'counters' => $payload['fiscalDayCounters'],
            'error' => null,
        ]);

        return $day->fresh();
    }

    /** Reconcile local counters/day state when FDMS was changed outside this app. */
    public function syncStatus(FiscalDevice $device): array
    {
        $status = $this->client->getStatus($device);
        $remoteDayStatus = $status['fiscalDayStatus'] ?? null;
        $remoteDayNo = (int) ($status['lastFiscalDayNo'] ?? 0);
        $remoteGlobalNo = (int) ($status['lastReceiptGlobalNo'] ?? 0);

        DB::transaction(function () use ($device, $status, $remoteDayStatus, $remoteDayNo, $remoteGlobalNo) {
            $device = FiscalDevice::whereKey($device->id)->lockForUpdate()->firstOrFail();
            $day = $device->openDay();

            if ($remoteDayStatus === 'FiscalDayClosed' && $day) {
                if ($remoteDayNo !== 0 && $remoteDayNo !== (int) $day->fiscal_day_no) {
                    throw new RuntimeException(
                        "FDMS reports fiscal day #{$remoteDayNo} closed, but local day #{$day->fiscal_day_no} is open. Manual recovery is required."
                    );
                }

                $closedAt = isset($status['fiscalDayClosed'])
                    ? Carbon::parse($status['fiscalDayClosed'], config('services.fdms.timezone'))
                    : now();

                $day->update([
                    'status' => 'closed',
                    'closed_at' => $closedAt,
                    'counters' => $status['fiscalDayCounters'] ?? $status['fiscalDayCounter'] ?? $day->counters,
                    'error' => null,
                ]);
            }

            $device->update([
                'last_fiscal_day_no' => max((int) $device->last_fiscal_day_no, $remoteDayNo),
                'last_receipt_global_no' => max((int) $device->last_receipt_global_no, $remoteGlobalNo),
            ]);
        });

        return $status;
    }

    /** Z-report totals aggregated from the day's submitted receipts. */
    protected function buildDayCounters(FiscalDay $day): array
    {
        $byTax = [];      // [currency][taxID] => [percent, sales, tax, saleType]
        $byMoney = [];    // [currency][moneyType] => amount

        foreach ($day->receipts()->where('status', 'submitted')->get() as $receipt) {
            $data = $receipt->payload['receipt'];
            $currency = $data['receiptCurrency'];
            $isCredit = $data['receiptType'] === 'CreditNote';

            foreach ($data['receiptTaxes'] as $tax) {
                $key = $currency.'|'.$tax['taxID'].'|'.($isCredit ? 'CN' : 'S');
                $byTax[$key] ??= [
                    'currency' => $currency,
                    'taxID' => $tax['taxID'],
                    'percent' => $tax['taxPercent'] ?? null,
                    'credit' => $isCredit,
                    'sales' => 0.0,
                    'tax' => 0.0,
                ];
                $byTax[$key]['sales'] = round($byTax[$key]['sales'] + (float) $tax['salesAmountWithTax'], 2);
                $byTax[$key]['tax'] = round($byTax[$key]['tax'] + (float) $tax['taxAmount'], 2);
            }

            foreach ($data['receiptPayments'] as $payment) {
                $key = $currency.'|'.$payment['moneyTypeCode'];
                $byMoney[$key] ??= ['currency' => $currency, 'moneyType' => $payment['moneyTypeCode'], 'amount' => 0.0];
                $byMoney[$key]['amount'] = round($byMoney[$key]['amount'] + (float) $payment['paymentAmount'], 2);
            }
        }

        $counters = [];
        foreach ($byTax as $t) {
            $withPercent = fn (array $counter) => $t['percent'] !== null
                ? $counter + ['fiscalCounterTaxPercent' => (float) $t['percent']]
                : $counter;

            $counters[] = $withPercent([
                'fiscalCounterType' => $t['credit'] ? 'CreditNoteByTax' : 'SaleByTax',
                'fiscalCounterCurrency' => $t['currency'],
                'fiscalCounterTaxID' => $t['taxID'],
                'fiscalCounterValue' => $t['sales'],
            ]);
            $counters[] = $withPercent([
                'fiscalCounterType' => $t['credit'] ? 'CreditNoteTaxByTax' : 'SaleTaxByTax',
                'fiscalCounterCurrency' => $t['currency'],
                'fiscalCounterTaxID' => $t['taxID'],
                'fiscalCounterValue' => $t['tax'],
            ]);
        }
        foreach ($byMoney as $m) {
            $counters[] = [
                'fiscalCounterType' => 'BalanceByMoneyType',
                'fiscalCounterCurrency' => $m['currency'],
                'fiscalCounterMoneyType' => $m['moneyType'],
                'fiscalCounterValue' => $m['amount'],
            ];
        }

        return $counters;
    }

    /**
     * Receipt lines, tax table, and payments from the invoice. Lines are
     * tax-exclusive (matching how invoices are captured); an invoice
     * issued on account is paid by money type "Credit". Credit notes
     * negate every amount.
     */
    protected function buildReceiptData(FiscalDevice $device, Invoice $invoice, string $type): array
    {
        $sign = $type === 'CreditNote' ? -1 : 1;
        $invoice->loadMissing(['items.taxes', 'items.account']);

        if ($invoice->items->contains(fn ($item) => $item->taxes->count() > 1)) {
            throw new RuntimeException(
                'FDMS permits one fiscal tax classification per receipt line. Split lines with multiple taxes or use a single ZIMRA-configured tax group.'
            );
        }

        if ($invoice->items->contains(fn ($item) => $item->taxes->contains(fn ($tax) => ! $tax->is_fiscal_tax))) {
            throw new RuntimeException(
                'This invoice contains a non-FDMS levy. Configure it as an applicable ZIMRA tax or issue it through a non-Zimbabwe company.'
            );
        }

        if ($invoice->items->contains(fn ($item) => $item->taxes->isNotEmpty())) {
            return $this->buildItemTaxReceiptData($device, $invoice, $type);
        }

        $taxRate = $invoice->taxRate;
        $hasTaxAmount = $taxRate !== null && (float) $invoice->tax_amount != 0.0;

        if ($hasTaxAmount && $taxRate->zimra_tax_id === null) {
            throw new RuntimeException(
                'Tax rate "'.$taxRate->name.'" has no ZIMRA tax ID. Set it under Master Data → Tax Rates before fiscalising.'
            );
        }

        $taxId = $taxRate?->zimra_tax_id !== null
            ? (int) $taxRate->zimra_tax_id
            : $this->exemptTaxId($device);
        $configuredTax = collect($device->config['applicableTaxes'] ?? [])
            ->firstWhere('taxID', $taxId);
        $taxPercent = is_array($configuredTax) && array_key_exists('taxPercent', $configuredTax)
            ? round((float) $taxRate->rate, 2)
            : null;

        $lines = [];
        foreach ($invoice->items as $i => $item) {
            $line = [
                'receiptLineType' => 'Sale',
                'receiptLineNo' => $i + 1,
                'receiptLineHSCode' => str_pad(
                    $item->hs_code ?: ($item->account->default_hs_code ?? config('services.fdms.default_hs_code')),
                    8, '0', STR_PAD_LEFT,
                ),
                'receiptLineName' => mb_substr($item->description, 0, 200),
                'receiptLinePrice' => round($sign * (float) $item->unit_price, 2),
                'receiptLineQuantity' => (float) $item->quantity,
                'receiptLineTotal' => round($sign * (float) $item->amount, 2),
                'taxID' => $taxId,
            ];
            if ($taxPercent !== null) {
                $line['taxPercent'] = $taxPercent;
            }
            $lines[] = $line;
        }

        $total = round($sign * (float) $invoice->total, 2);

        $tax = [
            'taxID' => $taxId,
            'taxAmount' => round($sign * (float) $invoice->tax_amount, 2),
            'salesAmountWithTax' => $total,
        ];
        if ($taxPercent !== null) {
            $tax['taxPercent'] = $taxPercent;
        }

        $payments = [['moneyTypeCode' => 'Credit', 'paymentAmount' => $total]];

        return [$lines, [$tax], $payments, $total];
    }

    protected function buildItemTaxReceiptData(FiscalDevice $device, Invoice $invoice, string $type): array
    {
        $sign = $type === 'CreditNote' ? -1 : 1;
        $inclusive = $invoice->tax_mode === 'inclusive';
        $taxes = [];
        $lines = [];
        foreach ($invoice->items as $index => $item) {
            $component = $item->taxes->first();
            $taxId = $component?->zimra_tax_id ?? $this->exemptTaxId($device);
            if ($component && $component->zimra_tax_id === null) {
                throw new RuntimeException('Tax "'.$component->tax_name.'" has no ZIMRA tax ID.');
            }
            $lineTotal = $inclusive ? (float) $item->gross_amount : (float) $item->amount;
            $lines[] = [
                'receiptLineType' => 'Sale', 'receiptLineNo' => $index + 1,
                'receiptLineHSCode' => str_pad($item->hs_code ?: ($item->account->default_hs_code ?? config('services.fdms.default_hs_code')), 8, '0', STR_PAD_LEFT),
                'receiptLineName' => mb_substr($item->description, 0, 200),
                'receiptLinePrice' => round($sign * (float) $item->unit_price, 2),
                'receiptLineQuantity' => (float) $item->quantity,
                'receiptLineTotal' => round($sign * $lineTotal, 2),
                'taxID' => (int) $taxId,
                ...($component ? ['taxPercent' => round((float) $component->rate, 2)] : []),
            ];
            $key = (string) $taxId;
            $taxes[$key] ??= ['taxID' => (int) $taxId, 'taxAmount' => 0.0, 'salesAmountWithTax' => 0.0];
            $taxes[$key]['taxAmount'] = round($taxes[$key]['taxAmount'] + $sign * (float) ($component?->tax_amount ?? 0), 2);
            $taxes[$key]['salesAmountWithTax'] = round($taxes[$key]['salesAmountWithTax'] + $sign * (float) $item->gross_amount, 2);
            if ($component) $taxes[$key]['taxPercent'] = round((float) $component->rate, 2);
        }
        $total = round($sign * (float) $invoice->total, 2);
        return [$lines, array_values($taxes), [['moneyTypeCode' => 'Credit', 'paymentAmount' => $total]], $total];
    }

    /** The exempt tax ID as ZIMRA configured it for this device. */
    protected function exemptTaxId(FiscalDevice $device): int
    {
        foreach ($device->config['applicableTaxes'] ?? [] as $tax) {
            if (! isset($tax['taxPercent'])) {
                return (int) $tax['taxID'];
            }
        }

        // Reference values: 1 on the test environment, 3 in production.
        return $device->environment === 'production' ? 3 : 1;
    }

    protected function activeDevice(int $companyId): FiscalDevice
    {
        $device = FiscalDevice::where('company_id', $companyId)
            ->where('status', 'active')
            ->orderByDesc('id')
            ->first();
        if (! $device || ! $device->isActive()) {
            throw new RuntimeException('No active fiscal device is registered for this company.');
        }

        return $device;
    }
}
