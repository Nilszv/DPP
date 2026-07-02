# DPP Platform - Build Status

**Living document. Updated as work progresses.** Last updated: 2026-07-01.

Legend: ✅ done · 🔨 in progress · ⬜ not started · ⏸️ deferred (later phase)

---

## Resume here (paused 2026-07-01)

**Where it stands:** the SaaS shell and DPP core loop are working end to end and reviewed
(~9/10). Live at `https://dpp.vdisain.ovh`. Latest on `Nilszv/DPP` `main`. 185 tests pass.

**Just landed: GDPR self-service (export + erasure) at `/app/privacy`.** Behind plain `auth`
only -- a suspended or half-onboarded user has exactly the same data rights, so none of the
org/onboarding/active gates apply (same pattern as `/app/support`; standalone page, no
org-context nav). **Export** (Art. 15/20): JSON download of everything held about the person
-- profile, memberships+roles, legal acceptances, invitations sent/received, their own audit
entries -- audited as `gdpr.export`. **Erasure** (Art. 17): type-your-email confirmation, then
`App\Services\AccountEraser` (shared with the admin delete-user tool, which now scrubs too --
previously it removed only the user row + sole-member orgs) deletes the user, sole-member
unpublished orgs, login codes + invitations keyed by EMAIL (no FK reaches those), database
sessions, and redacts the email inside `audit_log` meta under whatever key it hides
(jsonb scrub; the EVENTS stay -- Art. 17(3)(b) legal-obligation carve-out -- only identifiers
go). The erasure itself is audited (`gdpr.erasure`, initiated_by self|admin, deliberately
without the email). Legal-acceptance evidence has no user FK, so it survives for its 10-year
duty wherever the org survives. **Blockers** route to manual handling (GDPR permits it): sole
owner of an org with other members (reassign first), or a sole-member org with published
passports (permanent public record; contact support). An impersonation session can never
erase the account (would make impersonation an unaudited erasure lever). 8 tests
(`GdprPrivacyTest`). Still owner-side: the DPA document itself (legal text, not code).

**Just landed: print-ready PNG QR export.** `php8.3-imagick` installed on the server (and
already listed in DEPLOYMENT.md's requirements); `QrService::png()` renders via bacon-qr-code's
Imagick backend. Same auth'd QR route with `?format=png` (+ optional `size`); the passport
page offers both downloads ("SVG, any print size" / "PNG, 1200 px ≈ 10 cm at 300 dpi"). Size
is clamped to 240-2400 px -- the floor keeps carriers readable, the cap (a 20 cm label at
300 dpi) exists because a 4096 px Imagick render costs ~4 s of CPU, a cheap DoS lever on an
authenticated route. Tenant isolation verified on the new format too. 4 tests
(`PassportQrExportTest`), incl. actual PNG decode + dimensions.

**Just landed: manual per-locale content translations.** The public-layer i18n translated
labels/chrome only; now the field **values** themselves can be translated -- by the
manufacturer, never by machine (decided 2026-07-02; an MT "suggest a draft for human review"
assist may come later once a provider/API key is chosen). The passport form gains a
"Translations -- EN" section per non-default public locale (base-language fields double as
placeholders); a blank input means "serve the original", so partially-translated passports
degrade gracefully per field. Stored as a `translations` JSONB map (`{locale: {key: value}}`)
on `passport_versions` -- part of the locked, versioned record, copied into correction drafts,
editable there, swapped atomically on correction publish like everything else. Unknown
locales/fields are dropped server-side; blanks are never stored. A translation can never
conjure a field whose BASE value is empty (no one-locale-only content). `content_hash`
deliberately keeps covering only the base `data`: the source-language record is the legally
binding master; translations are supplementary renderings (each snapshot carries its own
etag). Pre-existing versions have `translations = null`, which is exactly the old behavior --
no backfill needed. 7 tests (`PassportContentTranslationTest`). Also fixed this session's
review P2: audit-page date filters now reject impossible calendar dates (2026-02-31) via
`checkdate()`, not just shape-checking. (2026-07-02, follow-up review P2 fix: the public page
displayed the base-data hash as "Verified content hash" while showing translated values, so a
translation-only correction changed visible content without moving the displayed hash. The
page now shows BOTH guarantees: the base hash relabeled "Source record hash (original
language)" and the per-locale snapshot **etag** as "Verified hash of this page's content" --
the etag is the hash of exactly the rendered payload, translations included. JSON-LD responses
carry the etag as a proper `ETag` header. Correction audit rows gained
`from/to_translations_hash` so a translation-only correction is distinguishable from a no-op
even though its content hashes tie. Regression-tested end-to-end.)

