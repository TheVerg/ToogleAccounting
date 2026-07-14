<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\JournalEntry;
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
        // An invoice's recorded payments post their own journal entries;
        // omitting the invoice must take those out of the ledger too.
        $sources = $source instanceof Invoice
            ? collect([$source])->merge($source->payments)
            : collect([$source]);

        $entryIds = JournalEntry::where(function ($query) use ($sources) {
            foreach ($sources as $record) {
                $query->orWhere(fn ($q) => $q
                    ->where('source_type', $record->getMorphClass())
                    ->where('source_id', $record->getKey()));
            }
        })->pluck('id');

        $this->purge($source, $entryIds, $reason, $source->company_id, [
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
        ]);
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
