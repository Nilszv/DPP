<?php

return [
    /*
     | Base URL that scanned QR codes encode and resolve to (the public passport viewer).
     | MUST be stable forever in production -- QR carriers are permanent and cannot be reprinted.
     */
    'passport_base_url' => env('PASSPORT_BASE_URL', env('APP_URL', 'http://localhost:8000')),

    /* Default Member-State language for the public passport layer. */
    'default_locale' => env('PASSPORT_DEFAULT_LOCALE', 'lv'),

    /* Keyed HMAC secret for hashing scanner IPs (GDPR). Rotating it is intentional. */
    'scan_ip_hmac_key' => env('SCAN_IP_HMAC_KEY', env('APP_KEY', '')),

    /* Where "Contact sales" messages are delivered (e.g. downgrade requests, custom plans). */
    'sales_email' => env('SALES_EMAIL', 'dev@vdisain.lv'),

    /* Audiences the tiered resolver/snapshots support. Only 'consumer' renders in Slice 1. */
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