**Just landed: admin audit-trail browser (`/admin/audit`).** Read-only, filterable surface
over the append-only `audit_log` (which now has real content: impersonation starts/stops and
correction publishes). Filters: action (from the distinct list), actor (partial name/email),
organization, date range (garbage dates ignored, never a query error). Always paginated (50) --
the table is month-partitioned and grows forever, nothing may load it unbounded. New indexes
`(action, ts DESC)` + `(actor_id, ts DESC)` on the partitioned parent back the filters
(propagate to all partitions incl. future `partitions:ensure` ones). Deleted actors render as
"system / deleted user" rather than breaking the page. Finishes the audit-surface half of the
Slice-3 item the version-history table started. 5 tests (`AdminAuditTrailTest`).

**Just landed: public-layer i18n (LV/EN, extensible per buyer Member-State language).**
Snapshot rows are now pre-built per **locale x audience** at publish/correction time
(`config('dpp.locales')`, env `PASSPORT_LOCALES=lv,en`; a passport's own `default_locale` is
always built even if dropped from the list). Field **labels** localize via an optional
per-locale `labels` map on each template field (plain `label` stays the manufacturer-facing
form label and the public fallback for untranslated templates); field **values** are always
served exactly as the manufacturer entered them -- regulated data is never machine-translated.
Page chrome is translated via `lang/{lv,en}/public.php`. The resolver negotiates: explicit
`?lang=` wins, then `Accept-Language` (matched on primary subtags, and only when the header is
genuinely present -- Symfony fabricates a default `en` otherwise, which must not outrank the
passport's own default), then the passport's `default_locale`; only locales that actually have
a snapshot row are ever chosen, so pre-backfill passports simply don't offer a language until
rebuilt. HTML responses carry a language switcher + `Vary: Accept, Accept-Language` alongside
the existing cache headers; JSON-LD returns the negotiated locale. A backfill migration
re-seeds the generic template (now with LV/EN label maps -- guaranteed by migration, same
precedent as the registration policy) and rebuilds all published passports' snapshots.
**Adding a language later** = add it to `PASSPORT_LOCALES` + a `lang/{locale}/public.php` +
template label translations, then rebuild snapshots. Verified by 9 tests (`PublicI18nTest`)
+ a live LV/EN smoke test (HTML chrome/labels + switcher + JSON-LD negotiation) against the
production server; the live DB was backfilled by the migration.

**Just landed: post-publish corrections (versioned editing of published passports).**
Previously a hard wall ("Published passports are locked. Versioned editing comes later.") --
now an editor+ can open a **correction draft** on a published passport: a new unlocked
`passport_versions` row seeded from the live version (the append-only schema was built for
exactly this). While the draft is open, the public page keeps serving the current version
untouched; the draft is edited with the same template-driven form. **Publishing the
correction** goes through the same regulated gate as first publish (required-field check,
suspended-org block, per-org advisory lock, canonical-hash + lock of the master data) --
minus the quota check, deliberately: the passport is already on the market, the published
count doesn't change, and an org that slipped over quota (admin plan change) must still be
able to correct a live passport. Nothing public-facing rotates: `public_id`, tier access
tokens, `published_at`, `retention_until` all stay -- only which version the snapshots serve
changes (all 5 audience rows rebuilt atomically; this exercises the composite-key
`PublishedSnapshot` save path fixed earlier, covered per-audience by the new tests). The swap
writes an `audit_log` row (`passport.correction.published`, from/to version numbers + content
hashes) **inside the same transaction** -- the public record can never change without its
audit row. A correction can also be **discarded** (draft deleted, published version
untouched). Starting a correction is double-click/two-tab safe (advisory lock + re-check;
the `(passport_id, version_no)` unique index would otherwise turn the race into a 500). The
passport page now shows a **version history table** (version, live/superseded/draft status,
created by, content hash) -- a first slice of the Slice-3 "versioning UI + audit trail
surface". 14 tests (`PassportCorrectionTest`). (2026-07-02, external review P1 fix: discard
originally deleted the draft *without* the per-org advisory lock, so a concurrent
publish-correction could lock + swap that same version live between discard's check and its
delete -- leaving `current_version_id` pointing at a deleted row, which no FK prevented.
Discard now takes the same lock and re-reads the open correction inside the transaction
(null = a concurrent publish won), and `passports.current_version_id` gained a **RESTRICT
FK** as a DB-level backstop: nothing legitimate ever deletes a live version, so if that
constraint fires it caught a bug. Whole-passport deletion still cascades - the head row goes
first, so its versions are unreferenced by the time the cascade reaches them; covered by
tests.)

