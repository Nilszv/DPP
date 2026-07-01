# Pre-launch checklist

Things that MUST be reviewed and set correctly before the platform goes live. Kept here so
nothing placeholder ships to production. Last updated: 2026-07-01.

Legend: [ ] not done - [x] done

---

## 1. Contact / support email placeholders (dev@vdisain.lv)

During development every outbound "human" email is sent to the developer inbox
`dev@vdisain.lv`. Before launch, point each of these at the REAL support / sales inboxes.
All are driven by env vars, so this is a config change (no code edit needed).

- [ ] `SALES_EMAIL` - "Contact sales" messages (downgrade requests, custom plans).
      - Config: `config/dpp.php` -> `sales_email` (default `dev@vdisain.lv`).
      - Used by: `App\Http\Controllers\ContactController@sendSales` (the "Contact sales" modal
        on `/app/billing`).
- [ ] `SUPPORT_EMAIL` - support / abuse alerts.
      - Config: `config/dpp.php` -> `support_email` (default `dev@vdisain.lv`).
      - Used by:
        - `App\Http\Controllers\OnboardingController` - auto-alert when an email account is
          suspended for repeated duplicate registration (`DuplicateRegistrationAlert`).
        - `App\Http\Controllers\SupportController@send` - the in-app support form on
          `/app/support` (`SupportRequestMail`).
- [ ] `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` - the From on ALL outbound mail
      (`.env` -> currently `no-reply@example.com` in `.env.example`). Set to the real sender.

Where the forms live (so we know every surface that reaches these inboxes):
- `/app/billing` - "Contact sales" modal -> `SALES_EMAIL`.
- `/app/support` - support form (phone, email, company, message) -> `SUPPORT_EMAIL`.
- Onboarding duplicate-suspension auto-alert (no form; triggered server-side) -> `SUPPORT_EMAIL`.

Tuning knob:
- [ ] `ONBOARDING_DUPLICATE_MAX_ATTEMPTS` (default 3) - blocked duplicate attempts allowed
      before the email is auto-suspended (suspended on attempt N+1). Confirm the value.

## 2. Environment / safety

- [ ] Flip `APP_DEBUG=false` and `APP_ENV=production` (currently local for dev visibility;
      see DEPLOYMENT.md and STATUS.md).
- [ ] Run `php artisan migrate` on the production database (includes the user-suspension +
      duplicate-attempts columns from `2026_07_01_100000_...`).
- [ ] Confirm `DPP_UNTHROTTLED_EMAILS` is EMPTY in production (test-only throttle bypass).
- [ ] Move transactional mail to the queue (currently sent synchronously).

## 3. Domains / URLs

- [ ] `PASSPORT_BASE_URL` is the permanent public resolver host (QR carriers are permanent).
- [ ] Final marketing (WordPress) domain vs. platform domain decided and DNS set.

---

Notes:
- This list is intentionally about launch-blocking configuration, not features. Feature status
  lives in `STATUS.md`.
