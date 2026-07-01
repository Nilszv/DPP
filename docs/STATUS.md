# DPP Platform - Build Status

**Living document. Updated as work progresses.** Last updated: 2026-07-01.

Legend: ✅ done · 🔨 in progress · ⬜ not started · ⏸️ deferred (later phase)

---

## Resume here (paused 2026-07-01)

**Where it stands:** the SaaS shell and DPP core loop are working end to end and reviewed
(~9/10). Live at `https://dpp.vdisain.ovh`. Latest on `Nilszv/DPP` `main` (pushed, commit
`7271d6c` + the follow-ups here). 93 tests pass.

**Just landed (this session): country-aware onboarding.** Country picked first drives the
VAT number (locked country prefix incl. Greece `EL` / Switzerland `CHE`, digit-only + length-
capped entry, per-country format) and the contact phone (searchable dial-code dropdown). All
profile fields required except address line 2; VAT required only where a country operates one.
A **duplicate-registration guard** runs three independent checks - (1) company name + country,
(2) registration number alone, (3) VAT number alone - any single hit flags a possible duplicate,
so the same company can't slip through by changing one field while keeping another consistent;
repeated blocked attempts (default 4th) suspend the **email account**, gate it to `/app/support` (contact form),
and alert `SUPPORT_EMAIL` with an admin-only reason; admins lift it from the org detail page.
VAT is canonicalized + validated **server-side** (`App\Support\VatNumber`), so the guard is not
browser-dependent. External review of this slice: **9.1/10** (P1/P2 fixed). See
`PRE_LAUNCH_CHECKLIST.md` for the `dev@vdisain.lv` placeholders to swap before launch.

**Built & working:** passwordless auth · multi-tenant orgs with roles · first-run onboarding
(country-aware company profile + country/tax + legal acceptance + duplicate/abuse guard) · DPP
create -> publish -> QR -> public resolve (consumer view, JSON-LD) · scan logging · billing
abstraction (manual driver, DB-driven plans, downgrade guard + Contact sales) · team management
(invites, seats, org switcher) · admin back-office (overview, orgs search/detail, QR browser,
plans, legal editor, user unsuspend) · CI.

**Best next steps (pick one):**
1. **Tiered public views** (repairer/recycler/authority) - access-map + snapshot plumbing
   already exists; only `consumer` renders.
2. **Stripe billing** - needs a Stripe account + the lapse-policy decision first.
3. **Post-publish versioning** (corrections to a published passport).
4. **Real per-category templates** (owner to provide field examples).

**Decisions still owed by the product owner** (bottom of this file): the full lapse policy,
the legal role (host vs. ESPR service provider), first product category, and final domains.

**How to run:** see `DEPLOYMENT.md`. Codebase map + conventions: see `ARCHITECTURE.md`.
Run tests: `php artisan test` (uses the `dpp_test` Postgres DB). Format: `./vendor/bin/pint`.

---

## Slice 1 - Core loop

Goal: a user can sign up, create an org, create a DPP from a template, publish it, and have a
scannable QR resolve to a public passport page. **Done.**

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
- ✅ Organization roles enforced via `PassportPolicy` (Owner/Admin/Editor/Viewer): editors+ create/edit/publish, managers (owner/admin) delete + change plan, viewers read-only. `User::roleInCurrentOrg()`. Verified by tests.
- ✅ **Team & member management** (`/app/team`): invite teammates by email (signed token, expiring), accept flow (only the invited email, joins + switches into the org), change role, remove member (last-owner protected), revoke invitation. **Per-plan seat limits** (free 1 / medium 3 / commercial unlimited; admin per-org `team_quota_override`) enforced server-side. Seat checks, last-owner checks, and accepts are **concurrency-safe** (per-org advisory lock, classid 2). Seat counts ignore expired invitations; a daily `invitations:prune` command cleans them up; plan seats backfilled by migration. **Org switcher** in the nav for users in multiple orgs. Verified by tests.
- ✅ Plan + quota enforcement server-side (Free=1 published, Medium=5, Commercial=custom) - enforced on publish in `PassportPublisher` (concurrency-safe per-org advisory lock); see DPP product layer below

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