**Previously landed: admin impersonation ("log in as" a user, with audit).** From an org's member
list (`/admin/organizations/{org}`), an admin can temporarily become a regular user - e.g. to
debug/verify their experience - with zero passwords or login codes needed. Starting one
requires a **fresh** TOTP/recovery code re-entered immediately before every single start
(`/admin/impersonate/confirm`), independent of the session's existing `2fa.passed` flag, since
that could be long-lived or left open in a tab. An admin can never impersonate another admin,
or themselves - checked at both the initial click *and* again right before the swap (closes a
TOCTOU window: the target's admin status, or the acting admin's own identity, could change in
the gap between the two requests). Every start/stop writes to the `audit_log` table (which
already existed, unused, purpose-built for exactly this) with actor/target/metadata. A
persistent banner ("You are impersonating {email} - [Stop impersonating]") shows on every
`/app/*` page while active; logging out fully clears impersonation state with no special
handling needed (session invalidation already does it). `Auth::loginUsingId()` swaps identity;
the original admin's id is stashed in the **session** (not the guard, which has no memory of
the prior identity once swapped) to support returning to it. Verified by tests + a live
end-to-end HTTP smoke test (start, confirm, org-context check, stop, `audit_log` rows) against
the production server. (2026-07-02, external review P1 fix: `session()->regenerate()` after the
identity swap keeps session *data*, so the impersonated session silently inherited the admin's
`2fa.passed` flag - if the target was promoted to admin mid-impersonation, or still had a
confirmed TOTP setup from a prior admin life, the admin 2FA middleware treated the session as
already verified. The flag is now cleared on entering impersonation and re-granted only when
stop() logs the original admin back in - safe because the only path that sets
`impersonate.original_admin_id` is a confirm() that just verified a fresh code. Both scenarios
covered by regression tests.)

