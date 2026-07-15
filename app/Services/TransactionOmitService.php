<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Dividend;
use App\Models\DividendAllocation;
use App\Models\DividendPayment;
use App\Models\EquityTransaction;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Shareholder;
use App\Models\ShareholderLoan;
use App\Models\ShareholderLoanRepayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Permanently removes a transaction from its source area and the
 * journal (system admins only, audit logged). Callable either from a
 * journal entry or from the source document itself.
 */
class TransactionOmitService
{
    public function omitEntry(JournalEntry $entry, string $reason): void
    {
        $original = $entry->reversal_of_id ? JournalEntry::findOrFail($entry->reversal_of_id) : $entry;

        $this->purge($original->source, collect([$original->id]), $reason, $original->company_id, [
            'source_type' => $original->source_type,
            'source_id' => $original->source_id,
        ]);
    }

    public function omitSource(Model $source, string $reason): void
    {
        // Documents whose child records post their own journal entries
        // (invoice payments, loan repayments) must take those out of the
        // ledger too.
        $sources = match (true) {
            $source instanceof Invoice => collect([$source])->merge($source->payments),
            $source instanceof ShareholderLoan => collect([$source])->merge($source->repayments),
            $source instanceof Dividend => collect([$source])->merge($source->payments),
            default => collect([$source]),
        };

        $entryIds = JournalEntry::where(function ($query) use ($sources) {
            foreach ($sources as $record) {
                $query->orWhere(fn ($q) => $q
                    ->where('source_type', $record->getMorphClass())
                    ->where('source_id', $record->getKey()));
            }
        })->pluck('id');

        DB::transaction(function () use ($source, $entryIds, $reason) {
            // Omitting a share issue takes the shares back off the
            // shareholder's register entry (unless a reversal already did).
            if ($source instanceof EquityTransaction && $source->type === 'share_issue'
                && $source->shareholder_id && $source->shares_count && ! $source->reversed_at) {
                $shareholder = Shareholder::whereKey($source->shareholder_id)->lockForUpdate()->first();
                $shareholder?->update([
                    'shares_held' => max(round((float) $shareholder->shares_held - $source->shares_count, 2), 0),
                ]);
            }

            // Omitting one dividend payment puts the amount back on the
            // shareholder's unpaid allocation.
            if ($source instanceof DividendPayment && ! $source->reversed_at) {
                $allocation = DividendAllocation::whereKey($source->dividend_allocation_id)->lockForUpdate()->first();
                if ($allocation) {
                    $allocation->update([
                        'paid_amount' => max(round((float) $allocation->paid_amount - (float) $source->amount, 2), 0),
                    ]);
                    $allocation->dividend->refreshStatus();
                }
            }

            // Omitting a single repayment puts the money back on the loan.
            if ($source instanceof ShareholderLoanRepayment) {
                $loan = ShareholderLoan::whereKey($source->shareholder_loan_id)->lockForUpdate()->first();
                if ($loan) {
                    $repaid = max(round((float) $loan->total_repaid - (float) $source->amount, 2), 0);
                    $loan->update([
                        'total_repaid' => $repaid,
                        'outstanding_balance' => round((float) $loan->principal - $repaid, 2),
                        'status' => $loan->status === 'repaid' ? 'active' : $loan->status,
                    ]);
                }
            }

            $this->purge($source, $entryIds, $reason, $source->company_id, [
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->getKey(),
            ]);
        });
    }

    protected function purge(?Model $source, Collection $originalIds, string $reason, int $companyId, array $context): void
    {
        DB::transaction(function () use ($source, $originalIds, $reason, $companyId, $context) {
            $entries = JournalEntry::whereIn('id', $originalIds)
                ->orWhereIn('reversal_of_id', $originalIds)->get();

            AuditLog::record('transaction_omitted', $source ?? $entries->first(), array_merge([
                'reason' => $reason,
                'journal_entries' => $entries->pluck('entry_no')->all(),
            ], $context), $companyId);

            if ($source) {
                $source->delete();
            }

            Attachment::whereIn('attachable_id', $entries->pluck('id'))
                ->where('attachable_type', (new JournalEntry)->getMorphClass())->delete();
            DB::table('journal_entries')->whereIn('id', $entries->pluck('id'))->delete();
        });
    }
}
