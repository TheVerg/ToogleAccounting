<?php

namespace App\Console\Commands;

use App\Models\FiscalDevice;
use App\Services\Fdms\FiscalisationService;
use Illuminate\Console\Command;
use Throwable;

class AutoCloseFiscalDays extends Command
{
    protected $signature = 'fdms:auto-close';
    protected $description = 'Close eligible open fiscal days after the configured cutoff';

    public function handle(FiscalisationService $service): int
    {
        if (! config('services.fdms.auto_close_enabled')) {
            $this->components->info('FDMS automatic closure is disabled.');
            return self::SUCCESS;
        }

        $failed = false;
        FiscalDevice::where('status', 'active')->with('company:id,name')->get()->each(function ($device) use ($service, &$failed) {
            if (! $device->openDay()) return;
            try {
                $service->closeDay($device);
                $this->info("Closed {$device->company->name} fiscal day.");
            } catch (Throwable $e) {
                $failed = true;
                $this->error("{$device->company->name}: {$e->getMessage()}");
            }
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