**Previously landed: mandatory TOTP two-factor auth for admin users.** Every
`is_admin` account must complete an authenticator-app (Google Authenticator/Authy-style) code
before reaching any `/admin/*` page -- immediately and mandatorily on first admin login, no
skip. Layered on top of the existing passwordless flow: `PasswordlessController::verify()`
holds off `Auth::login()` for admins and routes them to `/login/2fa/setup` (first time, QR +
manual secret + 10 bcrypt-hashed one-time recovery codes shown once) or `/login/2fa/verify`
(already set up) before completing login. `EnsureAdminTwoFactorVerified` (`admin.2fa`
middleware) requires **both** a confirmed setup on the user **and** a per-session flag
(`session('2fa.passed')`) on every `/admin/*` request -- an external review caught that either
check alone is bypassable (session flag alone: a user promoted to admin mid-session, or any
session predating this feature, walks straight in with zero 2FA configured; confirmed-flag
alone: a remember-me-revived session skips verification for the current session). Also fixed:
the setup page itself refused to distinguish "first-time setup" from "already confirmed," so a
stale/hijacked authenticated session could silently overwrite an admin's secret without ever
proving the existing code -- setup is now refused outright for a confirmed admin. Lockout
after 5 failed codes (15 min, on top of the route throttle). Self-service management at
`/admin/security` (regenerate recovery codes, reset + redo setup with a step-up code
confirmation). Operator escape hatch: `php artisan admin:reset-2fa {email}`. TOTP via
`pragmarx/google2fa`; QR rendered by the existing `App\Services\QrService` (already used for
passport QR codes) -- no new QR dependency. Verified end-to-end against the live server with a
real HTTP flow, plus dedicated regression tests for both fixed bypasses. The one pre-existing
admin account now gets prompted for mandatory setup on its next `/admin/*` visit, as intended --
no grace period.

**Previously landed: tiered public views (repairer/recycler/authority).** A
published passport now builds a pre-filtered snapshot for all 5 audiences (`config('dpp.audiences')`,
was hardcoded to `['consumer', 'full']`), each reachable via a durable, revocable per-audience
link (`/p/{public_id}/{audience}/{token}`, token stored in the new `passport_access_tokens`
table -- permanent and survives `APP_KEY` rotation, unlike a Laravel signed URL, since these
may be printed in physical service manuals for years). Issued automatically at publish time;
org users see/copy/regenerate them from the passport's show page (editor+ can regenerate,
matching the existing publish gate). A backfill migration retrofitted tokens + snapshots for
passports published before this existed.

**Critical bug found + fixed during that work:** `PublishedSnapshot` has no single-column
primary key (its real key is composite: `passport_id`+`audience`+`locale`), and Eloquent's
default `save()`/`fresh()` build their `WHERE` from a single `$primaryKey` -- with it left
`null`, that silently produced an **unconstrained `UPDATE` that overwrote every row in the
whole table** the moment anything called `save()` on an already-existing row. This was dormant
before today because nothing had ever rebuilt an existing passport's snapshots; the new
backfill migration was the first thing to do that, and it corrupted all 20 existing
`published_snapshots` rows (every published passport's public page was serving unfiltered
`'full'` data) within seconds of running. Fixed by overriding `setKeysForSaveQuery()` /
`setKeysForSelectQuery()` on the model to constrain by the actual composite key; live data
was rebuilt and verified row-by-row after the fix. A regression test
(`PublishedSnapshotModelTest`) asserts saving one row never touches another. No other model
in the codebase uses `$primaryKey = null`.

**Previously landed: country-aware onboarding.** Country picked first drives the
VAT number (locked country prefix incl. Greece `EL` / Switzerland `CHE`, digit-only + length-
capped entry, per-country format) and the contact phone (searchable dial-code dropdown). All
profile fields required except address line 2; VAT required only where a country operates one.
A **duplicate-registration guard** runs three independent checks - (1) company name + country,
(2) registration number + country, (3) VAT number alone - any single hit flags a possible
duplicate, so the same company can't slip through by changing one field while keeping another
consistent (registration number is country-scoped, not global, since national registries can
coincidentally reuse the same digits across countries);
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

**Best next steps (recommended in order):**
1. ~~Post-publish versioning~~ ✅ · ~~public-layer i18n~~ ✅ · ~~audit-trail browser~~ ✅ ·
   ~~manual content translations~~ ✅ (MT "suggest draft" assist deferred until an MT
   provider/API key is chosen -- owner decision).
2. **Stripe billing** - blocked on a Stripe account + the lapse-policy decision from the
   product owner; not actionable until then.
