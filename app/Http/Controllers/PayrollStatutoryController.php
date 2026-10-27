<?php

namespace App\Http\Controllers;

use App\Models\PayrollStatutoryRate;
use App\Models\PayrollTaxBand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PayrollStatutoryController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantId = $request->user()->tenant_id;

        return Inertia::render('Settings/PayrollStatutory', [
            'bands' => PayrollTaxBand::visibleToTenant($tenantId)
                ->orderByDesc('effective_from')->orderBy('currency')->orderBy('lower_bound')->get(),
            'rates' => PayrollStatutoryRate::visibleToTenant($tenantId)
                ->orderByDesc('effective_from')->orderBy('code')->get(),
            'tenantId' => $tenantId,
        ]);
    }

    public function storeBand(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'currency' => ['required', 'string', 'size:3'], 'frequency' => ['required', Rule::in(['monthly', 'weekly', 'biweekly'])],
            'effective_from' => ['required', 'date'], 'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'lower_bound' => ['required', 'numeric', 'min:0'], 'upper_bound' => ['nullable', 'numeric', 'gt:lower_bound'],
            'rate' => ['required', 'numeric', 'min:0', 'max:1'], 'deduction' => ['nullable', 'numeric', 'min:0'],
            'source_url' => ['required', 'url'], 'is_approved' => ['required', 'boolean'],
        ]);
        $data['currency'] = strtoupper($data['currency']);
        $data['deduction'] = $data['deduction'] ?? 0;

        $this->assertBandDoesNotOverlap($request->user()->tenant_id, $data);

        PayrollTaxBand::create($data + ['jurisdiction' => 'ZW', 'tenant_id' => $request->user()->tenant_id]);

        return back()->with('success', 'PAYE band saved.');
    }

    public function storeRate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', Rule::in(['aids_levy', 'nssa_pobs', 'nssa_apwcs', 'zimdef', 'nec', 'corporate_tax'])],
            'currency' => ['nullable', 'string', 'size:3'], 'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'], 'employee_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'employer_rate' => ['required', 'numeric', 'min:0', 'max:1'], 'ceiling' => ['nullable', 'numeric', 'min:0'],
            // Single rate: used by codes with no employee/employer split. The QPD
            // estimator refuses to compute without an approved corporate_tax rate.
            'rate' => ['nullable', 'numeric', 'min:0', 'max:1', 'required_if:code,corporate_tax'],
            'source_url' => ['required', 'url'], 'is_approved' => ['required', 'boolean'],
        ]);
        $data['currency'] = $data['currency'] ? strtoupper($data['currency']) : null;

        PayrollStatutoryRate::create($data + ['jurisdiction' => 'ZW', 'tenant_id' => $request->user()->tenant_id]);

        return back()->with('success', 'Statutory rate saved.');
    }

    public function destroyBand(Request $request, PayrollTaxBand $band): RedirectResponse
    {
        $this->assertOwned($request, $band->tenant_id);
        $band->delete();

        return back()->with('success', 'PAYE band deleted.');
    }

    public function destroyRate(Request $request, PayrollStatutoryRate $rate): RedirectResponse
    {
        $this->assertOwned($request, $rate->tenant_id);
        $rate->delete();

        return back()->with('success', 'Statutory rate deleted.');
    }

    /**
     * Bands within one table must tile the income range without overlapping —
     * PAYE sums across every band in the table, so an overlap silently
     * double-taxes the overlapping slice.
     */
    protected function assertBandDoesNotOverlap(?int $tenantId, array $data): void
    {
        $siblings = PayrollTaxBand::where('tenant_id', $tenantId)
            ->where('jurisdiction', 'ZW')
            ->where('currency', $data['currency'])
            ->where('frequency', $data['frequency'])
            ->whereDate('effective_from', $data['effective_from'])
            ->get();

        $lower = (float) $data['lower_bound'];
        $upper = $data['upper_bound'] === null ? INF : (float) $data['upper_bound'];

        foreach ($siblings as $sibling) {
            $siblingUpper = $sibling->upper_bound === null ? INF : (float) $sibling->upper_bound;
            if ($lower < $siblingUpper && (float) $sibling->lower_bound < $upper) {
                throw ValidationException::withMessages([
                    'lower_bound' => "This band overlaps an existing band ({$sibling->lower_bound}–".
                        ($sibling->upper_bound ?? '∞').") in the {$data['currency']} {$data['frequency']} table effective {$data['effective_from']}.",
                ]);
            }
        }
    }

    /** Shared national rules are read-only; a tenant may only remove its own overrides. */
    protected function assertOwned(Request $request, ?int $tenantId): void
    {
        abort_if($tenantId === null, 403, 'Shared national rules cannot be deleted.');
        abort_unless($tenantId === $request->user()->tenant_id, 403);
    }
}
