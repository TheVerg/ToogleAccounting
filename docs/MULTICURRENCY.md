# Multi-Currency Support (USD / ZWG)

Status: **built — Phases A–D implemented** (2026-07-24)
Priority: **build first** (fiscalisation depends on invoice currency; see [FISCALISATION.md](FISCALISATION.md))

## Implementation notes (what shipped)

- `exchange_rates` table + `ExchangeRateService` (carry-forward lookup, inverse-pair
  fallback, per-transaction override). UI: Settings → Exchange Rates.
- Auto-fetch: `fx:fetch-rates` (scheduled weekdays 10:30) pulls from ExchangeRate-API's
  open endpoint and cross-checks against an RBZ scraper (`RateFetchService`). Agreement
  within `FX_TOLERANCE` (default 2%) stores the RBZ mid as "auto (verified)"; on
  disagreement the RBZ rate (the figure ZIMRA cares about) is still stored but marked
  "auto (unverified)"; scraper down falls back to the aggregator rate (unverified).
  Manual rates are never overwritten. Every fetch attempt — stored or not — is recorded
  in `rate_fetch_logs` with what each source said; the Settings → Exchange Rates page
  shows a Current Rate card, a Rate Source card (latest fetch, both source values), and
  a Fetch Now button. RBZ has no official API — it publishes daily PDFs — hence the
  two-source design. Sources configurable in `config/services.php`
  (`services.exchange_rates`).
- `accounts.currency` on money accounts (and per-currency tax payable accounts);
  new money accounts can be created from the Chart of Accounts with a currency.
- Currency + `exchange_rate` + base-currency amounts on invoices, invoice payments,
  payments/transfers, expenses, equity transactions, shareholder loans + repayments,
  dividend payments, intercompany transfers. `journal_lines` carries `currency`,
  `fx_rate`, `currency_debit`, `currency_credit`; `debit`/`credit` stay base.
- Realised FX (Phase B) posts automatically on invoice settlement, shareholder loan
  repayment, and cross-currency transfers (account 5960). Invoice payments must be
  deposited into an account of the invoice's currency; loan repayments likewise.
- Per-currency tax (Phase C): `ChartOfAccountsService::taxAccountFor()` creates
  "Tax Payable — {CUR}" (codes 2140+) on first use; Tax by Currency report under
  Statements shows the declarable figure per currency of trade.
- Revaluation (Phase D): `FxRevaluationService` runs inside month-end close,
  restating foreign money-account balances and open foreign invoices at the closing
  rate against 5970 (unrealised), with an auto-reversing entry the next day so
  settlement accounting keeps measuring against original booked rates. Closing a
  month with open foreign balances requires a rate for the closing date.
- Dividends are declared in base currency; payments from foreign accounts settle
  the payable at the payment-date rate. Payroll and manual journals remain base-only.
- Backfill: all pre-existing data was stamped with the company currency at rate 1.

## Why

Zimbabwean businesses trade in both USD and ZiG (ZWG) — often in the same day. ZIMRA
expects tax to be accounted for **in the currency of trade** (VAT collected in ZWG is
declared and paid in ZWG; USD VAT in USD). A single currency per company cannot
represent real Zimbabwean books, and FDMS fiscalisation requires each invoice to
declare its currency. This is the foundational feature for selling in Zimbabwe.

## The accounting, in plain terms

1. **One currency stays "home base."** Financial statements cannot mix currencies.
   Every company keeps a base currency (`companies.currency`, usually USD) and the
   General Ledger's debits/credits stay in that base currency. That is what keeps the
   trial balance balancing.

2. **Transactions are recorded in the currency they happen in — with two amounts.**
   A sale invoiced in ZWG stores the ZWG amount (what the customer owes) *and* its
   USD equivalent at that day's rate (what enters the ledger).
   Example: invoice ZWG 27,000 at rate 27 ZWG/USD → ledger records $1,000 revenue;
   the invoice remembers "ZWG 27,000 @ 27.0".

3. **Rate movements create real gains/losses.** If that customer pays ZWG 27,000 a
   month later at rate 30, the money received is worth $900. Booked $1,000 receivable,
   collected $900 → the $100 difference is a **realised exchange loss**, posted to an
   "Exchange gains/losses" account. With ZWG volatility this is often a business's
   biggest hidden cost — surfacing it is a selling point.

4. **Tax lives in the currency of trade (the Zimbabwe twist).** Converting everything
   to USD is not enough — tax must be tracked per currency for ZIMRA returns, and
   FDMS forces per-invoice currency declaration anyway.

## Design

### Exchange rates
New `exchange_rates` table: `date`, currency pair, `rate` `decimal(18,6)`.
- Manually entered daily (RBZ publishes the interbank rate).
- Auto-carry-forward when no new rate entered; per-transaction override allowed
  (negotiated rates).
- Auto-fetch is a possible later enhancement; manual-with-carry-forward works offline.

### Currency on money accounts
Each cash/bank account gets a `currency` — "CBZ USD Account", "CBZ ZWG Account",
"EcoCash ZWG". Mirrors reality (banks hold FCA balances separately).
**Rule: transactions through an account must be in that account's currency.**
The cash book then naturally reads per currency.

### Currency on documents
Invoices, expenses, payments, dividends, shareholder loans, intercompany transfers:
add `currency` (char 3) + `exchange_rate` + amounts stored in **both** transaction
currency and base currency.

### Journal lines carry both
`journal_lines` keeps `debit`/`credit` in **base currency** (nothing about the
existing GL, trial balance, or statements breaks) and gains:
- `currency` (char 3)
- `fx_rate` (decimal 18,6)
- transaction-currency amount columns

Base amounts drive the statements; transaction-currency columns produce per-currency
views and the ZIMRA tax split.

### New system accounts (via `ChartOfAccountsService`)
- **Realised exchange gains/losses** (income/expense).
- Per-currency tax payable: "VAT Payable — USD" / "VAT Payable — ZWG" (preferred over
  one account split by column — makes the ZIMRA liability per currency visible on the
  face of the balance sheet).

### Settlement logic
When a payment settles an invoice, `AccountingService` compares the receivable's
booked base value with the payment's base value at the payment-date rate and posts
the difference to exchange gains/losses automatically. User just records
"customer paid ZWG 27,000".

### Period-end revaluation (last phase)
Open foreign-currency balances (bank accounts, unpaid invoices) gain/lose base-value
as rates move before settlement (**unrealised** differences). Home for this is the
existing **month-end close** flow: revalue open foreign-currency balances at the
closing rate. Deferred to last — proper accounting, but most SME value comes earlier.

## Phases

| Phase | Contents | Notes |
|-------|----------|-------|
| **A — Foundation** | `exchange_rates` table; currency on money accounts; currency + dual amounts on all transaction docs; base-currency posting | Biggest package; includes FDMS Package 0 (invoice currency, HS codes, ZIMRA tax mapping ride along) |
| **B — Realised FX** | Gain/loss posting on settlements | |
| **C — Per-currency tax** | Per-currency VAT payable accounts + VAT-by-currency report | The ZIMRA deliverable |
| **D — Revaluation** | Month-end revaluation of open FX balances (unrealised) | Hooks into existing month-end close |

## Warnings / notes

- Phase A touches **every module** (invoices, expenses, cash book, dividends,
  payroll?, opening balances). It is the largest single feature since the ledger
  itself. Do it **before** the client base grows — migrating thousands of posted
  single-currency journals later is far more expensive.
- Rates need high precision (`decimal(18,6)`); money amounts stay `decimal(18,2)`.
- Suggested starting point: exchange rates table + currency on money accounts —
  everything else hangs off those two.
