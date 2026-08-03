# Product Enhancements Roadmap

## Product Direction

Build a compliance-first accounting platform for Zimbabwean and regional SMEs,
rather than a generic accounting package. The core differentiator should be the
combination of accounting, ZIMRA fiscalisation, tax preparation, reconciliation,
local payments, and accountant collaboration.

Suggested positioning:

> Accounting, fiscalisation, tax preparation, and audit evidence for Zimbabwean
> SMEs and their accountants.

## Priority 1: Production-Safe ZIMRA Fiscalisation

- Validate the complete workflow against a real ZIMRA FDMS test device.
- Verify every request, header, signature, counter, and response against the
  current official Fiscal Device Gateway specification.
- Treat FDMS validation errors as failed submissions, not successful receipts.
- Enforce strict per-device submission ordering so failed receipts cannot break
  the receipt hash chain.
- Prevent fiscal-day closure while receipts are pending, failed, or unconfirmed.
- Reconcile local counters and receipt status with FDMS after interruptions.
- Capture complete buyer information required for valid input-tax claims.
- Add certificate-expiry alerts and certificate-renewal workflows.
- Add automatic fiscal-day closure with configurable cutoff rules.
- Monitor the queue worker and alert administrators when processing stops.
- Provide a searchable FDMS error log with actionable recovery instructions.
- Obtain confirmation from ZIMRA about vendor approval or certification needs.

## Priority 2: Tax Return Workspace

### VAT

- Prepare VAT7 working papers from ledger and FDMS data.
- Keep USD and ZWG declarations separate where required.
- Reconcile output VAT to issued invoices and accepted FDMS receipts.
- Validate supplier fiscal invoices before including input VAT.
- Support invoice selection, apportionment, and deferred input-tax claims.
- Cover imported goods, capital goods, imported services, VAT withholding,
  credit notes, debit notes, and manual adjustments.
- Track tax credits brought forward and payments made.
- Produce reviewable filing packs and supporting schedules.
- Store submission acknowledgements and payment references.

### Other Returns

- Produce PAYE P2 schedules from payroll.
- Add effective-dated PAYE, AIDS levy, NSSA, NEC, and ZIMDEF rules.
- Add a QPD estimator and ITF12B working papers.
- Add ITF12C annual corporate-income-tax working papers.
- Support withholding-tax schedules and returns.
- Add a compliance calendar with current deadlines and reminders.
- Use maker-checker review and approval before a return is finalized.

Automatic TaRMS submission should only be offered when ZIMRA provides and
authorizes an appropriate integration. Until then, the system should generate
complete, reviewable filing packs.

## Priority 3: Tax Reconciliation Engine

Build an exception-driven reconciliation workspace that compares:

- Ledger sales against issued invoices.
- Issued invoices against FDMS-accepted receipts.
- Output VAT against VAT-return working papers.
- Purchases against valid supplier fiscal invoices.
- Credit notes against their original fiscal receipts.
- Payroll deductions against PAYE and statutory schedules.
- Tax liabilities against payments in the bank and cash books.
- Exchange rates used in transactions against approved rates.

Each exception should show the source records, financial difference, compliance
risk, responsible person, and required correction.

## Priority 4: Accountant and Client Workspace

- Let accounting firms manage multiple client tenants from one workspace.
- Provide role-based access for clients, accountants, reviewers, auditors, and
  tax consultants.
- Allow accountants to request missing documents or explanations from clients.
- Track outstanding queries, responses, evidence, and resolution status.
- Add period-end completion checklists and assigned responsibilities.
- Add maker-checker approval for journals, fiscal corrections, and tax returns.
- Provide a client-facing compliance status and upcoming-deadlines dashboard.
- Preserve a complete history of review comments and approvals.

## Priority 5: Bank and Local Payment Reconciliation

- Import bank statements through stable CSV templates first.
- Add adapters for major local banks as reliable formats become available.
- Support EcoCash, InnBucks, ZIPIT, card settlements, and other local payment
  channels.
- Create reusable matching rules for customers, suppliers, references, and fees.
- Support one-to-one, one-to-many, and many-to-one matching.
- Detect duplicate imports and duplicate transactions.
- Separate settlement fees, withholding amounts, and net deposits.
- Maintain an unidentified-receipts queue with assignment and resolution.

## Priority 6: Low-Connectivity Reliability