3. **Real per-category templates** - blocked on the owner providing field examples.

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
- ✅ **Mandatory admin 2FA** (TOTP via `pragmarx/google2fa`): every `is_admin` user must complete an authenticator-app code before reaching `/admin/*`, set up immediately on first admin login (no skip, no grace period). Layered into the passwordless flow (`PasswordlessController::verify()` holds off `Auth::login()` for admins, routes to `/login/2fa/setup` or `/login/2fa/verify`). `EnsureAdminTwoFactorVerified` (`admin.2fa` middleware) requires **both** `hasTwoFactorConfirmed()` on the user **and** a per-session flag (`session('2fa.passed')`) - checking either alone was reviewed and found exploitable: the session flag alone let a user promoted to admin mid-session (or a stale session predating this feature) into `/admin/*` with no 2FA configured; the confirmed-flag alone doesn't stop a remember-me-revived session skipping verification for the current session. Non-admin logins no longer set the session flag at all (it has no legitimate use there and was the source of the promotion bypass). Setup (`/login/2fa/setup`) is refused for an already-confirmed admin regardless of session state (fixed: a stale/hijacked authenticated session could otherwise silently overwrite `two_factor_secret` without ever proving the existing code) - redo requires either the operator command or the code-confirmed reset at `/admin/security`. 10 individually bcrypt-hashed single-use recovery codes shown once at setup (and again on regenerate); 5-failed-attempt lockout (15 min) on top of the route throttle. Operator escape hatch: `php artisan admin:reset-2fa {email}`. QR rendered via the existing `App\Services\QrService`. Verified by tests (including both fixed bypasses) + a live end-to-end HTTP smoke test against the production server.

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
- ✅ **Duplicate-registration guard**: onboarding blocks completion via three **independent** checks against already-completed organizations - any single hit flags a possible duplicate: (1) company name + country (case/whitespace-insensitive; a name is unique per country), (2) registration number + country (formatting-insensitive on the number; country-scoped because national registries can coincidentally reuse the same digits across countries), (3) VAT number alone (canonical, already country-prefixed). The error surfaces on whichever field triggered the match. (2026-07-01: replaced an earlier version that required name + registration number + VAT to *all* match together, which let an exact-name duplicate through if the registration number or VAT differed; a follow-up review flagged that registration number was initially matched globally instead of per-country, which was fixed the same day.) Repeated blocked attempts (default: 4th) suspend the **email account** (user-level `suspended_at`), gate it to `/app/support` (contact form: phone/email/company/message), and alert `SUPPORT_EMAIL` with an admin-only reason (including which check matched). Admin lifts the suspension from the org detail page. VAT is canonicalized + validated **server-side** (`App\Support\VatNumber`) so entry is not browser-bypassable; a backfill migration canonicalizes any pre-existing `vat_id` values. External review: 9.1/10. See `PRE_LAUNCH_CHECKLIST.md` for the `dev@vdisain.lv` placeholders to swap before launch.

### DPP product layer
- ✅ Generic template seeded (`TemplateSeeder`); product created behind the passport wizard
- ✅ DPP create + edit driven by the template field-schema (plain HTML form)
- ✅ Draft -> Publish workflow (`PassportPublisher`): required-field gate, **server-side quota enforcement (concurrency-safe: per-org advisory lock + quota re-check inside the transaction)**, master data locked (version + canonical SHA-256 hash), retention date set. Verified by tests.
- ✅ **Post-publish corrections** (`PassportPublisher::publishCorrection`): editor+ opens a correction draft (new unlocked version copied from live; public page unaffected), edits it, publishes it through the same gate (required fields, suspended block, advisory lock; no quota check - published count unchanged) or discards it. Public identity never rotates (public_id / tier tokens / published_at / retention). Snapshot swap + `audit_log` row (from/to version + hashes) in one transaction. Version history table on the passport page. Verified by tests (11, `PassportCorrectionTest`).
- ✅ Identifiers: GS1 Digital Link (`/01/{GTIN}/21/{serial}`) + fallback UUID (`/p/{uuid}`) via `Passport::resolverUrl()`
- ✅ QR generation (SVG, vector/print-scalable) via `bacon/bacon-qr-code`
- ✅ Print-ready PNG export (Imagick backend, `?format=png`, 240-2400 px clamp) alongside the SVG

