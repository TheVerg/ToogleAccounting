<?php

namespace Tests\Feature;

use App\Jobs\FiscaliseInvoice;
use App\Models\Company;
use App\Models\Contact;
use App\Models\FiscalDevice;
use App\Models\FiscalReceipt;
use App\Models\Invoice;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\ChartOfAccountsService;
use App\Services\Fdms\DeviceRegistrationService;
use App\Services\Fdms\FiscalisationService;
use App\Services\Fdms\ReceiptSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FiscalisationTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $user;

    protected TaxRate $vat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Fiscal Co', 'code' => 'F', 'type' => 'holding', 'currency' => 'USD']);
        app(ChartOfAccountsService::class)->seedDefaultChart($this->company);
        $this->user = User::factory()->create();
        $this->vat = TaxRate::create([
            'name' => 'VAT 15.5%', 'rate' => 15.5, 'is_active' => true,
            'zimra_tax_id' => 515, 'zimra_tax_code' => 'A',
        ]);
    }

    protected function fakeFdms(): void
    {
        Http::fake([
            '*/Public/v1/*/RegisterDevice' => Http::response(['certificate' => '-----BEGIN CERTIFICATE-----FAKE-----END CERTIFICATE-----']),
            '*/Device/v1/*/GetConfig' => Http::response([
                'taxPayerName' => 'Fiscal Co (Pvt) Ltd', 'taxPayerTIN' => '2000000000',
                'qrUrl' => 'https://fdmstest.zimra.co.zw',
                'applicableTaxes' => [
                    ['taxID' => 1, 'taxName' => 'Exempt'],
                    ['taxID' => 515, 'taxPercent' => 15.5, 'taxName' => 'Standard rated 15.5%'],
                ],
                'certificateValidTill' => '2027-07-24T00:00:00',
            ]),
            '*/Device/v1/*/OpenDay' => Http::response(['fiscalDayNo' => 1, 'operationID' => 'OP1']),
            '*/Device/v1/*/SubmitReceipt' => Http::response([
                'receiptID' => 9001, 'operationID' => 'OP2', 'serverDate' => '2026-07-24T12:00:00',
                'receiptServerSignature' => ['hash' => 'H', 'signature' => 'S', 'certificateThumbprint' => 'T'],
            ]),
            '*/Device/v1/*/CloseDay' => Http::response(['operationID' => 'OP3']),
        ]);
    }

    protected function registerDevice(): FiscalDevice
    {
        return app(DeviceRegistrationService::class)
            ->register($this->company, 10626, '398834', 'SN-001', 'test');
    }

    protected function issueInvoice(float $amount, float $tax): Invoice
    {
        $chart = app(ChartOfAccountsService::class);
        $client = Contact::firstOrCreate(['company_id' => $this->company->id, 'name' => 'Client'], ['type' => 'customer']);

        $invoice = Invoice::create([
            'company_id' => $this->company->id, 'contact_id' => $client->id,
            'invoice_no' => 'INV-'.uniqid(), 'issue_date' => '2026-07-24', 'status' => 'draft',
            'subtotal' => $amount, 'tax_rate_id' => $this->vat->id, 'tax_amount' => $tax,
            'total' => round($amount + $tax, 2), 'currency' => 'USD',
        ]);
        $invoice->items()->create([
            'account_id' => $chart->find($this->company, '4000')->id,
            'description' => 'Consulting', 'quantity' => 1, 'unit_price' => $amount, 'amount' => $amount,
            'hs_code' => '99001000',
        ]);

        return app(AccountingService::class)
            ->issueInvoice($invoice->fresh(['items', 'contact', 'company']), $this->user->id);
    }

    public function test_device_registration_stores_certificate_and_config(): void
    {
        $this->fakeFdms();
        $device = $this->registerDevice();

        $this->assertSame('active', $device->status);
        $this->assertStringContainsString('FAKE', $device->certificate);
        $this->assertStringContainsString('PRIVATE KEY', $device->private_key); // decrypted accessor
        $this->assertSame('Fiscal Co (Pvt) Ltd', $device->config['taxPayerName']);

        // The CSR sent must carry the mandated CN.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'RegisterDevice')) {
                return false;
            }
            $csr = $request->data()['certificateRequest'] ?? '';
            $subject = openssl_csr_get_subject($csr);

            return ($subject['CN'] ?? null) === 'ZIMRA-SN-001-0000010626'
                && ($subject['C'] ?? null) === 'ZW'
                && ($subject['O'] ?? null) === 'Zimbabwe Revenue Authority'
                && ($subject['ST'] ?? null) === 'Zimbabwe'
                && $request->hasHeader('DeviceModelVersion', 'v1')
                && ($request->data()['activationKey'] ?? null) === '00398834';
        });
    }

    public function test_issuing_an_invoice_queues_fiscalisation_when_a_device_is_active(): void
    {
        $this->fakeFdms();
        $this->registerDevice();
        Queue::fake();

        $invoice = $this->issueInvoice(100, 15.50);

        Queue::assertPushed(FiscaliseInvoice::class, fn ($job) => $job->invoice->id === $invoice->id);
    }

    public function test_receipts_are_signed_chained_and_submitted(): void
    {
        $this->fakeFdms();
        $device = $this->registerDevice();
        $fiscalisation = app(FiscalisationService::class);

        $first = $fiscalisation->fiscalise($this->issueInvoice(100, 15.50)->load(['items.account', 'taxRate', 'contact']));
        $second = $fiscalisation->fiscalise($this->issueInvoice(200, 31.00)->load(['items.account', 'taxRate', 'contact']));

        $this->assertSame('submitted', $first->status);
        $this->assertSame(1, $first->receipt_counter);
        $this->assertSame(1, (int) $first->receipt_global_no);
        $this->assertSame(2, $second->receipt_counter);
        $this->assertSame(2, (int) $second->receipt_global_no);
        $this->assertSame(9001, (int) $first->server_receipt_id);

        // Payload structure and amounts.
        $receipt = $first->payload['receipt'];
        $this->assertSame('FiscalInvoice', $receipt['receiptType']);
        $this->assertSame(115.50, $receipt['receiptTotal']);
        $this->assertSame(515, $receipt['receiptTaxes'][0]['taxID']);
        $this->assertSame(15.5, $receipt['receiptTaxes'][0]['taxPercent']);
        $this->assertSame('Credit', $receipt['receiptPayments'][0]['moneyTypeCode']);
        $this->assertSame('99001000', $receipt['receiptLines'][0]['receiptLineHSCode']);

        // The second receipt chains to the first: recompute its sign
        // string with the previous hash and check the stored hash.
        $signer = app(ReceiptSigner::class);
        $r2 = $second->payload['receipt'];
        $expected = $signer->hash($signer->receiptSignString(
            10626, 'FiscalInvoice', 'USD', 2, $r2['receiptDate'], $r2['receiptTotal'], $r2['receiptTaxes'], $first->hash,
        ));
        $this->assertSame($expected, $second->hash);

        // Verification URL carries padded device id + ddMMyyyy + padded global no.
        $this->assertStringStartsWith('https://fdmstest.zimra.co.zw/0000010626', $first->verification_url);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}$/', $first->qr_data);

        $this->assertSame(2, $device->fresh()->last_receipt_global_no);
    }

    public function test_buyer_data_is_omitted_without_a_tin_and_complete_when_identified(): void
    {
        $this->fakeFdms();
        $this->registerDevice();
        Queue::fake();
        $fiscalisation = app(FiscalisationService::class);

        $cash = $fiscalisation->fiscalise($this->issueInvoice(100, 15.50)->load(['items.account', 'taxRate', 'contact']));
        $this->assertArrayNotHasKey('buyerData', $cash->payload['receipt']);

        $invoice = $this->issueInvoice(200, 31.00);
        $invoice->contact->update([
            'tin' => '1000000002',
            'vat_number' => '220000002',
            'trade_name' => 'Buyer Test Branch',
            'email' => 'buyer@example.test',
            'phone' => '0772000002',
            'province' => 'Harare',
            'city' => 'Harare',
            'street' => 'Test Drive',
            'house_no' => '20',
        ]);

        $identified = $fiscalisation->fiscalise($invoice->fresh(['items.account', 'taxRate', 'contact']));
        $buyer = $identified->payload['receipt']['buyerData'];
        $this->assertSame('1000000002', $buyer['buyerTIN']);
        $this->assertSame('220000002', $buyer['vatNumber']);
        $this->assertSame('Harare', $buyer['buyerAddress']['city']);
        $this->assertSame('buyer@example.test', $buyer['buyerContacts']['email']);
    }

    public function test_zero_rated_and_exempt_receipts_use_distinct_zimra_tax_ids(): void
    {
        $this->fakeFdms();
        $device = $this->registerDevice();
        $device->update(['config' => array_merge($device->config, [
            'applicableTaxes' => [
                ['taxID' => 1, 'taxName' => 'Exempt'],
                ['taxID' => 2, 'taxPercent' => 0, 'taxName' => 'Zero rated'],
            ],
        ])]);
        Queue::fake();

        $zero = TaxRate::create([
            'name' => 'Zero Rated', 'rate' => 0, 'is_active' => true, 'zimra_tax_id' => 2,
        ]);
        $zeroInvoice = $this->issueInvoice(100, 0);
        $zeroInvoice->update(['tax_rate_id' => $zero->id]);
        $zeroReceipt = app(FiscalisationService::class)
            ->fiscalise($zeroInvoice->fresh(['items.account', 'taxRate', 'company', 'contact']));

        $exemptInvoice = $this->issueInvoice(50, 0);
        $exemptInvoice->update(['tax_rate_id' => null]);
        $exemptReceipt = app(FiscalisationService::class)
            ->fiscalise($exemptInvoice->fresh(['items.account', 'taxRate', 'company', 'contact']));

        $this->assertSame(2, $zeroReceipt->payload['receipt']['receiptTaxes'][0]['taxID']);
        $this->assertSame(0.0, (float) $zeroReceipt->payload['receipt']['receiptTaxes'][0]['taxPercent']);
        $this->assertSame(1, $exemptReceipt->payload['receipt']['receiptTaxes'][0]['taxID']);
        $this->assertArrayNotHasKey('taxPercent', $exemptReceipt->payload['receipt']['receiptTaxes'][0]);
    }

    public function test_red_fdms_validation_error_marks_receipt_invalid(): void
    {
        Http::fake([
            '*/Public/v1/*/RegisterDevice' => Http::response(['certificate' => 'CERT']),
            '*/Device/v1/*/GetConfig' => Http::response([
                'qrUrl' => 'https://fdmstest.zimra.co.zw',
                'applicableTaxes' => [['taxID' => 1, 'taxName' => 'Exempt']],
            ]),
            '*/Device/v1/*/OpenDay' => Http::response(['fiscalDayNo' => 1]),
            '*/Device/v1/*/SubmitReceipt' => Http::response([
                'receiptID' => 9003,
                'receiptServerSignature' => ['hash' => 'H', 'signature' => 'S'],
                'validationErrors' => [[
                    'validationErrorCode' => 'RCPT043', 'validationErrorColor' => 'Red',
                    'validationErrorDescription' => 'Mandatory buyer data fields are not provided',
                ]],
            ]),
        ]);
        $device = $this->registerDevice();
        Queue::fake();

        $receipt = app(FiscalisationService::class)
            ->fiscalise($this->issueInvoice(100, 15.50)->load(['items.account', 'taxRate', 'contact']));

        $this->assertSame('invalid', $receipt->status);
        $this->assertSame(9003, (int) $receipt->server_receipt_id);
        $this->assertStringContainsString('RCPT043', $receipt->error);
        $this->assertSame($device->id, $receipt->fiscal_device_id);
    }

    public function test_close_day_submits_z_report_counters(): void
    {
        $this->fakeFdms();
        $device = $this->registerDevice();
        $fiscalisation = app(FiscalisationService::class);

        $fiscalisation->fiscalise($this->issueInvoice(100, 15.50)->load(['items.account', 'taxRate', 'contact']));
        $day = $fiscalisation->closeDay($device->fresh());

        $this->assertSame('closed', $day->status);

        $types = collect($day->counters)->pluck('fiscalCounterType');
        $this->assertTrue($types->contains('SaleByTax'));
        $this->assertTrue($types->contains('SaleTaxByTax'));
        $this->assertTrue($types->contains('BalanceByMoneyType'));

        $sale = collect($day->counters)->firstWhere('fiscalCounterType', 'SaleByTax');
        $this->assertSame(115.5, (float) $sale['fiscalCounterValue']);
    }

    public function test_sync_status_reconciles_a_day_closed_in_fdms_admin(): void
    {
        $this->fakeFdms();
        $device = $this->registerDevice();
        $day = app(FiscalisationService::class)->openDay($device);

        Http::fake([
            '*/Device/v1/*/GetStatus' => Http::response([
                'fiscalDayStatus' => 'FiscalDayClosed',
                'fiscalDayClosed' => '2026-08-03T12:42:51',
                'lastFiscalDayNo' => 1,
                'lastReceiptGlobalNo' => 1,
                'fiscalDayCounters' => [],
            ]),
        ]);

        $status = app(FiscalisationService::class)->syncStatus($device->fresh());

        $this->assertSame('FiscalDayClosed', $status['fiscalDayStatus']);
        $this->assertSame('closed', $day->fresh()->status);
        $this->assertSame('2026-08-03 12:42:51', $day->fresh()->closed_at->format('Y-m-d H:i:s'));
        $this->assertNull($device->fresh()->openDay());
        $this->assertSame(1, $device->fresh()->last_receipt_global_no);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/GetStatus'));
    }

    public function test_credit_note_references_the_original_receipt(): void
    {
        $this->fakeFdms();
        $this->registerDevice();
        $fiscalisation = app(FiscalisationService::class);

        $invoice = $this->issueInvoice(100, 15.50);
        $original = $fiscalisation->fiscalise($invoice->load(['items.account', 'taxRate', 'contact']));

        $creditNote = $fiscalisation->creditNote($invoice->fresh(['items.account', 'taxRate', 'company', 'contact']));

        $receipt = $creditNote->payload['receipt'];
        $this->assertSame('CreditNote', $receipt['receiptType']);
        $this->assertSame(-115.5, (float) $receipt['receiptTotal']);
        $this->assertSame(-100.0, (float) $receipt['receiptLines'][0]['receiptLineTotal']);
        $this->assertSame((int) $original->receipt_global_no, $receipt['creditDebitNote']['receiptGlobalNo']);
        $this->assertSame(9001, $receipt['creditDebitNote']['receiptID']);
        $this->assertSame($original->id, $creditNote->original_fiscal_receipt_id);
    }

    public function test_failed_submission_is_replayed_not_rebuilt(): void
    {
        Http::fake([
            '*/Public/v1/*/RegisterDevice' => Http::response(['certificate' => 'CERT']),
            '*/Device/v1/*/GetConfig' => Http::response(['qrUrl' => 'https://fdmstest.zimra.co.zw', 'applicableTaxes' => []]),
            '*/Device/v1/*/OpenDay' => Http::response(['fiscalDayNo' => 1]),
            '*/Device/v1/*/SubmitReceipt' => Http::sequence()
                ->push(null, 500)
                ->push(['receiptID' => 9002]),
        ]);

        $device = $this->registerDevice();
        $fiscalisation = app(FiscalisationService::class);

        // Keep the sync queue from consuming the faked HTTP sequence.
        Queue::fake();
        $invoice = $this->issueInvoice(100, 15.50)->load(['items.account', 'taxRate', 'contact']);

        try {
            $fiscalisation->fiscalise($invoice);
            $this->fail('First submission should have failed.');
        } catch (\RuntimeException) {
            // expected — the job would retry
        }

        $failed = FiscalReceipt::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('failed', $failed->status);

        // The retry resubmits the SAME reserved receipt — counters and
        // hash chain intact, no new receipt row.
        $retried = $fiscalisation->fiscalise($invoice);
        $this->assertSame($failed->id, $retried->id);
        $this->assertSame('submitted', $retried->status);
        $this->assertSame(1, FiscalReceipt::where('invoice_id', $invoice->id)->count());
        $this->assertSame(1, $device->fresh()->last_receipt_global_no);
    }

    public function test_monitor_stops_alerting_once_a_failed_receipt_is_acknowledged(): void
    {
        $receipt = $this->failedReceipt();

        // An unacknowledged failure is a live alert.
        $this->artisan('fdms:monitor')->assertExitCode(1);

        $receipt->update(['alert_acknowledged_at' => now(), 'alert_acknowledged_note' => 'Voided at ZIMRA by phone']);

        $this->artisan('fdms:monitor')->assertExitCode(0);
    }

    public function test_acknowledging_a_receipt_requires_a_system_admin_and_a_reason(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $receipt = $this->failedReceipt();

        $outsider = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->actingAs($outsider)
            ->post("/settings/fiscalisation/receipts/{$receipt->id}/acknowledge", ['note' => 'nope'])
            ->assertForbidden();

        $admin = User::factory()->create(['current_company_id' => $this->company->id]);
        setPermissionsTeamId($this->company->id);
        $admin->assignRole('system-admin');

        $this->actingAs($admin)
            ->post("/settings/fiscalisation/receipts/{$receipt->id}/acknowledge", ['note' => ''])
            ->assertSessionHasErrors('note');
        $this->assertNull($receipt->fresh()->alert_acknowledged_at);

        $this->actingAs($admin)
            ->post("/settings/fiscalisation/receipts/{$receipt->id}/acknowledge", ['note' => 'Reported to ZIMRA, ref 4471'])
            ->assertRedirect();

        $receipt->refresh();
        $this->assertNotNull($receipt->alert_acknowledged_at);
        $this->assertSame($admin->id, $receipt->alert_acknowledged_by);
        // Acknowledging records a decision; it must not rewrite the receipt log.
        $this->assertSame('failed', $receipt->status);
    }

    protected function failedReceipt(): FiscalReceipt
    {
        $this->fakeFdms();
        $device = $this->registerDevice();
        $day = app(FiscalisationService::class)->openDay($device);

        return FiscalReceipt::create([
            'fiscal_device_id' => $device->id, 'fiscal_day_id' => $day->id,
            'receipt_type' => 'FiscalInvoice', 'currency' => 'USD', 'receipt_counter' => 1,
            'receipt_global_no' => 1, 'invoice_no' => 'INV-1', 'receipt_date' => now(),
            'total' => 115.50, 'payload' => [], 'hash' => 'H', 'device_signature' => 'S',
            'status' => 'failed', 'error' => 'RCPT020 signature rejected',
        ]);
    }
}
