<?php

return [
    /*
     | Base URL that scanned QR codes encode and resolve to (the public passport viewer).
     | MUST be stable forever in production -- QR carriers are permanent and cannot be reprinted.
     */
    'passport_base_url' => env('PASSPORT_BASE_URL', env('APP_URL', 'http://localhost:8000')),

    /* Default Member-State language for the public passport layer. */
    'default_locale' => env('PASSPORT_DEFAULT_LOCALE', 'lv'),

    /*
     | Locales the public layer serves: snapshot rows are pre-built per locale x audience at
     | publish/correction time (labels + page chrome localized; the manufacturer's field
     | VALUES are served as entered -- regulated data is never machine-translated). Adding a
     | buyer Member-State language = add it here + lang/{locale}/public.php + template label
     | translations, then rebuild snapshots. A passport's own default_locale is always built
     | even if it is missing from this list.
     */
    'locales' => array_filter(array_map(
        'trim',
        explode(',', (string) env('PASSPORT_LOCALES', 'lv,en'))
    )),

    /* Keyed HMAC secret for hashing scanner IPs (GDPR). Rotating it is intentional. */
    'scan_ip_hmac_key' => env('SCAN_IP_HMAC_KEY', env('APP_KEY', '')),

    /* Where "Contact sales" messages are delivered (e.g. downgrade requests, custom plans). */
    'sales_email' => env('SALES_EMAIL', 'dev@vdisain.lv'),

    /* Where support / abuse alerts are delivered (duplicate-registration suspensions, the
     | in-app support form). Placeholder until the real support inbox is set -- see
     | docs/PRE_LAUNCH_CHECKLIST.md. */
    'support_email' => env('SUPPORT_EMAIL', 'dev@vdisain.lv'),

    /* Blocked duplicate-registration attempts allowed before the email is auto-suspended.
     | Errors are shown for attempts 1..N; exceeding N (the N+1th) suspends the account. */
    'onboarding_duplicate_max_attempts' => (int) env('ONBOARDING_DUPLICATE_MAX_ATTEMPTS', 3),

    /* Audiences the tiered resolver/snapshots support. 'full' is internal/non-distributed --
     | only consumer/repairer/recycler/authority are reachable via a public URL. */
    'audiences' => ['consumer', 'repairer', 'recycler', 'authority', 'full'],

    /*
     | Emails exempt from the send-code throttle and cooldown (for testing only).
     | Comma-separated in DPP_UNTHROTTLED_EMAILS. Keep this empty in production.
     */
    'unthrottled_emails' => array_filter(array_map(
        'trim',
        explode(',', (string) env('DPP_UNTHROTTLED_EMAILS', ''))
    )),
];
