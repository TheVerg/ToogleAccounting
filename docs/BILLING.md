# Tenant Billing / Subscriptions

Status: **planned — build after fiscalisation** (see [FISCALISATION.md](FISCALISATION.md))
Depends on: multi-tenancy (built — see [TENANCY.md](TENANCY.md))

> **Already shipped (2026-07-24), pulled forward with self-registration:**
> `tenants.plan/status/trial_ends_at` columns, public signup at `/register`
> (business name, owner name/email/password, base currency, terms; optional
> invite-only via `SIGNUP_INVITE_CODE`; trial length `SIGNUP_TRIAL_DAYS`,
> default 30). Signup provisions tenant + holding company + owner
> (`TenantProvisioningService`, shared with `tenant:create`; the command
> provisions as `active`, or `--trial`). Existing tenants were backfilled as
> `active`. Still to build here: `tenant_payments`, the suspension middleware,
> `billing:check`, plan limits, and the Billing page.

## Why

The platform is multi-tenant SaaS: each tenant is one paying customer
(a business or group). System payments are billed **per tenant, never per
company** — one business, one subscription, one bill, regardless of how many
companies sit inside its group. Company/user counts are pricing dimensions
(plan limits), not separate bills.

## Design

### Tenant fields

Extend `tenants` (the `is_active` flag already there is the seed of this):

| Column | Type | Notes |
|---|---|---|
| `plan` | string | e.g. `starter` / `business` / `group` |
| `status` | string | `trial` / `active` / `past_due` / `suspended` |
| `trial_ends_at` | datetime | set by `tenant:create` (e.g. +30 days) |
| `subscription_ref` | string, nullable | reference at the payment provider (Paynow / Stripe / manual invoice no.) |
| `billing_email` | string, nullable | where invoices/receipts go |

Keep a `tenant_payments` table for the money trail: tenant_id, date, amount,
currency, method, reference, period covered, recorded_by. (Manual capture
first; provider webhooks can write the same rows later.)

### Plan limits

Suggested dimensions (final numbers = pricing decision):

| Plan | Companies | Users | Notes |
|---|---|---|---|
| starter | 1 | 3 | single company |
| business | 3 | 10 | small group |
| group | unlimited | unlimited | holding structures |

Enforcement is exactly two checks at creation time:
- `CompanyController::store` — company count vs plan limit → "upgrade" error.
- `UserController::store` — user count vs plan limit → "upgrade" error.
Central helper on `Tenant` (e.g. `canAddCompany()`, `canAddUser()`).

### Suspension gate

One middleware (`EnsureTenantIsActive`, added to the `company` middleware
group) reading `tenant->status`:

- `trial` / `active` — normal use; show days-left banner near trial end.
- `past_due` — banner warning, full access for a grace period (e.g. 14 days).
- `suspended` — users can still log in but land on a "subscription expired"
  page. **Read-only access, never data loss**: ZIMRA requires businesses to
  retain records, and lapsed customers should come back, not be locked out of
  their own books. Export stays available.

`tenant:create` sets `status = trial`, `trial_ends_at = +30 days`.

### Payment provider

Zimbabwe reality: card rails are unreliable, so support from day one:
1. **Manual/invoice billing** — ops marks a tenant paid (`tenant_payments`
   row + status flip). Simplest, works for bank transfer / USD cash / EcoCash.
2. **Paynow** (paynow.co.zw) integration later for self-serve card/EcoCash.
3. Stripe only if selling outside Zimbabwe.

A daily scheduled command (`billing:check`) moves statuses:
trial expired → `past_due`; grace period elapsed → `suspended`;
payment recorded → `active` + next `renews_at`.

### Admin surface

- Ops-side (no UI yet — artisan first): `tenant:status`, `tenant:mark-paid`.
- Tenant-side later: a Billing page (plan, renewal date, payment history,
  upgrade request).

## Phases

| Phase | Contents |
|---|---|
| **1** | Tenant plan/status/trial columns + `tenant_payments` + suspension middleware + `billing:check` + artisan ops commands |
| **2** | Plan limits on company/user creation + trial/past-due banners + tenant Billing page (read-only) |
| **3** | Paynow self-serve checkout + webhook → auto `tenant_payments` + status flips |

## Notes

- Never gate **data export** behind an active subscription.
- Billing currency: price in USD (ZWG optional at the day's rate — the
  exchange-rate infrastructure already exists).
- The suspension middleware must exempt: logout, the suspended-notice page,
  attachment/statement export routes.
