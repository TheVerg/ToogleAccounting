<?php

namespace App\Services\Fdms;

use App\Models\Company;
use App\Models\FiscalDevice;
use RuntimeException;

/**
 * Registers this software as a Virtual Fiscal Device with FDMS: generate
 * an RSA-2048 keypair locally (the private key never leaves the server),
 * send a CSR with the one-time activation key, store the issued
 * certificate, and snapshot the taxpayer configuration.
 */
class DeviceRegistrationService
{
    public function __construct(protected FdmsClient $client) {}

    public function register(Company $company, int $deviceId, string $activationKey, string $serialNo, string $environment): FiscalDevice
    {
        [$privateKeyPem, $csrPem] = $this->generateKeyAndCsr($deviceId, $serialNo);

        $certificate = $this->client->registerDevice($deviceId, $environment, $activationKey, $csrPem);

        $device = FiscalDevice::updateOrCreate(
            ['company_id' => $company->id, 'environment' => $environment],
            [
                'device_id' => $deviceId,
                'serial_no' => $serialNo,
                'model_name' => config('services.fdms.model_name'),
                'model_version' => config('services.fdms.model_version'),
                'status' => 'active',
                'certificate' => $certificate,
                'private_key' => $privateKeyPem,
            ],
        );

        // Confirmation + operating data: taxpayer name/TIN/branch, the
        // applicable taxes as ZIMRA knows them, and the QR base URL.
        try {
            $config = $this->client->getConfig($device);
            $device->update([
                'config' => $config,
                'certificate_valid_until' => isset($config['certificateValidTill'])
                    ? substr((string) $config['certificateValidTill'], 0, 10)
                    : null,
            ]);
        } catch (RuntimeException) {
            // Registration succeeded; config can be fetched later.
        }

        return $device->fresh();
    }

    /**
     * RSA-2048 + SHA-256 CSR with the mandated subject:
     * CN=ZIMRA-{serialNo}-{deviceId zero-padded to 10 digits}.
     */
    protected function generateKeyAndCsr(int $deviceId, string $serialNo): array
    {
        $args = self::opensslArgs() + [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ];

        $key = openssl_pkey_new($args);
        if ($key === false) {
            throw new RuntimeException('Could not generate the device keypair: '.openssl_error_string());
        }

        $commonName = sprintf('ZIMRA-%s-%s', $serialNo, str_pad((string) $deviceId, 10, '0', STR_PAD_LEFT));

        $csr = openssl_csr_new(
            [
                'commonName' => $commonName,
                'countryName' => 'ZW',
                'organizationName' => 'Zimbabwe Revenue Authority',
                'stateOrProvinceName' => 'Zimbabwe',
            ],
            $key,
            $args,
        );
        if ($csr === false) {
            throw new RuntimeException('Could not generate the certificate request: '.openssl_error_string());
        }

        openssl_pkey_export($key, $privateKeyPem, null, self::opensslArgs());
        openssl_csr_export($csr, $csrPem);

        return [$privateKeyPem, $csrPem];
    }

    /**
     * Windows PHP builds often ship without a default OpenSSL config;
     * point at the bundled one when the environment doesn't.
     */
    public static function opensslArgs(): array
    {
        if (getenv('OPENSSL_CONF') !== false) {
            return [];
        }

        foreach ([
            dirname(PHP_BINARY).'/extras/ssl/openssl.cnf',
            dirname(PHP_BINARY).'/extras/openssl/openssl.cnf',
        ] as $cnf) {
            if (is_file($cnf)) {
                return ['config' => $cnf];
            }
        }

        return [];
    }
}
