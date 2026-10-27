<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\FiscalDevice;
use App\Models\FiscalReceipt;
use App\Services\Fdms\DeviceRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Fiscalisation settings — system-admin only (consistent with Opening
 * Balances / Omit gating). The client enters the Device ID + Activation
 * Key issued to them by ZIMRA; the app generates the keypair, registers
 * the device, and signs receipts with the stored certificate thereafter.
 */
class FiscalDeviceController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->isSystemAdmin(), 403);
        $company = $request->user()->currentCompany;

        $device = FiscalDevice::where('company_id', $company->id)
            ->orderByDesc('id')
            ->first();

        return Inertia::render('Admin/Fiscalisation', [
            'device' => $device ? [
                'id' => $device->id,
                'device_id' => $device->device_id,
                'serial_no' => $device->serial_no,
                'environment' => $device->environment,
                'status' => $device->status,
                'certificate_valid_until' => $device->certificate_valid_until?->toDateString(),
                'taxpayer' => $device->config['taxPayerName'] ?? null,
                'tin' => $device->config['taxPayerTIN'] ?? null,
                'vat_number' => $device->config['vatNumber'] ?? null,
                'branch' => $device->config['deviceBranchName'] ?? null,
                'taxes' => $device->config['applicableTaxes'] ?? [],
                'last_fiscal_day_no' => $device->last_fiscal_day_no,
                'last_receipt_global_no' => $device->last_receipt_global_no,
                'open_day' => $device->openDay()?->only(['fiscal_day_no', 'opened_at', 'receipt_counter']),
            ] : null,
            'recentReceipts' => $device
                ? FiscalReceipt::where('fiscal_device_id', $device->id)
                    ->with(['invoice:id,invoice_no', 'acknowledgedBy:id,name'])
                    ->latest('id')->limit(25)
                    ->get(['id', 'invoice_id', 'receipt_type', 'currency', 'receipt_counter',
                        'receipt_global_no', 'invoice_no', 'receipt_date', 'total', 'status', 'error', 'verification_url',
                        'alert_acknowledged_at', 'alert_acknowledged_by', 'alert_acknowledged_note'])
                : [],
        ]);
    }

    public function store(Request $request, DeviceRegistrationService $registration): RedirectResponse
    {
        abort_unless($request->user()->isSystemAdmin(), 403);
        $company = $request->user()->currentCompany;

        $data = $request->validate([
            'device_id' => ['required', 'integer', 'min:1'],
            'activation_key' => ['required', 'string', 'max:64'],
            'serial_no' => ['required', 'string', 'max:30'],
            'environment' => ['required', Rule::in(['test', 'production'])],
        ]);

        abort_if(
            FiscalDevice::where('company_id', $company->id)
                ->where('environment', $data['environment'])
                ->where('status', 'active')->exists(),
            422,
            'An active fiscal device already exists for this company in that environment.',
        );

        try {
            $device = $registration->register(
                $company,
                (int) $data['device_id'],
                $data['activation_key'],
                $data['serial_no'],
                $data['environment'],
            );
        } catch (Throwable $e) {
            return back()->withErrors(['device_id' => 'Registration failed: '.$e->getMessage()]);
        }

        return back()->with('success',
            'Fiscal device '.$device->device_id.' registered for '
            .($device->config['taxPayerName'] ?? $company->name).'. Invoices will now be fiscalised.');
    }

    /**
     * Sign off a failed/invalid receipt so `fdms:monitor` stops alerting on it.
     * This records the decision; it does not alter the receipt or the hash chain,
     * and a later retry that succeeds clears the failure state on its own.
     */
    public function acknowledgeReceipt(Request $request, FiscalReceipt $fiscalReceipt): RedirectResponse
    {
        abort_unless($request->user()->isSystemAdmin(), 403);
        abort_unless(
            $fiscalReceipt->device?->company_id === $request->user()->current_company_id,
            403,
        );
        abort_unless(in_array($fiscalReceipt->status, ['failed', 'invalid'], true), 422,
            'Only failed or invalid receipts can be acknowledged.');

        $data = $request->validate(['note' => ['required', 'string', 'max:255']]);

        $fiscalReceipt->update([
            'alert_acknowledged_at' => now(),
            'alert_acknowledged_by' => $request->user()->id,
            'alert_acknowledged_note' => $data['note'],
        ]);

        // FiscalReceipt carries no company_id of its own — it hangs off the device.
        AuditLog::record('fiscal_receipt_alert_acknowledged', $fiscalReceipt, [
            'note' => ['old' => null, 'new' => $data['note']],
        ], $fiscalReceipt->device->company_id);

        return back()->with('success', "Receipt {$fiscalReceipt->receipt_global_no} acknowledged — it will no longer raise alerts.");
    }
}