- Allow normal bookkeeping and invoice preparation during connectivity problems.
- Use a durable, visible fiscalisation queue with safe automatic retries.
- Preserve FDMS receipt ordering and hash-chain integrity through outages.
- Clearly distinguish pending, rejected, and accepted fiscal documents.
- Reconcile all queued activity after connectivity returns.
- Provide operational alerts for long-running pending submissions.
- Define legally reviewed rules for printing or sharing documents while an FDMS
  response is pending.

## Additional Differentiators

### Compliance Health Score

Show missing tax IDs, invalid buyer details, unfiscalised invoices, expired
certificates, overdue returns, unclosed fiscal days, unreconciled tax balances,
and unresolved compliance queries.

### Audit Evidence Packs

Bundle invoices, fiscal responses, receipts, supporting documents, approvals,
exchange rates, journals, reconciliations, and return schedules by reporting
period. Allow controlled export for auditors and tax reviews.

### Tamper-Evident Audit Trail

Record who changed a record, what changed, the previous value, the new value,
the time, the reason, and the related approval. Prevent destructive deletion of
financial and compliance records.

### Industry Packs

Provide optional charts of accounts, workflows, reports, and compliance settings
for professional services, retail, construction, NGOs, schools, agriculture, and
other target industries.

### Intercompany Automation

- Generate balanced mirrored entries between group companies.
- Reconcile intercompany balances.
- Track confirmations and disputes.
- Produce elimination journals and consolidated reporting working papers.

### Cash-Flow and Collections

- Add aged receivables and collection workflows.
- Track promised payment dates and follow-ups.
- Produce short-term cash forecasts from receivables, payables, payroll, and tax
  obligations.
- Provide payment schedules and overdue-risk reporting.

### Customer and Supplier Portals

- Let customers access invoices, receipts, statements, and payment instructions.
- Let suppliers submit invoices and supporting documents.
- Track document validation, disputes, approvals, and payment status.

### Controlled Regulatory Updates

Store tax rates, payroll bands, thresholds, deadlines, and calculation rules with
effective dates, sources, version history, approval, test coverage, and rollback.

## International Expansion

Keep the accounting core jurisdiction-neutral and implement compliance through
country adapters.

- Add company country code, tax jurisdiction, timezone, locale, legal form, and
  tax residency.
- Support multiple tax registrations per company.
- Enable ZIMRA FDMS only for eligible Zimbabwean companies.
- Move Zimbabwe-specific terminology and rules into a Zimbabwe compliance module.
- Add country-specific invoice requirements, tax returns, payroll rules, and
  filing packs through separate adapters.
- Expand one jurisdiction at a time after legal and accounting validation.

Currency support alone must not be presented as full support for a jurisdiction.

## Features to Defer

Avoid weakening the initial product by building unrelated broad features too
early. Defer:

- Generic CRM functionality.
- Full manufacturing and advanced inventory.
- General project-management tools.
- E-commerce website builders.
- Complex enterprise consolidation beyond the target customer need.
- Many shallow country integrations.
- Automatic tax filing without an authorized and tested filing interface.

## Recommended Delivery Sequence

1. Make the FDMS state machine production-safe.
2. Complete real-device ZIMRA sandbox validation.
3. Build VAT7 working papers and FDMS-to-ledger reconciliation.
4. Add accountant multi-client workflows and period-end checklists.
5. Add local bank and mobile-payment imports and reconciliation.
6. Complete localized payroll compliance and statutory schedules.
7. Add audit evidence packs and the compliance health dashboard.
8. Introduce the jurisdiction adapter architecture.
9. Pilot the platform with three to five accounting firms and a small number of
   VAT-registered businesses.
10. Broaden distribution only after fiscalisation, backup restoration, support,
    and compliance operations have been proven in the pilot.

## Minimum Launch Criteria

Before marketing the product as ZIMRA-compliant:

- A real ZIMRA test device accepts registration, open day, invoice, credit note,
  retry, and close day operations.
- FDMS validation errors cannot be displayed as successful fiscalisation.
- Concurrent invoices cannot break counter order or the receipt chain.
- Failed and pending receipts prevent unsafe day closure.
- Queue workers, certificate expiry, failed jobs, and delayed receipts are
  monitored.
- The generated fiscal invoice has been reviewed against current ZIMRA rules.
- A Zimbabwean tax practitioner has reviewed the VAT and payroll calculations.
- Backup and disaster-recovery procedures have been tested.
- Support staff have documented recovery procedures for common FDMS failures.

