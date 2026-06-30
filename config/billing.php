<?php

return [
    /*
     | Billing driver. 'manual' switches plans with no payment (no Stripe account needed yet).
     | Set to 'stripe' once a Stripe account + Cashier are wired up; the rest of the app
     | (plans, quota, UI) does not change.
     */
    'driver' => env('BILLING_DRIVER', 'manual'),

    'currency' => 'EUR',

    /*
     | Plan catalogue. Single source of truth for quotas and pricing. published_quota uses
     | PHP_INT_MAX for "unlimited / custom".
     */
    'plans' => [
        'free' => [
            'name' => 'Free',
            'price' => 0,
            'interval' => null,
            'published_quota' => 1,
            'team_quota' => 1,
        ],
        'medium' => [
            'name' => 'Medium',
            'price' => 9,
            'interval' => 'month',
            'published_quota' => 5,
            'team_quota' => 3,
        ],
        'commercial' => [
            'name' => 'Commercial',
            'price' => null,           // custom / sales-led
            'interval' => 'custom',
            'published_quota' => PHP_INT_MAX,
            'team_quota' => PHP_INT_MAX,
        ],
    ],

    /*
     | Stripe placeholders. Empty until a Stripe account exists. Price IDs map a plan key to a
     | Stripe Price; filled in when StripeBillingProvider is added.
     */
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'prices' => [
            'medium' => env('STRIPE_PRICE_MEDIUM'),
        ],
    ],
];
