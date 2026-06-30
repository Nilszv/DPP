# DPP Platform - What the System Needs to Support (Deployment / Migration)

**Purpose:** capture everything required to run this in production and to migrate it off
this dev box cleanly. Kept current as new infra dependencies are added. Last updated: 2026-06-30.

> Rule: whenever a slice introduces a new runtime dependency (a service, an env var, a
> cron job, an external account), it gets added here in the same change.

---

## 1. Runtime requirements (current)

| Component | Dev (now) | Production target |
|---|---|---|
| PHP | 8.3 (CLI + needs FPM in prod) | 8.3 FPM behind Nginx |
| PHP extensions | `pdo_pgsql`, `pgsql`, plus Laravel defaults (mbstring, openssl, gd/imagick for QR PNG) | same |
| Database | PostgreSQL 14 (local, role `dpp`) | PostgreSQL 14+ (managed or VPS), daily backups, PITR |
| Web server | `php artisan serve` :8000 | Nginx + PHP-FPM, HTTPS (Let's Encrypt) |
| Queue | `database` driver | `database` now; Redis/Horizon when volume grows |
| Cache / session | `database` driver | `database` now; Redis later |
| Object storage | local disk (`storage/app`) via Laravel filesystem | S3-compatible bucket (QR assets, archived passports, backups) |
| Node | 20.20.1 (Vite build only, not runtime) | build assets in CI, ship compiled |

**Why local→S3 is config-only:** all file writes go through Laravel's `Storage` facade, so
switching `FILESYSTEM_DISK=local` → `s3` is an env change, no code change.

---

## 2. Required environment variables

Core (Laravel default): `APP_KEY`, `APP_ENV`, `APP_URL`, `DB_*`.

**Required now:**
- `PASSPORT_BASE_URL` - the public base that scanned QR codes encode and resolve to.
  Dev: `http://localhost:8000`. Prod: the passport-resolver domain (must be HTTPS, stable
  **forever** - QR codes are permanent and cannot be reprinted).
- `PASSPORT_DEFAULT_LOCALE` - default Member-State language for the public passport layer.
- `MAIL_*` (SMTP) - **required**: passwordless login codes and team invitations are emailed.
  Dev uses `mail.veebimajutus.ee:465` (SSL), from `info@vdisain.lv`.
- `SCAN_IP_HMAC_KEY` - keyed HMAC secret for hashing scanner IPs (GDPR). Defaults to `APP_KEY`
  if unset; set an explicit value before real scan traffic. Rotating it is intentional.
- `SALES_EMAIL` - where "Contact sales" / downgrade requests are delivered (dev: `dev@vdisain.lv`).
- `BILLING_DRIVER` - `manual` (no payment; plan switches instantly) until Stripe is set up.
- `DPP_UNTHROTTLED_EMAILS` - comma-separated emails exempt from the login send throttle
  (testing only; **keep empty in production**).

**Later slices (placeholders already in `.env.example`):**
- Stripe billing: `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_MEDIUM`,
  `CASHIER_*`, VAT config (set `BILLING_DRIVER=stripe`).
- Registry push credentials, backup-provider creds.
- `AWS_*` - when object storage moves to S3.

---

## 3. Scheduled jobs (cron) - `php artisan schedule:run` every minute

Defined in `routes/console.php`:
- `partitions:ensure` (monthly) - pre-create next month's `scan_events` / `audit_log`
  partitions before the boundary, or inserts fall into the default partition.
- `invitations:prune` (daily) - delete expired, unaccepted team invitations.
- Later: retention/archival sweep (move `archived` passports to cold object storage),
  dunning, backup-provider sync.

The **scheduler** must run in prod (cron: `php artisan schedule:run` every minute).
A **queue worker** is needed once mail/snapshots move to queued jobs (currently synchronous):
`php artisan queue:work` (systemd/supervisor).

---

## 4. The non-negotiable constraints (drive infra choices)

1. **Permanent resolution.** A *published* passport URL must never 404 - for product
   lifetime + ~10 years, surviving churn/non-payment/bankruptcy. → stable `PASSPORT_BASE_URL`,
   cold-archive tier, independent 3rd-party backup (ESPR).
2. **Read-heavy hot path.** Scans vastly outnumber writes. Resolver reads ONE pre-built
   `published_snapshots` row - never a live join. Redis/CDN slot in front later with no rewrite.
3. **Tenant isolation.** Every tenant table carries `organization_id` + a global scope;
   no query may cross orgs. Must hold under load and be covered by tests.
4. **Tamper-evidence + audit.** Published versions are append-only, hashed (canonical JSON
   SHA-256), and locked. All edits/access logged to a partitioned `audit_log`.
5. **GDPR.** Personal data stays out of locked master versions; lifecycle personal data is
   separately erasable. Scanner IPs are HMAC-hashed, not stored raw.

---

## 5. Migration checklist (moving off this dev box)

- [ ] Provision PostgreSQL 14+, create role + DB, enable `citext`, `pgcrypto`, `pg_trgm`.
- [ ] `git clone`, `composer install --no-dev`, `npm ci && npm run build`.
- [ ] Set all env vars (§2), `php artisan key:generate`, `php artisan migrate --force`.
- [ ] Seed reference data: `php artisan db:seed` (plans, generic template, registration policy).
- [ ] Promote the first super-admin: `php artisan admin:grant you@example.com` (after they sign in once).
- [ ] Configure SMTP (required) and `SALES_EMAIL`.
- [ ] Configure Nginx + PHP-FPM + HTTPS; point `PASSPORT_BASE_URL` at the final resolver domain.
- [ ] Start the scheduler (cron) + a queue worker (systemd/supervisor).
- [ ] Configure object storage (S3) + backups + PITR.
- [ ] WordPress: mount platform under `/login`, `/app`, `/p`, `/01`, `/admin`; WordPress owns `/`.
