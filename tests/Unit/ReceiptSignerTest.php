<?php

namespace Tests\Unit;

use App\Services\Fdms\ReceiptSigner;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class ReceiptSignerTest extends TestCase
{
    protected ReceiptSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signer = new ReceiptSigner;
    }

    public function test_receipt_sign_string_matches_the_spec_format(): void
    {
        $string = $this->signer->receiptSignString(
            10626, 'FiscalInvoice', 'USD', 45, '2024-11-06T15:47:14', 21.00,
            [['taxID' => 515, 'taxPercent' => 15.5, 'taxAmount' => 2.82, 'salesAmountWithTax' => 21.00]],
            null,
        );

        $this->assertSame('10626FISCALINVOICEUSD452024-11-06T15:47:14210015.502822100', $string);
    }

    public function test_exempt_tax_omits_the_percent_and_chain_appends_previous_hash(): void
    {
        $string = $this->signer->receiptSignString(
            10626, 'FiscalInvoice', 'ZWG', 46, '2024-11-06T16:00:00', 100.00,
            [['taxID' => 1, 'taxAmount' => 0.00, 'salesAmountWithTax' => 100.00]],
            'PREVIOUSHASHBASE64==',
        );

        $this->assertSame('10626FISCALINVOICEZWG462024-11-06T16:00:0010000010000PREVIOUSHASHBASE64==', $string);
    }

    public function test_taxes_are_sorted_by_tax_id(): void
    {
        $string = $this->signer->receiptSignString(
            1, 'FiscalInvoice', 'USD', 1, '2024-01-01T00:00:00', 1.00,
            [
                ['taxID' => 515, 'taxPercent' => 15.5, 'taxAmount' => 0.10, 'salesAmountWithTax' => 0.60],
                ['taxID' => 2, 'taxPercent' => 0.0, 'taxAmount' => 0.00, 'salesAmountWithTax' => 0.40],
            ],
            null,
        );

        // taxID 2 (0.00...) must come before taxID 515.
        $this->assertSame('1FISCALINVOICEUSD12024-01-01T00:00:001000.0004015.501060', $string);
    }

    public function test_empty_close_day_string_matches_the_reference_vector(): void
    {
        // Reference vector from the Python implementation: device 10626,
        // day 17, opened 2024-09-02, no counters -> "10626172024-09-02".
        $string = $this->signer->closeDaySignString(10626, 17, '2024-09-02', []);

        $this->assertSame('10626172024-09-02', $string);
    }

    public function test_close_day_counters_are_filtered_sorted_and_uppercased(): void
    {
        $string = $this->signer->closeDaySignString(10626, 17, '2024-09-02', [
            ['fiscalCounterType' => 'BalanceByMoneyType', 'fiscalCounterCurrency' => 'USD',
                'fiscalCounterMoneyType' => 'Credit', 'fiscalCounterValue' => 21.00],
            ['fiscalCounterType' => 'SaleTaxByTax', 'fiscalCounterCurrency' => 'USD',
                'fiscalCounterTaxID' => 515, 'fiscalCounterTaxPercent' => 15.5, 'fiscalCounterValue' => 2.82],
            ['fiscalCounterType' => 'SaleByTax', 'fiscalCounterCurrency' => 'USD',
                'fiscalCounterTaxID' => 515, 'fiscalCounterTaxPercent' => 15.5, 'fiscalCounterValue' => 21.00],
            ['fiscalCounterType' => 'SaleByTax', 'fiscalCounterCurrency' => 'ZWG',
                'fiscalCounterTaxID' => 1, 'fiscalCounterValue' => 0.0], // zero: dropped
        ]);

        $this->assertSame(
            '10626172024-09-02'
            .'SALEBYTAXUSD15.502100'
            .'SALETAXBYTAXUSD15.50282'
            .'BALANCEBYMONEYTYPEUSDCREDIT2100',
            $string,
        );
    }

    public function test_hash_is_base64_sha256(): void
    {
        $this->assertSame(
            base64_encode(hash('sha256', 'abc', true)),
            $this->signer->hash('abc'),
        );
    }

    public function test_signature_verifies_against_the_public_key(): void
    {
        $args = \App\Services\Fdms\DeviceRegistrationService::opensslArgs();
        $key = openssl_pkey_new($args + ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key, 'OpenSSL key generation unavailable: '.openssl_error_string());
        openssl_pkey_export($key, $privatePem, null, $args);
        $publicPem = openssl_pkey_get_details($key)['key'];

        $signature = $this->signer->sign('test-string', $privatePem);

        $this->assertSame(1, openssl_verify(
            'test-string',
            base64_decode($signature),
            $publicPem,
            OPENSSL_ALGO_SHA256,
        ));
    }

    public function test_verification_url_and_code_format(): void
    {
        $signature = base64_encode('signature-bytes');
        $expectedHex = strtoupper(substr(md5('signature-bytes'), 0, 16));

        $url = $this->signer->verificationUrl(
            'https://fdmstest.zimra.co.zw/', 10626, Carbon::parse('2024-09-02'), 45, $signature,
        );

        // 10-digit device id + ddMMyyyy + 10-digit global no + 16 hex chars.
        $this->assertSame(
            'https://fdmstest.zimra.co.zw/0000010626020920240000000045'.$expectedHex,
            $url,
        );

        $code = $this->signer->verificationCode($signature);
        $this->assertSame(implode('-', str_split($expectedHex, 4)), $code);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}$/', $code);
    }
}
