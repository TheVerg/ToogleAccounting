<?php

namespace App\Services\Fdms;

use InvalidArgumentException;

/**
 * Builds the exact strings FDMS hashes/verifies and signs them with the
 * device key (SHA-256 + RSA PKCS#1 v1.5, both base64). The concatenation
 * formats follow the Fiscal Device Gateway API spec, cross-checked
 * against two independent reference implementations — every byte
 * matters (RCPT020: "invoice signature is not valid").
 */
class ReceiptSigner
{
    /**
     * Receipt signature source string:
     * deviceID + RECEIPTTYPE + CURRENCY + globalNo + receiptDate +
     * totalInCents + taxes (sorted by taxID: percent 2dp | '' for exempt,
     * taxAmount cents, salesAmountWithTax cents) + previousReceiptHash
     * (only when not the first receipt of the fiscal day).
     */
    public function receiptSignString(
        int $deviceId,
        string $receiptType,
        string $currency,
        int $receiptGlobalNo,
        string $receiptDate,
        float $receiptTotal,
        array $receiptTaxes,
        ?string $previousReceiptHash,
    ): string {
        $taxes = collect($receiptTaxes)
            ->sortBy('taxID')
            ->map(fn ($tax) => (array_key_exists('taxPercent', $tax) && $tax['taxPercent'] !== null
                    ? number_format((float) $tax['taxPercent'], 2, '.', '')
                    : '')
                .$this->cents((float) $tax['taxAmount'])
                .$this->cents((float) $tax['salesAmountWithTax']))
            ->implode('');

        return $deviceId
            .strtoupper($receiptType)
            .strtoupper($currency)
            .$receiptGlobalNo
            .$receiptDate
            .$this->cents($receiptTotal)
            .$taxes
            .($previousReceiptHash ?? '');
    }

    /**
     * Fiscal day (Z-report) signature source string, uppercased:
     * deviceID + fiscalDayNo + openedDate(Y-m-d) + counters
     * (non-zero, sorted by type/currency/taxID/moneyType; each:
     * TYPE + CURRENCY + percent 2dp|'' + MONEYTYPE + value cents).
     */
    public function closeDaySignString(int $deviceId, int $fiscalDayNo, string $openedDate, array $counters): string
    {
        $typeOrder = array_flip([
            'SaleByTax', 'SaleTaxByTax', 'CreditNoteByTax', 'CreditNoteTaxByTax',
            'DebitNoteByTax', 'DebitNoteTaxByTax', 'BalanceByMoneyType',
            'PayoutByTax', 'PayoutTaxByTax',
        ]);

        $concatenated = collect($counters)
            ->filter(fn ($c) => round((float) $c['fiscalCounterValue'], 2) != 0.0)
            ->sortBy([
                fn ($a, $b) => ($typeOrder[$a['fiscalCounterType']] ?? 99) <=> ($typeOrder[$b['fiscalCounterType']] ?? 99),
                fn ($a, $b) => strcmp($a['fiscalCounterCurrency'], $b['fiscalCounterCurrency']),
                fn ($a, $b) => ($a['fiscalCounterTaxID'] ?? 0) <=> ($b['fiscalCounterTaxID'] ?? 0),
                fn ($a, $b) => strcmp($a['fiscalCounterMoneyType'] ?? '', $b['fiscalCounterMoneyType'] ?? ''),
            ])
            ->map(fn ($c) => strtoupper($c['fiscalCounterType'])
                .strtoupper($c['fiscalCounterCurrency'])
                .(array_key_exists('fiscalCounterTaxPercent', $c) && $c['fiscalCounterTaxPercent'] !== null
                    ? number_format((float) $c['fiscalCounterTaxPercent'], 2, '.', '')
                    : '')
                .strtoupper($c['fiscalCounterMoneyType'] ?? '')
                .$this->cents((float) $c['fiscalCounterValue']))
            ->implode('');

        return strtoupper($deviceId.$fiscalDayNo.$openedDate.$concatenated);
    }

    /** base64( SHA-256(utf8 string) ) — also the chain link for the next receipt. */
    public function hash(string $signString): string
    {
        return base64_encode(hash('sha256', $signString, true));
    }

    /** base64( RSA-PKCS#1-v1.5-SHA256 signature of the string ). */
    public function sign(string $signString, string $privateKeyPem): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new InvalidArgumentException('The fiscal device private key could not be read.');
        }
        if (! openssl_sign($signString, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new InvalidArgumentException('Signing the fiscal receipt failed: '.openssl_error_string());
        }

        return base64_encode($signature);
    }

    /**
     * QR verification URL:
     * qrUrl / deviceId(10, zero-padded) + receiptDate(dmY) +
     * globalNo(10, zero-padded) + first 16 hex chars of MD5(signature bytes).
     */
    public function verificationUrl(string $qrUrl, int $deviceId, \DateTimeInterface $receiptDate, int $receiptGlobalNo, string $base64Signature): string
    {
        return rtrim($qrUrl, '/').'/'
            .str_pad((string) $deviceId, 10, '0', STR_PAD_LEFT)
            .$receiptDate->format('dmY')
            .str_pad((string) $receiptGlobalNo, 10, '0', STR_PAD_LEFT)
            .$this->qrHex($base64Signature);
    }

    /** Human verification code: the same 16 hex chars in dashed groups of 4. */
    public function verificationCode(string $base64Signature): string
    {
        return implode('-', str_split($this->qrHex($base64Signature), 4));
    }

    protected function qrHex(string $base64Signature): string
    {
        return strtoupper(substr(md5(base64_decode($base64Signature)), 0, 16));
    }

    /** Amount in cents as an integer string (2dp-rounded first): 21.00 → "2100". */
    protected function cents(float $amount): string
    {
        return (string) (int) round(round($amount, 2) * 100);
    }
}
