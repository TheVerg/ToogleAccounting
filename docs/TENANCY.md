# Multi-Tenancy

Status: **built** (2026-07-24)

One hosted instance serves many customer organizations ("tenants"), each fully
isolated — the QuickBooks Online model. A tenant is a business (or group) with
its own users, companies, and books.

## How it works

- `tenants` table; `tenant_id` on **users**, **companies**, **audit_logs**, and
  **exchange_rates** (nullable = shared). Every other table inherits isolation
  through its existing `company_id`.
- `App\Models\Concerns\BelongsToTenant` (on `Company` and `User`) adds a global
  query scope filtered by the authenticated user's tenant and stamps
  `tenant_id` on creation. Isolation is enforced at the query layer: route
  model binding 404s for foreign records, admin listings only show the tenant's
  own rows, and "system admin" is effectively **tenant admin** (its
  see-everything queries are tenant-scoped too).
- Users created on the Users screen inherit the creator's tenant automatically;
  `tenant_id` is never accepted from request input. Emails are unique globally
  (one account belongs to one tenant).
- Company codes are unique **per tenant** (DB constraint `tenant_id + code`).
- Exchange rates: auto-fetched rates are shared reference data
  (`tenant_id null`, maintained by `fx:fetch-rates`); manually entered rates
  belong to the entering tenant and outrank the shared rate for the same date.
  Tenants cannot delete shared rates. Global tax rates and role definitions
  remain shared.
- Audit logs are stamped with the acting user's tenant and filtered per tenant.

## Onboarding a new tenant

Self-registration is disabled. Provision with:

```
php artisan tenant:create "Acme Group" --admin-email=owner@acme.co.zw \
    --company="Acme Holdings" --code=HQ --currency=USD
```

Creates the tenant, its first (holding) company with a default chart of
accounts, and a system-admin user with a temporary password (printed once).

## Migration / backfill

`2026_07_24_150000_create_tenants_and_add_tenant_id` gathered all pre-existing
users, companies, audit logs, and manual exchange rates into one default tenant
named after the first holding company.

## Known caveats

- Role *definitions* are shared across tenants (they are permission bundles,
  not data). A tenant admin with `roles.manage` edits definitions all tenants
  use — if custom per-tenant roles become a requirement, roles need their own
  `tenant_id`.
- There is no cross-tenant super-admin UI; operate on other tenants via
  artisan/DB. Add a support back-office if/when needed.
- FDMS fiscalisation credentials (next feature) should hang off the tenant or
  company, never global config.
