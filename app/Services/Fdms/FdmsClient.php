<?php

namespace App\Services\Fdms;

use App\Models\FiscalDevice;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Transport for ZIMRA's Fiscal Device Gateway API. RegisterDevice is
 * plain HTTPS (activation-key auth); every other method uses mutual TLS
 * with the FDMS-issued client certificate. The DeviceModelName /
 * DeviceModelVersion headers must match registration byte-for-byte
 * (FDMS 403s otherwise).
 */
class FdmsClient
{
    public function baseUrl(string $environment): string
    {
        return $environment === 'production'
            ? config('services.fdms.production_url')
            : config('services.fdms.test_url');
    }

    public function qrBaseUrl(string $environment): string
    {
        return $environment === 'production'
            ? config('services.fdms.production_qr_url')
            : config('services.fdms.test_qr_url');
    }

    /** POST /Public/v1/{deviceID}/RegisterDevice — returns the certificate PEM. */
    public function registerDevice(int $deviceId, string $environment, string $activationKey, string $csrPem): string
    {
        $response = $this->request()
            ->post($this->baseUrl($environment)."/Public/v1/{$deviceId}/RegisterDevice", [
                'activationKey' => str_pad($activationKey, 8, '0', STR_PAD_LEFT),
                'certificateRequest' => $csrPem,
            ]);

        $this->guard($response, 'registerDevice');

        $certificate = $response->json('certificate');
        if (! $certificate) {
            throw new RuntimeException('FDMS did not return a certificate.');
        }

        return $certificate;
    }

    public function getConfig(FiscalDevice $device): array
    {
        return $this->deviceCall($device, fn ($http, $base) => $http->get("{$base}/GetConfig"), 'getConfig');
    }

    public function getStatus(FiscalDevice $device): array
    {
        return $this->deviceCall($device, fn ($http, $base) => $http->get("{$base}/GetStatus"), 'getStatus');
    }

    public function openDay(FiscalDevice $device, array $payload): array
    {
        return $this->deviceCall($device, fn ($http, $base) => $http->post("{$base}/OpenDay", $payload), 'openDay');
    }

    public function submitReceipt(FiscalDevice $device, array $payload): array
    {
        return $this->deviceCall($device, fn ($http, $base) => $http->post("{$base}/SubmitReceipt", $payload), 'submitReceipt');
    }

    public function closeDay(FiscalDevice $device, array $payload): array
    {
        return $this->deviceCall($device, fn ($http, $base) => $http->post("{$base}/CloseDay", $payload), 'closeDay');
    }

    /** Run one mTLS call, materialising the cert/key as temp files only for its duration. */
    protected function deviceCall(FiscalDevice $device, callable $call, string $method): array
    {
        $certPath = tempnam(sys_get_temp_dir(), 'fdc');
        $keyPath = tempnam(sys_get_temp_dir(), 'fdk');

        try {
            file_put_contents($certPath, $device->certificate);
            file_put_contents($keyPath, $device->private_key);
            @chmod($keyPath, 0600);

            $http = $this->request($device->model_name, $device->model_version)
                ->withOptions(['cert' => $certPath, 'ssl_key' => $keyPath]);

            $base = $this->baseUrl($device->environment)."/Device/v1/{$device->device_id}";
            $response = $call($http, $base);

            $this->guard($response, $method);

            return $response->json() ?? [];
        } finally {
            @unlink($certPath);
            @unlink($keyPath);
        }
    }

    protected function request(?string $modelName = null, ?string $modelVersion = null): PendingRequest
    {
        return Http::timeout(30)
            ->acceptJson()
            ->withHeaders([
                'DeviceModelName' => $modelName ?? config('services.fdms.model_name'),
                // The deployed FDMS Swagger requires DeviceModelVersion. Older
                // PDF revisions called this DeviceModelVersionNo.
                'DeviceModelVersion' => $modelVersion ?? config('services.fdms.model_version'),
            ]);
    }

    protected function guard(Response $response, string $method): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException(
            "FDMS {$method} failed (HTTP {$response->status()}): ".mb_substr($response->body(), 0, 500)
        );
    }
}
