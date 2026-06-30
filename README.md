# DPP Platform

Multi-tenant SaaS for creating and hosting **Digital Product Passports** (EU ESPR).
Users create passports from category templates, publish them, and a scanned QR resolves
to a fast public passport page.

## Status

Active development, core platform working end to end (reviewed ~9/10). Docs:

- `docs/STATUS.md` - what is done vs. pending (start here; has a "Resume here" section)
- `docs/ARCHITECTURE.md` - codebase map, middleware, services, conventions
- `docs/DEPLOYMENT.md` - what the system needs to run / migrate to production
- `docs/DPP_Database_Architecture.md` - data layer design + as-built schema
- `docs/DPP_SaaS_Platform_Scope.md` - original product scope, tiers, build phases

## Stack

- PHP 8.3 / Laravel 12
- PostgreSQL 14 (JSONB passport bodies, partial indexes, month-partitioned scan/audit tables,
  `pg_trgm` search). Extensions: `citext`, `pgcrypto`, `pg_trgm`.
- Plain semantic HTML, no styling yet (a designer adds SCSS later over the markup)
- Object storage via Laravel filesystem (local in dev, S3-compatible in prod)

## What works so far

- **Passwordless auth** (email -> 6-digit code), multi-tenant orgs with strict isolation and
  roles (owner/admin/editor/viewer).
- **First-run onboarding**: company profile + country (tax) + enforced legal acceptance.
- **DPP loop**: create from a template -> draft -> publish (quota-gated, locked + hashed
  version, snapshot built) -> QR -> public resolver (consumer HTML + JSON-LD) -> scan logging.
- **Billing** (manual driver, Stripe-ready): DB-driven plans, per-org overrides, downgrade
  guard + Contact sales.
- **Team management**: email invites, seats, role changes, org switcher.
- **Admin back-office**: analytics, org search/detail, cross-tenant QR browser, plan editor,
  legal-document editor.
- CI (GitHub Actions, Postgres) + 71 feature tests.

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# PostgreSQL 14 with citext + pgcrypto + pg_trgm; set DB_* + MAIL_* (SMTP) in .env
php artisan migrate
php artisan db:seed          # plans, generic template, registration policy
php artisan serve
# promote a super-admin after they sign in once:
php artisan admin:grant you@example.com
```

## Architecture notes

- **System of record vs. system of delivery.** Writes go to normalised tables; a scan reads
  one pre-built snapshot row (never a live join), so reads stay fast at scale. Redis/CDN
  slot in front of the snapshot later with no rewrite.
- **Published passports are a long-term duty** (product lifetime + ~10 years). Versions are
  append-only and hashed; personal data is kept out of locked versions for GDPR erasure.

No build tooling is required to review; this is a standard Laravel project layout.
