# Payroll Statutory Engine (PAYE / NSSA / QPDs)

Status: **planned — not yet started**
Depends on: **multi-currency Phase A** (tax tables are currency-keyed; split-currency
salaries need the FX foundation) — see [MULTICURRENCY.md](MULTICURRENCY.md)
Sequence: multi-currency A → fiscalisation 1–2 ([FISCALISATION.md](FISCALISATION.md)) →
**payroll statutory engine** → remaining phases

## What already exists (the skeleton)

Payroll is partially built: `employees`, `salary_components` (recurring
earnings/deductions, fixed or %-of-basic), `payroll_runs` + `payroll_items` (frozen
payslip JSON lines), employee loans with auto repayment deductions, once-off
adjustments at approval, payslip PDFs, and proper ledger posting via
`PayrollService` (Dr Salaries & Wages gross; Cr Tax Payable / Pension Payable /
Payroll Deductions Payable / Employee Loans; Cr bank for net).

**What's missing is the statutory brain:** PAYE and pension today are just
hand-typed deduction lines. No tax tables, no NSSA rules, no AIDS levy, no employer
contributions, no currency handling anywhere in payroll.

Key files: `app/Services/PayrollService.php`, `app/Http/Controllers/PayrollController.php`,
`app/Models/{Employee,SalaryComponent,PayrollRun,PayrollItem,EmployeeLoan}.php`,
`resources/js/Pages/Payroll/{Index,Show}.vue`, `database/migrations/2026_07_08_*`.

## Zimbabwe statutory rules (2026 — always re-verify before seeding)

### PAYE — two parallel tax tables
- ZIMRA publishes **separate bands for USD and ZWG** salaries.
- 2026 monthly USD bands: first $100 tax-free → 20% → 25% → 40% above $3,000
  (equivalent ZWG bands for ZiG earners).
- **AIDS levy: 3% of the PAYE amount** (not of gross).
- Employer remits PAYE + AIDS levy to ZIMRA by the **10th of the following month**.
- Many employers **split salaries** (part USD, part ZWG) — tables apply
  proportionally. Support per-employee pay currency first; splits soon after.
- PAYE applies **regardless of NSSA status** — an unregistered worker above the
  threshold still owes PAYE.

### NSSA — two schemes
- **POBS (pension):** 4.5% employee + 4.5% employer, on insurable earnings up to a
  **ceiling (~US$700/month currently)**. Nothing above the ceiling.
- **APWCS (workers' comp):** **employer-only**, uncapped, industry risk-factor rate.
- Registration is legally the **employer's** obligation for virtually all employees
  (coverage extends to domestic and casual workers).

### QPDs — company income tax, not payroll
Provisional corporate income tax in four unequal instalments of estimated annual
liability: **10% by 25 Mar, 25% by 25 Jun, 30% by 25 Sep, 35% by 20 Dec**
(return ITF 12B due ~5 days before each payment).

### Smaller levies (later)
ZIMDEF manpower levy (~1% of wage bill), standards development levy, industry NEC
contributions — add once the big three work.

## The design pattern: three levels (not one big toggle)

| Level | Mechanism | Applies to |
|-------|-----------|-----------|
| **Per-employee toggle** | on/off for things that depend on *registration status* | NSSA (`nssa_registered` flag + NSSA number) |
| **Automatic — no toggle** | law ties it to *amounts*; tables produce zero below thresholds naturally | PAYE, AIDS levy |
| **Company-level setting** | company taxes / company registrations | QPDs ("subject to provisional tax?"), later ZIMDEF/NEC |

This mix keeps the software usable for a half-formal SME while doing the maths right
for whoever is in the system.

### Unregistered employees (the common case)
- `nssa_registered = false` → no NSSA lines; payroll, payslips, and PAYE still work.
  The system models reality — the legal duty and the choice stay with the employer.
- **Compliance view** (the commercial angle): "X of your N employees are
  unregistered; registering them costs ~$Y/month" — makes gradual formalisation easy
  instead of scary. Meets informal SMEs where they are and walks them toward
  compliance; the software makes the compliant path the easy one, never hides income.

## Build plan

1. **Effective-dated tax tables** — `tax_bands` table: currency, effective period,
   band thresholds, rates. Seed current ZIMRA tables; admin-editable because ZIMRA
   changes rates mid-year in Zimbabwe. **Never hard-code rates.** Same pattern for
   NSSA parameters (rate, ceiling) — `statutory_rates` style table.
2. **Employee statutory profile** — tax number, NSSA number, `nssa_registered`
   flag, pay currency (USD/ZWG; split support later).
3. **Auto-computed lines in `PayrollService::computeItem()`** — PAYE from the
   correct currency table, AIDS levy = 3% of PAYE, NSSA employee 4.5% capped at
   ceiling. These replace hand-typed PAYE/pension components (keep manual components
   for genuinely custom deductions).
4. **Employer-side costs** (don't exist today): NSSA employer match + APWCS post as
   **expense + liability** (company cost, not a deduction). New accounts via
   `ChartOfAccountsService` (e.g. "NSSA Payable", "Employer Payroll Costs").
5. **Remittance reports** — monthly "pay ZIMRA" (PAYE + AIDS levy, P2-style) and
   "pay NSSA" schedules with due dates (10th of following month for PAYE). This is
   what turns a calculator into a compliance tool SMEs pay for.
6. **QPD estimator** — company-level: estimate annual taxable profit from the P&L,
   apply corporate rate, show the 10/25/30/35 schedule with amounts, dates, and
   reminders. One setting per company to enable.
7. **Compliance view** — unregistered headcount + cost-to-register estimate.

## Notes

- Payroll currency: `payroll_runs`/`payroll_items` need `currency` + rate columns in
  multi-currency Phase A (payroll is on Phase A's touch-list).
- Payslips must show PAYE, AIDS levy, and NSSA separately (employees expect it, and
  ZIMRA/NSSA audits check payslips).
- Rates cited here are 2026 snapshots from public sources — verify against current
  ZIMRA/NSSA publications when seeding the tables.

## Sources

- ZIMRA PAYE tables 2026: https://registercompany.co.zw/zimra/income-tax
- M&J PAYE calculator: https://mjconsultants.co.zw/tools/paye-calculator/
- NSSA contributions: https://www.nssa.org.zw/contributions/
- NSSA rates 2025/26: https://mjconsultants.co.zw/insights/nssa-rates-2025-updated-pension-and-accident-prevention-scheme-contributions/
- Lucent — NSSA POBS & APWCS: https://lucent.co.zw/bookkeeping/nssa-pobs-and-apwcs/
- Lucent — QPD provisional tax: https://lucent.co.zw/tax/provisional-tax-qpd-in-zimbabwe-what-every-business-must-know/
- ZIMRA — 2026 first QPD notice: https://www.zimra.co.zw/public-notices?download=4473:public-notice-17-of-2025-2026-first-quarterly-payments
