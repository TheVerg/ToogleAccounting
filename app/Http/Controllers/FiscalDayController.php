<?php

namespace App\Http\Controllers;

use App\Models\FiscalDevice;
use App\Models\Invoice;
use App\Services\Fdms\FiscalisationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class FiscalDayController extends Controller
{
    /** Refresh authoritative fiscal-day state from FDMS. */
    public function sync(Request $request, FiscalisationService $fiscalisation): RedirectResponse
    {
        abort_unless($request->user()->isSystemAdmin(), 403);
        $device = $this->device($request);

        try {
            $status = $fiscalisation->syncStatus($device);
        } catch (Throwable $e) {
            return back()->with('error', 'Could not refresh ZIMRA status: '.$e->getMessage());
        }

        $label = $status['fiscalDayStatus'] ?? 'unknown';

        return back()->with('success', 'ZIMRA status refreshed: '.$label.'.');
    }

    /** Open a fiscal day manually (it also auto-opens on the first invoice). */
    public function open(Request $request, FiscalisationService $fiscalisation): RedirectResponse
    {
        abort_unless($request->user()->isSystemAdmin(), 403);
        $device = $this->device($request);

        try {
            $day = DB::transaction(function () use ($device, $fiscalisation) {
                $device = FiscalDevice::whereKey($device->id)->lockForUpdate()->firstOrFail();
                abort_if($device->openDay() !== null, 422, 'A fiscal day is already open.');

                return $fiscalisation->openDay($device);
            });
        } catch (Throwable $e) {
            return back()->with('error', 'Could not open the fiscal day: '.$e->getMessage());
        }

        return back()->with('success', 'Fiscal day #'.$day->fiscal_day_no.' opened.');
    }

    /** Close the fiscal day and submit the Z-report. */
    public function close(Request $request, FiscalisationService $fiscalisation): RedirectResponse
    {
        abort_unless($request->user()->isSystemAdmin(), 403);
        $device = $this->device($request);

        try {
            $day = $fiscalisation->closeDay($device);
        } catch (Throwable $e) {
            return back()->with('error', 'Could not close the fiscal day: '.$e->getMessage());
        }

        return back()->with('success', 'Fiscal day #'.$day->fiscal_day_no.' closed and the Z-report submitted.');
    }

    /** Retry fiscalising an invoice whose submission failed. */
    public function retry(Request $request, Invoice $invoice, FiscalisationService $fiscalisation): RedirectResponse
    {
        abort_unless($invoice->company_id === $request->user()->current_company_id, 403);

        try {
            $receipt = $fiscalisation->fiscalise($invoice->load(['items.account', 'taxRate', 'company', 'contact']));
        } catch (Throwable $e) {
            return back()->with('error', 'Fiscalisation failed: '.$e->getMessage());
        }

        return back()->with('success',
            $invoice->invoice_no.' fiscalised (receipt #'.$receipt->receipt_global_no.').');
    }

    protected function device(Request $request): FiscalDevice
    {
        $device = FiscalDevice::where('company_id', $request->user()->current_company_id)
            ->where('status', 'active')
            ->orderByDesc('id')
            ->first();
        abort_unless($device !== null, 422, 'No active fiscal device is registered.');

        return $device;
    }
}
