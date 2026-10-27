<?php

namespace App\Services\Compliance;

use App\Models\Company;
use App\Models\ComplianceReturn;

/**
 * One return type's working papers.
 *
 * The compliance module used to compute VAT for every return type, so opening a
 * QPD or PAYE return produced VAT schedules, gated preparation on VAT
 * exceptions, locked the period and archived a VAT evidence pack under the wrong
 * return's name. Each type now owns its own figures, its own blocking
 * exceptions, its own decision about locking the period, and its own evidence
 * CSV. See docs/COMPLIANCE.md.
 *
 * `build()` returns a self-describing snapshot so the UI and the evidence pack
 * stay type-agnostic:
 *
 *   type        string  the return type key
 *   label       string  heading for the working-papers tab
 *   generated_at, period
 *   columns     [{key, label, align, format}]  format: text|number|money
 *   rows        [ {<column key> => value} ]    money formats use the row's currency
 *   summary     [{label, value, format, tone}] tone: neutral|good|bad
 *   exceptions  [{type, severity, reference, message, amount, currency}]
 */
interface ReturnWorkspace
{
    /** The return type code, e.g. 'VAT7'. */
    public function key(): string;

    /** Heading for the working-papers tab. */
    public function label(): string;

    public function build(Company $company, string $from, string $to, ?ComplianceReturn $return = null): array;

    /**
     * Whether approving this return should freeze the books up to period_to.
     * Only one return type per period should do this — two locking returns for
     * the same month means whichever is approved first blocks the other's
     * ability to correct anything.
     */
    public function locksPeriodOnApproval(): bool;

    /**
     * Whether the filing deadline falls after the period it reports on.
     *
     * True for returns that report a closed period (VAT7, PAYE-P2). False for
     * payments on account: a QPD's first instalment falls due 25 March against
     * a year of assessment ending 31 December, so requiring
     * `due_date >= period_to` would make it unrepresentable.
     */
    public function dueDateFollowsPeriod(): bool;

    /**
     * How many separately-filed instalments one period carries. 1 for an
     * ordinary return; 4 for QPDs, which are numbered 1–4 within the year.
     */
    public function instalments(): int;

    /** @return array{name: string, headers: array<string>, rows: array<array>} */
    public function evidenceCsv(array $snapshot): array;
}
