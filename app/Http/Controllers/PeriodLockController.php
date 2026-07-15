<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Month-end close and reopen: moves the company's period lock forward
 * (closing months into ledger history) or backward (reopening after a
 * mistake). The ledger data itself is never touched — closing a month
 * only blocks further posting into it. System admins only, audit logged
 * (the Company model's Auditable trait also records the change itself).
 */
class PeriodLockController extends Controller
{
    public function close(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isSystemAdmin(), 403);
        $company = $user->currentCompany;

        $data = $request->validate(['through_month' => ['required', 'date_format:Y-m']]);

        $newLock = Carbon::parse($data['through_month'].'-01')->addMonth()->startOfDay();
        if ($newLock->gt(now()->startOfMonth()->addMonth())) {
            return back()->withErrors(['through_month' => 'You cannot close a month that has not started yet.']);
        }
        if ($company->locked_before !== null && $newLock->lte($company->locked_before)) {
            return back()->withErrors(['through_month' => 'That month is already closed. Use reopen to move the lock back.']);
        }

        $company->update(['locked_before' => $newLock->toDateString()]);
        AuditLog::record('period_closed', $company, [
            'through_month' => $data['through_month'],
            'locked_before' => $newLock->toDateString(),
        ], $company->id);

        return back()->with('success', 'Months through '.Carbon::parse($data['through_month'].'-01')->format('F Y')
            .' are now closed. No further entries can be posted into them.');
    }

    public function reopen(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isSystemAdmin(), 403);
        $company = $user->currentCompany;

        $data = $request->validate(['from_month' => ['required', 'date_format:Y-m']]);

        $newLock = Carbon::parse($data['from_month'].'-01')->startOfDay();
        if ($company->locked_before === null || $newLock->gte($company->locked_before)) {
            return back()->withErrors(['from_month' => 'That month is not closed.']);
        }

        $company->update(['locked_before' => $newLock->toDateString()]);
        AuditLog::record('period_reopened', $company, [
            'from_month' => $data['from_month'],
            'locked_before' => $newLock->toDateString(),
        ], $company->id);

        return back()->with('success', Carbon::parse($data['from_month'].'-01')->format('F Y')
            .' and later months are open again for posting.');
    }
}
