# ZIMRA Fiscalisation (FDMS Integration)

Status: **built — Packages 0–5 implemented** (2026-07-24); pending sandbox
validation against a real ZIMRA test device (see "Remaining human tasks" below)
Depends on: **multi-currency Phase A** (built) — see [MULTICURRENCY.md](MULTICURRENCY.md)

## Implementation notes (what shipped)

- **Package 0**: `invoice_items.hs_code` (auto-filled from `accounts.default_hs_code`),
  `tax_rates.zimra_tax_id`/`zimra_tax_code`, with form fields on Tax Rates and
  Chart of Accounts.
- **Package 1**: `fiscal_devices` (cert + Crypt-encrypted private key, chain
  counters, getConfig snapshot); Settings → Fiscalisation page (system admin):
  registration (RSA-2048 CSR, CN `ZIMRA-{serial}-{deviceId pad10}`), status
  cards, receipts log. `App\Services\Fdms\{FdmsClient, DeviceRegistrationService}`.
  API hosts: fdmsapitest/fdmsapi.zimra.co.zw; headers DeviceModelName +
  DeviceModelVersion (must match registration; config `services.fdms`).
- **Package 2**: `fiscal_days` + `fiscal_receipts` (append-only, exact payload
  stored); `FiscalisationService` reserves counters + hash chain under a device
  row lock, signs (SHA-256 + RSA PKCS#1 v1.5, `ReceiptSigner` — string formats
  cross-checked against two reference implementations and unit-tested with a
  known vector), submits; failed submissions are *replayed*, never rebuilt, so
  the chain survives outages. Queued via `FiscaliseInvoice` (6 retries) from
  `issueInvoice`; no-op with no active device. ⚠ Requires `php artisan queue:work`.
- **Package 3**: day auto-opens on first receipt; manual open/close on the
  Fiscalisation page; close computes Z-report counters from the day's receipts.
- **Package 4**: credit notes (`POST invoices/{id}/credit-note`): ledger reversal
  + CREDITNOTE receipt referencing the original; system-admin Omit blocked for
  fiscalised invoices.
- **Package 5**: invoice PDF carries the ZIMRA block (device ID, day/receipt
  numbers, verification code + QR via endroid/qr-code); Revenue page shows
  ZIMRA badges (pending/fiscalised/failed) + Retry; receipts log on settings.
- Invoices issued on account are reported with money type `Credit`; lines are
  tax-exclusive; invoices without VAT use the device's exempt tax ID.
- **Operational hardening (2026-08-05)**: `fdms:monitor` detects certificate expiry,
  stalled/failed/invalid receipts and a stalled database queue, with optional email;
  `fdms:auto-close` provides opt-in scheduled closure while retaining the service's
  pending/failed/invalid receipt gate. Both are scheduled in `routes/console.php`.
  The auto-close cutoff runs in `services.fdms.timezone`, not the app timezone —
  `APP_TIMEZONE` defaults to UTC, so an uncorrected 23:30 cutoff fired at 01:30
  Harare and closed the following fiscal day.
- **Alert acknowledgement (2026-08-09)**: `fdms:monitor` reports only *unacknowledged*
  failed/invalid receipts, so one unresolvable receipt no longer drowns the channel
  every five minutes. A system admin acknowledges from Settings → Fiscalisation with
  a mandatory reason (recorded on the receipt and in the audit log). Acknowledging
  never alters the receipt or the hash chain — a later successful retry clears the
  failure on its own. Long lists collapse to the first 10 plus a count.

## Remaining human tasks (before production)

1. Register a **test device** on the FDMS test portal and run the full flow
   against the sandbox — signature acceptance (RCPT020) is the key check.
2. Cross-check payloads against the official **Fiscal Device Gateway API v7.2**
   spec (the implementation was built from ZIMRA's swagger-generated reference
   code, not the PDF itself).
