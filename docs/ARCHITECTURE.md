# DPP Platform - Architecture & Codebase Map

A durable map of how the code is organized and the conventions to follow. Pair this with
STATUS.md (what is done / pending) and DEPLOYMENT.md (how to run / go live).
Last updated: 2026-06-30.

---

## Stack
- PHP 8.3 / Laravel 12, PostgreSQL 14.
- Plain semantic HTML, no design system yet. One baseline stylesheet at
  `public/css/app.css`, linked with a `?v={filemtime}` cache-buster. A designer replaces it
  later; markup/class names stay.
- `database` driver for queue/cache/session (no Redis dependency yet).
- Hosting: nginx + php8.3-fpm, Let's Encrypt cert for `dpp.vdisain.ovh` (dev).

## Three surfaces
1. **Public marketing site** - WordPress, NOT in this repo. Will own `/` in production; the
   platform mounts at `/login`, `/app`, `/p`, `/01`, `/admin`.
2. **Authenticated app** (`/app/*`) - dashboard, passports, team, company profile, billing.
3. **Public passport resolver** (`/p/{public_id}`, `/01/{gtin}/21/{serial}`) - no auth, the
   QR scan target. Reads ONE pre-built snapshot row; content-negotiates HTML vs JSON-LD.

## Middleware (aliases in `bootstrap/app.php`)
Applied in this order on the authenticated app group:
`auth` -> `org.context` -> `org.active` -> `onboarded`.
- `org.context` (`SetCurrentOrganization`) binds `app('currentOrganizationId')` to the user's
  **membership-validated** current org (`User::currentOrganizationIdIfMember`), repairing a
  stale `current_organization_id`.
- `org.active` (`EnsureOrganizationActive`) 403s if the current org is suspended.
- `onboarded` (`EnsureOnboarded`) redirects to onboarding until the org's profile + legal
  acceptance are complete.
- `admin` (`EnsureUserIsAdmin`) gates `/admin/*` to platform super-admins (`users.is_admin`).
- Logout, invitation-accept, and org-switch sit OUTSIDE the onboarded/active gates on purpose
  (a suspended/new user must still log out / accept an invite / switch org).

## Tenancy (strict isolation)
- Every tenant table has `organization_id`. Tenant models use the `BelongsToOrganization`
  trait: adds the `OrganizationScope` global scope (constrains queries to the bound current
  org), auto-fills `organization_id` on create, and overrides `resolveRouteBinding` so route
  binding is tenant-safe **regardless of middleware order** (a foreign id 404s; no valid org
  resolves to nothing, never an unconstrained lookup).
- The public resolver and admin run with NO bound org, so the scope is inert there and they
  query explicitly (`withoutGlobalScope`).

## Auth (passwordless)
- No passwords. `LoginCodeService` issues a 6-digit code (hashed, 10-min expiry, single-use,
  5-attempt cap, one active code per email enforced by a partial unique index + advisory
  lock). First login creates the user + their first organization (owner).
- `PasswordlessController`: send code (per-email throttle + cooldown, reserved atomically
  BEFORE the slow SMTP send), verify (row-locked), opt-in remember.

## Key services
- `PassportPublisher` - publish workflow: required-field gate, server-side quota, suspension
  recheck, lock master data (append-only version + canonical SHA-256 hash), build snapshots.
  Quota check is inside a per-org advisory-locked transaction.
- `SnapshotBuilder` - builds `published_snapshots` per audience (consumer, full) + locale,
  filtered by the template `access_map`.
- `QrService` - SVG QR for a passport's resolver URL (vector, print-scalable).
- `ScanLogger` - writes scans to the month-partitioned `scan_events` (HMAC-hashed IP).
- `CanonicalJson` - sorted-key compact JSON + SHA-256 (reproducible content hashes).
- `Billing\BillingProvider` (interface) + `ManualBillingProvider` - plan changes with no
  payment; a `StripeBillingProvider` slots in later via `BILLING_DRIVER`.

## Advisory-lock conventions (Postgres, per-resource serialization)
- Login issuance: `pg_advisory_xact_lock(hashtext(email))` (single-arg keyspace).
- Publish: `pg_advisory_xact_lock(1, hashtext(org_id))` (classid 1).
- Team ops (invite/accept/role/remove): `pg_advisory_xact_lock(2, hashtext(org_id))` (classid 2).
The single-arg and two-arg keyspaces are disjoint, and the classids separate purposes.

## Plans, quotas, overrides
- Plans are DB-driven (`plans` table), `config/billing.php` is seed/fallback. A plan has
  `published_quota` and `team_quota` (NULL = unlimited).
- Precedence is always: per-org override -> DB plan -> config fallback. Org overrides:
  `published_quota_override`, `team_quota_override`, `price_override`, `interval_override`.
- Downgrades that would strand published passports are blocked (`Organization::fitsPlan`);
  the only path is Contact sales (emails `dpp.sales_email`).

## Admin back-office (`/admin`, super-admin only)
Overview (analytics), Organizations (search/filter/sort/paginate + full detail view, edit
plan/quota/price/seats/status), QR codes (cross-tenant passport browser), Plans (CRUD),
Legal (edit versioned policies). `php artisan admin:grant {email}` promotes a super-admin.

## Onboarding & legal
- New org is gated through onboarding: company profile + country (tax) + explicit acceptance
  of every required `legal_documents` row. Acceptances recorded in `legal_acceptances`
  (audit). The registration policy is guaranteed by a migration, not just the seeder.
- `store()` refuses to re-run once onboarded (no profile overwrite); aborts if no policies.

## Commands & schedule (`routes/console.php`)
- `partitions:ensure` (monthly) - pre-create next month's `scan_events`/`audit_log` partitions.
- `invitations:prune` (daily) - delete expired unaccepted invitations.
- `admin:grant {email} [--revoke]` - promote/demote super-admin.

## Testing
- PHPUnit against a real Postgres test DB (`dpp_test`; `phpunit.xml` forces pgsql) because the
  schema uses Postgres-only features (JSONB, partitioning, partial indexes, citext, trgm).
- 71 feature tests. Mailables are rendered in tests (Mail::fake skips rendering).
- CI: `.github/workflows/ci.yml` (Postgres service, Composer, Pint --test, PHPUnit, npm build).

## Conventions
- **No em-dashes or en-dashes** anywhere (hyphens only).
- **Do not `git push`** unless explicitly asked; local commits are fine.
- Plain unstyled HTML; keep markup designer-friendly with meaningful class names.
