<?php

namespace App\Console\Commands;

use App\Models\FiscalDevice;
use App\Models\FiscalReceipt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class MonitorFdms extends Command
{
    /** Cap on how many individual receipts one alert enumerates before summarising. */
    protected const MAX_LISTED = 10;

    protected $signature = 'fdms:monitor {--notify : Send a configured alert email}';

    protected $description = 'Detect expiring FDMS certificates, stalled receipts, unacknowledged failed receipts and a stalled queue';

    public function handle(): int
    {
        $warnings = [];
        $certificateDays = (int) config('services.fdms.certificate_warning_days', 30);
        $pendingMinutes = (int) config('services.fdms.pending_warning_minutes', 15);

        FiscalDevice::with('company:id,name')->where('status', 'active')->get()->each(function ($device) use (&$warnings, $certificateDays) {
            $company = $device->company?->name ?? "device {$device->id}";
            if (! $device->certificate_valid_until) {
                $warnings[] = "{$company}: FDMS certificate expiry is unknown";
            } elseif ($device->certificate_valid_until->lte(today()->addDays($certificateDays))) {
                $warnings[] = "{$company}: FDMS certificate expires {$device->certificate_valid_until->toDateString()}";
            }
        });

        $pending = FiscalReceipt::with('device.company:id,name')->where('status', 'pending')
            ->where('created_at', '<=', now()->subMinutes($pendingMinutes))->get();
        $warnings = array_merge($warnings, $this->summarise(
            $pending,
            fn ($receipt) => $this->company($receipt).": receipt {$receipt->receipt_global_no} pending since {$receipt->created_at}",
            'receipt(s) pending beyond the threshold',
        ));

        // Only failures nobody has signed off: an acknowledged receipt has been
        // triaged, so repeating it every five minutes buries the live alerts.
        $failed = FiscalReceipt::with('device.company:id,name')->unacknowledgedFailures()->get();
        $warnings = array_merge($warnings, $this->summarise(
            $failed,
            fn ($receipt) => $this->company($receipt).": receipt {$receipt->receipt_global_no} {$receipt->status}: {$receipt->error}",
            'unacknowledged failed/invalid receipt(s)',
        ));

        $oldestJob = DB::table('jobs')->min('available_at');
        if ($oldestJob && $oldestJob <= now()->subMinutes($pendingMinutes)->timestamp) {
            $warnings[] = 'Queue appears stalled: an available job is older than the FDMS pending threshold.';
        }

        if ($warnings === []) {
            $this->info('FDMS operational checks passed.');

            return self::SUCCESS;
        }

        foreach ($warnings as $warning) {
            $this->error($warning);
        }
        Log::critical('FDMS operational alert', ['warnings' => $warnings]);

        $to = config('services.fdms.alert_email');
        if ($this->option('notify') && $to) {
            try {
                Mail::raw(implode("\n", $warnings), fn ($message) => $message->to($to)->subject('FDMS operational alert'));
            } catch (Throwable $e) {
                // A dead mailer must not mask the findings already logged above.
                $this->error("Alert email could not be sent: {$e->getMessage()}");
                Log::error('FDMS alert email failed', ['exception' => $e->getMessage()]);
            }
        }

        return self::FAILURE;
    }

    /** List up to MAX_LISTED offenders, then collapse the tail into a count. */
    protected function summarise($receipts, callable $describe, string $noun): array
    {
        if ($receipts->isEmpty()) {
            return [];
        }

        $lines = $receipts->take(self::MAX_LISTED)->map($describe)->values()->all();
        if ($receipts->count() > self::MAX_LISTED) {
            $lines[] = '... and '.($receipts->count() - self::MAX_LISTED)." more {$noun}.";
        }

        return $lines;
    }

    protected function company(FiscalReceipt $receipt): string
    {
        return $receipt->device?->company?->name ?? "device {$receipt->fiscal_device_id}";
    }
}
