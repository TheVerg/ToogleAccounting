# Compliance Workspace (VAT7 / PAYE-P2 / QPD / calendar / TaRMS filing)

Status: **VAT7, PAYE-P2 and QPD returns working; ITF12B/C, NSSA and ZIMDEF are calendar-tracked only**

The module has two halves, and they are deliberately not the same thing:

| Half | What it does | Which obligations |
|------|--------------|-------------------|
| **Return workspace** | Working papers, maker–checker sign-off, blocking exceptions, optional period lock on approval, evidence-pack ZIP | Only types registered in `ComplianceReturnTypeRegistry` |
| **Calendar** | Due date, owner, status. No computation. | Everything in `ComplianceDeadline::TYPES` |

## The registry

`ComplianceReturnTypeRegistry` is the single source of truth for which types can
be opened as a return. Each implements `ReturnWorkspace`:

- `build()` returns a **self-describing snapshot** — `columns`, `rows`, `summary`,
  `exceptions` — so the Vue page and the evidence pack stay type-agnostic. Adding
  a type requires no UI change.
- `locksPeriodOnApproval()` is per type. **Only VAT7 returns true.** Two locking
  returns for one period would mean whichever is approved first freezes the
  other's ability to correct anything — and a QPD must never freeze the books it
  is estimated from.
- `dueDateFollowsPeriod()` decides whether `due_date >= period_to` is enforced.
  False for payments on account.
- `instalments()` is how many separately-filed instalments one period carries —
  1 normally, 4 for QPDs.
- `evidenceCsv()` supplies that type's working-papers CSV, keyed explicitly so
  column order never depends on key order in the stored JSON.

Snapshots stored before the registry existed carry only `schedules` /
`reconciliation`; `Vat7Workspace` keeps emitting both, and the Vue falls back to
them, so old prepared and filed returns still render and still export.

**Period boundaries are inclusive and must be written as
`[$from.' 00:00:00', $to.' 23:59:59']`.** SQLite returns date columns as
`Y-m-d 00:00:00`, so a bare `Y-m-d` upper bound silently drops everything dated
on the last day of the period. MariaDB truncates to a real `DATE` and is
unaffected — production was never wrong — but the two drivers must not disagree,
and the test suite runs on SQLite. The range form is used rather than
`whereDate()` so the index stays usable. Regression test:
`test_documents_dated_on_the_last_day_of_the_period_are_included`.

## Why the split (2026-08-09)

`ComplianceWorkspaceService::build()` computes **VAT only** — standard/zero/exempt
sales, output VAT, input VAT, net VAT. It ran unconditionally for every return
type, so opening a QPD, ITF12C or PAYE-P2 return produced:

- VAT schedules presented as that return's working papers,
- preparation gated on *VAT* exceptions (unfiscalised invoices, input VAT without
  evidence) that say nothing about the obligation being filed,
- a **period lock** (`companies.locked_before`) taken on approval, and
- an evidence-pack ZIP containing a VAT CSV under the wrong return's name.

Every signal in the UI said "this return is complete". That is a false positive,
not a gap — worse than the type not existing. So return creation is restricted to
types that have a real builder, and the obligations that don't are tracked on the
calendar, which needs no computation and is honest as-is.

Returns created before a type was withdrawn stay readable but cannot be prepared,
approved or filed (`ComplianceReturn::hasWorkspace()`), so no new period locks or
evidence packs can be taken on VAT workings.

**The calendar can express shapes the return workspace cannot.** A QPD is a
payment on account of the *annual* liability: the first instalment falls due
25 March against a tax year ending 31 December. The return workspace validates
`due_date >= period_to`, which rejects that outright; `storeDeadline` does not,
so the calendar models it correctly today.

## Adding a return type

Implement `ReturnWorkspace` and register it in `ComplianceReturnTypeRegistry`.
Registering is a statement that the type has all four of:

1. its own working-paper builder (not another type's),
2. its own exception set for the blocking gate,
3. a considered answer on whether approval should lock the period, and
4. its own evidence CSV.

Do not register a type to make a dropdown longer.

**PAYE-P2** (`PayeP2Workspace`) is built from approved payroll runs whose
**pay date** falls in the period — the remittance follows the money leaving the
business, not the period worked. It blocks preparation on unapproved runs,
approved runs with no journal entry, and any run carrying statutory
`compliance_warnings`; it warns on employees with PAYE but no tax number, and
NSSA-registered employees with no NSSA number.

## TaRMS

There is **no TaRMS integration**. Filing happens on ZIMRA's portal; the app
records the reference the user types back in (`compliance_returns.filing_reference`)
and, optionally, an uploaded acknowledgement. The reference is required to move a
return to `filed` but is otherwise unvalidated — no format check, no uniqueness.
Since approval locks the period and filing closes the deadline, treat it as an
assertion by the preparer, not as proof.

The acknowledgement upload is stored **inside** the status transaction
(2026-08-09) so a failed upload cannot leave a return marked filed with no
evidence behind it.

Open question before "direct submission" stays on the roadmap: whether ZIMRA
exposes a third-party filing API for TaRMS at all. FDMS has a documented device
API; TaRMS is a taxpayer portal. This needs establishing — it may be a
can't-build, not a haven't-built.

## QPD (`QpdWorkspace`)

A QPD is a payment on account of the **annual** liability, so the period is the
year of assessment and each of the four instalments is filed separately
(`compliance_returns.instalment`, 1–4). Instalment 1 falls due 25 March against a
year ending 31 December, which is why `dueDateFollowsPeriod()` is false.

**The corporate income tax rate is data, not code.** It lives in
`payroll_statutory_rates` under code `corporate_tax`, in the `rate` column (added
for codes with no employee/employer split), and is subject to the same
effective-dating and approval gate as PAYE bands — see [PAYROLL.md](PAYROLL.md).
With no approved rate in force on the due date, the estimator raises a
**blocking** exception and computes nothing. Nothing is seeded; a human must
enter and approve a verified rate under Settings → Payroll Rules.

**The estimate.** Taxable profit for a year that has not finished is annualised
from actuals to date: profit for period start → due date, divided by whole
calendar months elapsed, times months in the period. Use whole calendar months —
Carbon's `diffInMonths` returns a fraction, which annualises from 3.77 months
instead of 3 and understates every instalment. Every input appears on the working
paper (actual profit to date, estimated annual profit, rate, instalment due), and
the snapshot raises warnings for the annualisation and for a nil/loss estimate.

**The schedule** — 10% by 25 Mar, 25% by 25 Jun, 30% by 25 Sep, 35% by 20 Dec —
is a documented default in `QpdWorkspace::SCHEDULE`, **not validated production
data**; the snapshot says so with a standing `qpd_schedule_unverified` warning.
Override per taxpayer with an `instalments` array on the `corporate_tax` rate's
`metadata`.

Note the taxonomy: **ITF12B** is the provisional (QPD) return; **ITF12C** is the
annual income tax return. They are not interchangeable, and neither has a
workspace — both are calendar-tracked.

## Key files

`app/Services/Compliance/{ReturnWorkspace,ComplianceReturnTypeRegistry,Vat7Workspace,PayeP2Workspace,QpdWorkspace,ComplianceWorkspaceService}.php`,
`app/Http/Controllers/{Compliance,ComplianceEvidencePack,AccountantCompliance}Controller.php`,
`app/Models/Compliance{Return,Deadline,Adjustment,ReturnEvent,Task}.php`,
`resources/js/Pages/Compliance/{Index,Accountant}.vue`,
`database/migrations/2026_08_03_15*_create_compliance_*.php`.
