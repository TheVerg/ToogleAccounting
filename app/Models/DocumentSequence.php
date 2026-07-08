<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Per-company counters for document numbers (JE, INV, EXP, PAY).
 *
 * Numbers were previously derived from row counts, which races under
 * concurrency; a locked counter row hands out each number exactly once.
 */
class DocumentSequence extends Model
{
    protected $fillable = ['company_id', 'key', 'last_no'];

    /**
     * Reserve the next number in a company's series, e.g. "INV-00042".
     * Must be called inside a database transaction so the row lock is
     * held until the caller commits.
     *
     * On first use the counter is seeded from the highest number already
     * issued in $table.$column.
     */
    public static function nextNo(int $companyId, string $prefix, string $table, string $column): string
    {
        if (! DB::transactionLevel()) {
            throw new LogicException('DocumentSequence::nextNo must be called inside a transaction.');
        }

        $sequence = static::query()
            ->where('company_id', $companyId)
            ->where('key', $prefix)
            ->lockForUpdate()
            ->first();

        if (! $sequence) {
            $seed = (int) Str::afterLast(
                (string) DB::table($table)->where('company_id', $companyId)->max($column),
                '-',
            );

            static::insertOrIgnore([
                'company_id' => $companyId,
                'key' => $prefix,
                'last_no' => $seed,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = static::query()
                ->where('company_id', $companyId)
                ->where('key', $prefix)
                ->lockForUpdate()
                ->firstOrFail();
        }

        $sequence->last_no += 1;
        $sequence->save();

        return $prefix.'-'.str_pad((string) $sequence->last_no, 5, '0', STR_PAD_LEFT);
    }
}