### Onboarding & legal
- ✅ **First-run onboarding** (`onboarded` middleware): a new org is forced through a flow that collects the **company profile** (legal name, address, contact person) and **country** (drives tax %), and requires **explicit acceptance of every legal document** before using the app. Reading is nudged via a scroll-to-enable checkbox; acceptance is enforced server-side. `store()` refuses to re-run once onboarded (no profile overwrite via re-POST) and aborts if no policies are configured. The registration policy is guaranteed by a migration, not just the seeder.
- ✅ **Editable legal documents** (DB-driven, versioned): admin `/admin/legal` editor (the "registration policy" the policy maker edits). Changing the text bumps the version.
- ✅ **Acceptance audit trail** (`legal_acceptances`): records which org/user accepted which document version, when, with an HMAC-hashed IP - evidence for the 10-year duty.
- ✅ **Company profile page** (`/app/organization`): shows the captured data + applicable VAT; owner/admin can edit. Shared `company-fields` partial keeps the form easy to adjust in one place.
- ✅ **Country + VAT config** (`config/tax.php`, EU-27 + a few others): single source for the country dropdown and the tax rate applied later at billing time.
- ✅ **Country-aware onboarding fields**: country first; VAT number shows a locked country prefix (Greece `EL`, Switzerland `CHE` handled) with digit-only, length-capped entry and per-country format validation; contact phone has a searchable dial-code dropdown. All fields required except address line 2; VAT required only for countries that operate a VAT number.
- ✅ **Duplicate-registration guard**: onboarding blocks completion via three **independent** checks against already-completed organizations - any single hit flags a possible duplicate: (1) company name + country (case/whitespace-insensitive; a name is unique per country), (2) registration number alone (formatting-insensitive), (3) VAT number alone (canonical). The error surfaces on whichever field triggered the match. (2026-07-01: replaced an earlier version that required name + registration number + VAT to *all* match together, which let an exact-name duplicate through if the registration number or VAT differed.) Repeated blocked attempts (default: 4th) suspend the **email account** (user-level `suspended_at`), gate it to `/app/support` (contact form: phone/email/company/message), and alert `SUPPORT_EMAIL` with an admin-only reason (including which check matched). Admin lifts the suspension from the org detail page. VAT is canonicalized + validated **server-side** (`App\Support\VatNumber`) so entry is not browser-bypassable; a backfill migration canonicalizes any pre-existing `vat_id` values. External review: 9.1/10. See `PRE_LAUNCH_CHECKLIST.md` for the `dev@vdisain.lv` placeholders to swap before launch.

### DPP product layer
- ✅ Generic template seeded (`TemplateSeeder`); product created behind the passport wizard
- ✅ DPP create + edit driven by the template field-schema (plain HTML form)
- ✅ Draft -> Publish workflow (`PassportPublisher`): required-field gate, **server-side quota enforcement (concurrency-safe: per-org advisory lock + quota re-check inside the transaction)**, master data locked (version + canonical SHA-256 hash), retention date set. Verified by tests.
- ✅ Identifiers: GS1 Digital Link (`/01/{GTIN}/21/{serial}`) + fallback UUID (`/p/{uuid}`) via `Passport::resolverUrl()`
- ✅ QR generation (SVG, vector/print-scalable) via `bacon/bacon-qr-code`
- ⬜ Print-ready PNG export (Imagick) - SVG done, PNG later

### Public viewer / resolver
- ✅ Resolver route handling both URL shapes + content negotiation (HTML vs JSON-LD)
- ✅ `published_snapshots` built on publish (`SnapshotBuilder`, consumer + full audiences); resolver reads ONE snapshot row
- ✅ Consumer view (plain HTML, no auth); drafts/unknown ids return 404
- ✅ Scan logging into partitioned `scan_events` (`ScanLogger`, HMAC-hashed IP)
- ✅ Route-model binding is tenant-safe: `BelongsToOrganization::resolveRouteBinding` constrains to a **membership-validated** org (shared `User::currentOrganizationIdIfMember`) and binds nothing (404) when no valid org - independent of middleware order, safe against a revoked membership with a stale current_organization_id. Covered by tests.

### Dashboard
- ✅ Basic dashboard + passport list (status), shared `layouts/app` chrome - unstyled baseline

---

