# DPP Platform

Multi-tenant SaaS for creating and hosting **Digital Product Passports** (EU ESPR).
Users create passports from category templates, publish them, and a scanned QR resolves
to a fast public passport page.

## Status

Early development. **Slice 1 (core loop)** in progress. See:

- `docs/STATUS.md` - what is done vs. pending (kept current)
- `docs/DPP_SaaS_Platform_Scope.md` - product scope, tiers, build phases
- `docs/DPP_Database_Architecture.md` - data layer design (the scalability core)
- `docs/DEPLOYMENT.md` - what the system needs to run / migrate to production

## Stack

- PHP 8.3 / Laravel 12
- PostgreSQL 14 (JSONB passport bodies, partial indexes, month-partitioned scan/audit tables)
- Plain semantic HTML, no styling yet (a designer adds SCSS later over the markup)
- Object storage via Laravel filesystem (local in dev, S3-compatible in prod)

## What works so far

- Database foundation: organizations + membership, products, passports (GS1 Digital Link +
  fallback UUID identifiers), append-only versions with canonical-JSON SHA-256 hashing,
  the read-side `published_snapshots` table, partitioned `scan_events` / `audit_log`.
- Tenant isolation via an organization global scope.
- **Passwordless auth**: email -> 6-digit code -> verify -> login (single-use, expiring,
  attempt-capped, rate-limited). First login creates the account + the user's first org.

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# configure DB_* for a PostgreSQL 14 database with citext + pgcrypto extensions
php artisan migrate
php artisan serve
```

## Architecture notes

- **System of record vs. system of delivery.** Writes go to normalised tables; a scan reads
  one pre-built snapshot row (never a live join), so reads stay fast at scale. Redis/CDN
  slot in front of the snapshot later with no rewrite.
- **Published passports are a long-term duty** (product lifetime + ~10 years). Versions are
  append-only and hashed; personal data is kept out of locked versions for GDPR erasure.

No build tooling is required to review; this is a standard Laravel project layout.