3. Set real ZIMRA tax IDs on tax rates (Master Data → Tax Rates) and HS codes
   on income categories.
4. Run a queue worker in production (`php artisan queue:work`).

## Why

All VAT-registered operators in Zimbabwe must fiscalise (SI 104 of 2010; ZIMRA also
cites Section 90 of the Income Tax Act more broadly). Every invoice must be reported
to ZIMRA's Fiscalisation Data Management System (FDMS) at the moment it is issued,
cryptographically signed, with a QR verification code printed on the invoice.

Pastel/QuickBooks do **not** do this natively — clients bolt on third-party
"fiscal connector" middleware (Fiscal Harmony, Fiscal Cloud, Fiscal Shift, OyOsCloud…)
at an extra monthly USD subscription. Building FDMS in natively is our differentiator:
invoice fiscalised at issue, QR on the PDF automatically, ledger and fiscal record in
one system, one bill.

## How FDMS works, in plain terms

- **Virtual Fiscal Device (VFD):** the software itself is the "fiscal device", talking
  to ZIMRA's REST API directly ("Direct interface of taxpayer Accounting/POS system
  to ZIMRA Server" — explicitly allowed).
- **Device registration (once per company/branch):** the taxpayer registers on ZIMRA's
  FDMS portal and receives a **Device ID + Activation Key** (the client's credentials,
  not ours — like a SIM issued in the client's name; one device per branch/POS).
  Our app generates a keypair locally, sends a CSR + Device ID + Activation Key,
  receives the **device certificate**. The activation key is one-time-use; the
  certificate + private key (never leaves our server) signs every receipt thereafter.
- **Fiscal days:** `openDay` before issuing; `closeDay` at day end with totals
  (sales by tax rate, tax by tax rate, balances by payment method — the "Z-report").
  Receipts can only be submitted while a day is open.
- **Hash chain:** every receipt embeds the SHA-256 hash of the previous receipt, then
  is signed with the device key (RSA-2048 PKCS#1 v1.5 + SHA-256, or ECDSA P-256).
  Deleting/altering an old receipt breaks the chain → corrections are **credit notes**
  (themselves fiscal receipts), never deletions.
- **Counters (app-managed — FDMS is stateful):**
  - `fiscalDayNo` — sequential, increments per day
  - `receiptCounter` — resets each fiscal day (1, 2, 3…)
  - `receiptGlobalNo` — monotonically increasing forever, never resets
- **QR code:** on successful submission ZIMRA returns a verification URL/QR that must
  be printed on the invoice.

## API surface (Fiscal Device Gateway API, v7.2 at time of writing)

REST/JSON. Required headers include `DeviceModelName`, `DeviceModelVersionNo`.
Methods: `verifyTaxpayerInformation`, `registerDevice`, `issueCertificate` /
`renewCertificate`, `getConfig`, `getStatus`, `ping`, `openDay`, `submitReceipt`,
`closeDay` (async), `submitFile` (async, offline batch).

Environments:
- **Test:** `https://fdmstest.zimra.co.zw` — test device registration does **not**
  require a real TIN (self-service add-device page on the FDMS ops portal).
- **Production:** per-client Device ID + Activation Key from ZIMRA.

Receipt essentials: `receiptType` (FISCALINVOICE | CREDITNOTE | DEBITNOTE),
`receiptCurrency` (USD | ZWG), counters, `invoiceNo`, ISO-8601 date, `receiptLines`
(name, HS code, qty, unit price, tax), `receiptPayments` (must sum to total),
`previousReceiptHash`, device signature; response carries `serverSignature` + QR URL.

2026 reference tax IDs (⚠ verify against current spec before coding):
standard VAT 15.5% = 515, withholding 5% = 514, zero-rated = 2, exempt = 3.

## ZIMRA-side checklist (human tasks, not code)

1. Download the official **Fiscal Device Gateway API v7.2** spec from zimra.co.zw —
   build against the real document; signature string format must match byte-for-byte.
2. Register a **test device** (Device ID + Activation Key) on the FDMS test portal.
3. Confirm with ZIMRA whether third-party software vendors need formal
   approval/listing (public guidance is thin; being listed would be a selling point).
4. Per production client (later): client registers for fiscalisation with ZIMRA,
   receives their own Device ID + Activation Key, enters them in our settings page.

## Build packages

### Package 0 — schema groundwork *(folded into multi-currency Phase A)*
- `invoices.currency` (USD/ZWG) + selector in create modal.
- `invoice_items.hs_code` + `accounts.default_hs_code` (auto-fill from income
  category so users rarely type codes; spec has a fallback default).
- `tax_rates.zimra_tax_id` + `zimra_tax_code`.

### Package 1 — device identity
- `fiscal_devices` table: `company_id`, `device_id`, `serial_no`, certificate,
  **encrypted** private key, model name/version, environment (test/production),
  certificate expiry, status. One row per company (per branch later).
- Fiscalisation settings page — **system-admin only** (consistent with Opening
  Balances / Omit gating): enter Device ID + Activation Key → generate keypair + CSR
  (PHP OpenSSL) → `registerDevice` → store certificate → `getConfig` → display
  taxpayer name/branch/taxes as confirmation.
- FDMS base URLs in `config/services.php`.

### Package 2 — receipt engine (the heart)
- `fiscal_days` table: day number, status, opened/closed timestamps, closing counters.
- `fiscal_receipts` table: invoice id, receipt counter, global no, submitted JSON
  payload, hash, our signature, ZIMRA `serverSignature`, QR/verification URL, status.
  (Audit trail if ZIMRA disputes a submission.)
- `FiscalisationService` beside `AccountingService`: build receipt JSON from invoice
  lines/tax/payments → hash chained to previous receipt → sign with device key →
  submit → store QR URL.
- Counters via the locked-row pattern already used by `DocumentSequence`.
- Hooked at the end of `AccountingService::issueInvoice()` as a **queued job with
  retries** — offline/ZIMRA-down invoices queue and submit when connectivity returns.
  ⚠ Operational requirement: a queue worker (`php artisan queue:work`, database
  driver already configured).

### Package 3 — fiscal day management
- Open/close day surfaced as a tab (mirrors month-end close UX). Close computes the
  day's totals from `fiscal_receipts` and submits the Z-report. Auto-open on first
  invoice of the day; manual close.

### Package 4 — credit notes
- Credit-note flow for fiscalised invoices: reverse ledger via existing
  `JournalService::reverse()` **and** submit a CREDITNOTE receipt referencing the
  original's device ID, fiscal day, and global number.
- Block system-admin **Omit** for fiscalised invoices → point to credit notes.

### Package 5 — visible compliance
- `resources/views/exports/invoice.blade.php`: QR code (server-side render — add a QR
  package), verification code, device ID, fiscal receipt number.
- Revenue page: fiscal status badge (pending / fiscalised / failed) + retry action.
- Submission log screen for troubleshooting.

## Order & effort

0 → 1 → 2 are sequential; 3, 4, 5 can land in any order after.
Package 0 is day-scale; 1 and 2 are the meat (several days each incl. sandbox
testing); 3–5 are smaller.

## Reference implementations (sanity-check our reading of the spec)

- Python FDMS library: https://github.com/lordskyzw/zimra-public
- Manager.io ↔ FDMS integration: https://github.com/mabaega/ZimraEGS

## Sources

- ZIMRA — Fiscalisation Explained: https://www.zimra.co.zw/domestic-taxes/corporate/fiscalisation-explained
- ZIMRA — FDMS compliance notice: https://www.zimra.co.zw/news/2307:compliance-with-the-zimra-fiscalisation-data-management-system-fdms
- ZIMRA Public Notice 50 of 2023 on FDMS
- Fiscal Device Gateway API v7.2 — Client Specifications (download from ZIMRA)