## Slice 2 - Billing
- ✅ **Billing abstraction** (`App\Billing\BillingProvider`) with a **manual** driver - plan switch with no payment, so the plan/upgrade/quota flow works before any Stripe account exists. Driver chosen by `BILLING_DRIVER` (manual|stripe). Plan catalogue is now **DB-driven** (`plans` table, editable in the admin back-office; `config/billing.php` is the seed/fallback). Tenant plan page at `/app/billing`, owner/admin-gated. Verified by tests.
- ⏸️ **Stripe** driver (`StripeBillingProvider` via Cashier): add when a Stripe account exists, set keys, flip `BILLING_DRIVER=stripe`. Placeholders already in config + `.env.example`. UI/plans/quota do not change.
- ⏸️ EU VAT handling (OSS, reverse charge), compliant invoices
- ⏸️ Dunning / failed-payment / grace period
- 🔨 **Lapse policy** for published DPPs (partial): **self-service downgrade is blocked when the org has more published passports than the target plan allows** (`Organization::fitsPlan`), enforced server-side in `BillingController::switchPlan` and reflected in the UI (blocked plans show "Contact sales"). A **Contact sales modal** (textarea) emails `dpp.sales_email` (`dev@vdisain.lv`) for downgrade/custom-plan requests. Still open: what actually happens to published DPPs on full cancellation / non-payment (archive vs. perpetual-hosting SKU vs. 3rd-party transfer).

## Platform back-office (super-admin)  §2.6
- ✅ Super-admin flag (`users.is_admin`), `admin` middleware, `php artisan admin:grant {email}` command
- ✅ `/admin` back-office (separate layout, super-admin only): platform analytics overview (orgs, users, passports, scans, plan distribution)
- ✅ Organization management: **searchable** (company name / contact email / member email), **filterable** (plan, status), **sortable** (newest, name, published count), **paginated** list; a **full detail view** per org (company profile, billing, members + emails, legal acceptances). Change plan, set **per-org overrides** (published-quota, **custom price + billing interval** for commercial deals), suspend/activate. Suspension is **enforced**: a suspended org is blocked from `/app` (`org.active` middleware) and from publishing (publisher guard); the public resolver still serves its published passports (never 404).
- ✅ **Delete a user** (support/testing tool, from the org detail page's member list): also deletes any organization where they're the sole member, since the duplicate-registration guard matches on org fields (name+country, registration number, VAT), not the user - an orphaned org would otherwise still block re-onboarding with the same details. Refuses to delete the last owner of an org that has other members (reassign first), and an admin can't delete their own account. Verified by tests.
- ✅ **DB-driven plans** (`plans` table + `Plan` model): create/edit plans, prices, quotas (null = unlimited), public/active flags, custom non-public plans. `Organization::publishedQuota()` precedence: per-org override -> DB plan -> config fallback. Verified by tests.
- ✅ **QR / passport browser** (`/admin/passports`): platform-wide, paginated (20/page, never loads everything), filter by organization + status, search by public id / GTIN / serial / product name, lazy-loaded QR thumbnails. Verified by tests.
- ⏸️ Impersonation (log in as a user, with audit) - next
- ⏸️ Published-DPP lifecycle tools (archive, legal holds, migrations)

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
- ✅ Admin search (passports + organizations) backed by `pg_trgm` GIN indexes so leading-wildcard ILIKE stays fast at scale
- ⏸️ Redis cache + CDN edge in front of snapshots (designed-for now, added when traffic needs it)
- ⏸️ Read replicas; tenant-hash partitioning of `passports`
- ⏸️ Styling / design system (designer fills in SCSS over the plain semantic HTML)
- ⏸️ WordPress public marketing site at `/`, platform mounted at `/login` + `/app` + `/p`
- ✅ Transactional email (SMTP configured and verified) - currently sends synchronously; move to queued in prod
- ✅ **Automated test suite** + Postgres test database + GitHub Actions CI (done in the code-review remediation above). Expand coverage as features land.
- ⏸️ GDPR: DPA, export, erasure path for lifecycle personal data
- ⏸️ Legal role decision: generic host vs. ESPR "DPP service provider"

---

## Open decisions still needed from product owner
- ⬜ **Lapse policy** for published DPPs after subscription ends (blocking for Slice 2 launch).
- ⬜ Legal role: generic host vs. ESPR DPP service provider (affects ToS, not code yet).
- ⬜ Template field examples per category (owner will provide; slot into template schema).
- ⬜ Final domains: WordPress site domain vs. platform domain/subdomain.
