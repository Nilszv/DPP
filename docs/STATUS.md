# DPP Platform - Build Status

**Living document. Updated as work progresses.** Last updated: 2026-06-30.

Legend: ✅ done · 🔨 in progress · ⬜ not started · ⏸️ deferred (later phase)

---

## Slice 1 - Core loop (no billing, no styling)

This is the slice currently being built. Goal: a user can sign up, create an org,
create a DPP from a template, publish it, and have a scannable QR resolve to a public
passport page. No payments, no CSS.

### Foundation
- ✅ PostgreSQL 14 installed + `dpp` database/role created (`citext`, `pgcrypto` extensions)
- ✅ `php8.3-pgsql` extension installed
- ✅ Laravel 12 scaffolded in `/home/ubuntu/dpp`, connected to Postgres
- ✅ `.env` configured (pgsql, `PASSPORT_BASE_URL`, queue/cache/session = database driver)
- ✅ Default Laravel migrations run on Postgres
- ✅ Domain schema migrations - organizations + membership, templates, products, passports, passport_versions, published_snapshots, scan_events (month-partitioned), audit_log (month-partitioned). Verified: partitions live, GS1 partial-unique index live, JSONB columns present.
- ✅ Eloquent models (UUID PKs) + relationships + tenant global scope (`BelongsToOrganization` + `OrganizationScope`)
- ✅ Canonical JSON + SHA-256 hashing helper (`App\Support\CanonicalJson`) - verified order-independent
- ✅ `config/dpp.php` (passport base URL, default locale, scan IP HMAC key, audiences)
- ✅ **Live hosting:** nginx site + Let's Encrypt cert for `dpp.vdisain.ovh` (fixes the Cloudflare 526). Plain-HTML landing page + `/health` endpoint serving. `APP_URL`/`PASSPORT_BASE_URL` set to `https://dpp.vdisain.ovh`.
  - ⚠️ `APP_DEBUG=true` / `APP_ENV=local` for now (dev visibility). **Must flip to `false`/`production` before real launch** - see DEPLOYMENT.md.
- ⬜ Seed: one "Generic" template + factories

### Auth & tenancy
- ✅ **Passwordless auth** (no passwords): email -> 6-digit code -> verify -> login. Guardrails: 10-min expiry, single-use, max 5 attempts per code (then burned), code stored hashed, route rate limiting (5/min send, 10/min verify), no account-existence leak. Verified end-to-end (issue/wrong/correct/reuse/account-creation).
- ✅ First login creates the account + the user's first Organization (they become owner) + sets current org. `/login`, `/login/code`, `/logout`, `/app` live; `/app` redirects to login when unauthenticated.
- ✅ **SMTP configured** (reused veebimajutus.ee / info@vdisain.lv from existing projects). Real send verified.
- ✅ Tenant-context middleware (`org.context` -> `SetCurrentOrganization`) binds current org so `OrganizationScope` isolates tenant queries on `/app`.
- ✅ Tenant isolation verified by automated tests (scope hides other tenants, cross-tenant id lookup blocked, stale-org middleware fallback)
- ⬜ Organization roles enforced in UI/policies (Owner/Admin/Editor/Viewer) - roles stored, not yet gated
- ⬜ Plan + quota enforcement server-side (Free=1 published, Medium=5, Commercial=custom) - quota helper exists, not yet enforced on publish

### Code review remediation (2026-06-30, external review)
- ✅ Account+org+membership creation wrapped in a DB transaction (no partial state); concurrent first-login unique-email race handled
- ✅ Persistent "remember me" is now opt-in (checkbox), not forced
- ✅ Emails are `citext` at the DB level (case-insensitive uniqueness, not just app lowercasing)
- ✅ Per-email send throttle (5/hour) + 60s resend cooldown, on top of the per-IP route throttle
- ✅ Tenant-context middleware verifies the user is still a member of the stored org (falls back + repairs otherwise); FK added on `users.current_organization_id`
- ✅ `partitions:ensure` command (referenced by docs) implemented + scheduled monthly
- ✅ Postgres test database wired (phpunit forces `pgsql` + `dpp_test`); 12 feature tests pass
- ✅ GitHub Actions CI: Postgres 14 service, Composer, Pint (`--test`), PHPUnit, npm build
- ✅ `.env.example` updated to the real project (Postgres, DPP env vars, app name, SMTP)

### DPP product layer
- ⬜ Product CRUD (unstyled HTML)
- ⬜ DPP wizard driven by template field-schema
- ⬜ Draft → Publish workflow (lock master data, require mandatory fields, write version + hash)
- ⬜ Identifiers: GS1 Digital Link (`/01/{GTIN}/21/{serial}`) + fallback UUID (`/p/{uuid}`)
- ⬜ QR generation (SVG + print-ready PNG) via `bacon/bacon-qr-code`

### Public viewer / resolver
- ⬜ Resolver route handling both URL shapes + content negotiation (Accept / linkType)
- ⬜ `published_snapshots` build-on-publish job
- ⬜ Consumer view (plain HTML, no auth, reads one snapshot row)
- ⬜ Scan logging into partitioned `scan_events`

### Dashboard
- ⬜ Basic dashboard (DPP list, status, scan count) - unstyled

---

## Slice 2 - Billing  ⏸️
- ⏸️ Stripe Cashier subscriptions (monthly/annual, proration)
- ⏸️ EU VAT handling (OSS, reverse charge), compliant invoices
- ⏸️ Dunning / failed-payment / grace period
- ⏸️ **Lapse policy** for published DPPs after cancellation (REQUIRED before real launch - Free allows published, so the 10-year duty applies to churned free users)

## Slice 3 - Compliance depth  ⏸️
- ⏸️ Tiered access views (repairer / recycler / authority / customs) - mechanism stubbed in Slice 1
- ⏸️ EU Registry push + commodity code
- ⏸️ Full versioning UI + audit trail surface
- ⏸️ Persistence/backup tier (cold archive export to object storage, 3rd-party backup copy)
- ⏸️ i18n (LV/EN + buyer Member-State language on public layer)

## Slice 4 - Commercial tier  ⏸️
- ⏸️ Public REST API + API keys
- ⏸️ Bulk import (CSV/ERP)
- ⏸️ White-label / custom domain resolver
- ⏸️ SSO (SAML/OIDC), advanced analytics export

## Cross-cutting (later, tracked here so nothing is lost)  ⏸️
- ⏸️ Redis cache + CDN edge in front of snapshots (designed-for now, added when traffic needs it)
- ⏸️ Read replicas; tenant-hash partitioning of `passports`
- ⏸️ Styling / design system (designer fills in SCSS over the plain semantic HTML)
- ⏸️ WordPress public marketing site at `/`, platform mounted at `/login` + `/app` + `/p`
- ✅ Transactional email (SMTP configured and verified) - currently sends synchronously; move to queued in prod
- ⬜ **Automated test suite** + a Postgres test database (our migrations use Postgres-only features: partitioning, partial indexes - so tests cannot run on the default sqlite). Needed to assert tenant isolation and auth flows.
- ⏸️ GDPR: DPA, export, erasure path for lifecycle personal data
- ⏸️ Legal role decision: generic host vs. ESPR "DPP service provider"

---

## Open decisions still needed from product owner
- ⬜ **Lapse policy** for published DPPs after subscription ends (blocking for Slice 2 launch).
- ⬜ Legal role: generic host vs. ESPR DPP service provider (affects ToS, not code yet).
- ⬜ Template field examples per category (owner will provide; slot into template schema).
- ⬜ Final domains: WordPress site domain vs. platform domain/subdomain.