### Public viewer / resolver
- ✅ Resolver route handling both URL shapes + content negotiation (HTML vs JSON-LD)
- ✅ `published_snapshots` built on publish (`SnapshotBuilder`, all 5 audiences: consumer, repairer, recycler, authority, full); resolver reads ONE snapshot row
- ✅ Consumer view (plain HTML, no auth); drafts/unknown ids return 404
- ✅ **Tiered access links** (repairer/recycler/authority): `/p/{public_id}/{audience}/{token}`, a durable per-audience token (`passport_access_tokens`, `App\Models\PassportAccessToken`) issued at publish time. The audience segment is never trusted alone - a valid token only works under its own audience's URL slot. Org users see/copy/regenerate each tier's link from the passport show page (editor+ can regenerate, same gate as publish). Backfilled for passports published before this existed. Verified by tests.
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
- ✅ **Delete a user** (support/testing tool, from the org detail page's member list): also deletes any organization where they're the sole member, since the duplicate-registration guard matches on org fields (name+country, registration number, VAT), not the user - an orphaned org would otherwise still block re-onboarding with the same details. Refuses to delete the last owner of an org that has other members (reassign first). Also refuses to touch a sole-member org that has **published passports** (fixed 2026-07-01, external review): published DPPs carry a permanent public resolver link, so this tool must never be able to delete one out from under a live QR code. An admin can't delete their own account. Verified by tests.
- ✅ **DB-driven plans** (`plans` table + `Plan` model): create/edit plans, prices, quotas (null = unlimited), public/active flags, custom non-public plans. `Organization::publishedQuota()` precedence: per-org override -> DB plan -> config fallback. Verified by tests.
- ✅ **QR / passport browser** (`/admin/passports`): platform-wide, paginated (20/page, never loads everything), filter by organization + status, search by public id / GTIN / serial / product name, lazy-loaded QR thumbnails. Verified by tests.
- ✅ **Impersonation** ("log in as" a user, with audit): from the org detail page's member list, an admin can temporarily become a regular user (never another admin, never themselves). Requires a fresh TOTP/recovery step-up immediately before every start, re-checked again right before the swap (closes a TOCTOU window). Every start/stop writes to `audit_log` (actor/target/meta). Persistent "Stop impersonating" banner on every `/app/*` page while active. Verified by tests + a live end-to-end smoke test.
- ⏸️ Published-DPP lifecycle tools (archive, legal holds, migrations)

## Slice 3 - Compliance depth  ⏸️
- ✅ Tiered access views (repairer / recycler / authority) - see Public viewer / resolver above. Customs specifically is not modeled as its own audience yet.
- ⏸️ EU Registry push + commodity code
- ✅ Full versioning UI + audit trail surface (post-publish corrections + version history table on the passport page; filterable admin audit-trail browser at `/admin/audit`)
- ⏸️ Persistence/backup tier (cold archive export to object storage, 3rd-party backup copy)
- ✅ i18n on the **public layer** (LV/EN, per-locale snapshots + resolver language negotiation + translated chrome/labels; adding a Member-State language is config + lang file + label translations + snapshot rebuild). The authenticated app UI (`/app`, `/admin`) remains English-only for now.

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
- 🔨 GDPR: **export + erasure done** (self-service `/app/privacy` + shared `AccountEraser` behind the admin tool); the DPA document itself is legal text still owed by the owner
- ⏸️ Legal role decision: generic host vs. ESPR "DPP service provider"

---

## Open decisions still needed from product owner
- ⬜ **Lapse policy** for published DPPs after subscription ends (blocking for Slice 2 launch).
- ⬜ Legal role: generic host vs. ESPR DPP service provider (affects ToS, not code yet).
- ⬜ Template field examples per category (owner will provide; slot into template schema).
- ⬜ Final domains: WordPress site domain vs. platform domain/subdomain.
