<?php

namespace App\Services;

use App\Models\Company;
use App\Models\DocumentSequence;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class JournalService
{
    /**
     * Post a balanced journal entry.
     *
     * @param  array  $lines  Each line: ['account_id' => int, 'debit' => float, 'credit' => float, 'memo' => ?string]
     */
    public function post(
        Company $company,
        string $date,
        string $description,
        array $lines,
        ?Model $source = null,
        ?string $reference = null,
        ?int $userId = null,
        ?JournalEntry $reversalOf = null,
    ): JournalEntry {
        $this->assertBalanced($lines);

        if ($company->isDateLocked($date)) {
            throw new InvalidArgumentException(
                "Cannot post to {$date}: periods before "
                .$company->locked_before->toDateString().' are locked.'
            );
        }

        return DB::transaction(function () use ($company, $date, $description, $lines, $source, $reference, $userId, $reversalOf) {
            $entry = JournalEntry::create([
                'company_id' => $company->id,
                'entry_no' => $this->nextEntryNo($company),
                'date' => $date,
                'description' => $description,
                'reference' => $reference,
                'status' => 'posted',
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'reversal_of_id' => $reversalOf?->id,
                'created_by' => $userId,
                'posted_at' => now(),
            ]);

            foreach ($lines as $line) {
                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'debit' => round((float) ($line['debit'] ?? 0), 2),
                    'credit' => round((float) ($line['credit'] ?? 0), 2),
                    'memo' => $line['memo'] ?? null,
                ]);
            }

            return $entry;
        });
    }

    /**
     * Posted entries are immutable: corrections are made by posting
     * an equal-and-opposite reversing entry.
     */
    public function reverse(JournalEntry $entry, ?string $date = null, ?int $userId = null): JournalEntry
    {
        if (! $entry->isPosted()) {
            throw new InvalidArgumentException('Only posted entries can be reversed.');
        }

        return DB::transaction(function () use ($entry, $date, $userId) {
            $lines = $entry->lines->map(fn ($l) => [
                'account_id' => $l->account_id,
                'debit' => (float) $l->credit,
                'credit' => (float) $l->debit,
                'memo' => $l->memo,
            ])->all();

            $reversal = $this->post(
                $entry->company,
                $date ?? now()->toDateString(),
                'Reversal of '.$entry->entry_no.': '.$entry->description,
                $lines,
                null,
                $entry->reference,
                $userId,
                reversalOf: $entry,
            );

            $entry->update(['status' => 'reversed']);

            return $reversal;
        });
    }

    protected function assertBalanced(array $lines): void
    {
        if (count($lines) < 2) {
            throw new InvalidArgumentException('A journal entry needs at least two lines.');
        }

        $debits = round(array_sum(array_map(fn ($l) => (float) ($l['debit'] ?? 0), $lines)), 2);
        $credits = round(array_sum(array_map(fn ($l) => (float) ($l['credit'] ?? 0), $lines)), 2);

        if ($debits <= 0 || abs($debits - $credits) >= 0.005) {
            throw new InvalidArgumentException(
                "Journal entry is not balanced (debits {$debits} vs credits {$credits})."
            );
        }

        foreach ($lines as $line) {
            $d = (float) ($line['debit'] ?? 0);
            $c = (float) ($line['credit'] ?? 0);
            if ($d < 0 || $c < 0) {
                throw new InvalidArgumentException('Debit and credit amounts must be non-negative.');
            }
            if ($d > 0 && $c > 0) {
                throw new InvalidArgumentException('A line cannot have both a debit and a credit.');
            }
        }
    }

    protected function nextEntryNo(Company $company): string
    {
        return DocumentSequence::nextNo($company->id, 'JE', 'journal_entries', 'entry_no');
    }
}
