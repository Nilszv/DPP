<?php

/*
 | Public passport page chrome (the buyer-facing layer). Field labels are NOT here -- they
 | live per-locale in the template field_schema and are baked into snapshots at publish time.
 */
return [
    'digital_product_passport' => 'Digital Product Passport',
    'viewing' => 'Viewing: :audience information',
    'no_details' => 'No public details are available for this product.',
    'identifier' => 'Identifier',
    'verified_content_hash' => 'Verified content hash',
    'language' => 'Language',

    'audiences' => [
        'consumer' => 'Consumer',
        'repairer' => 'Repairer',
        'recycler' => 'Recycler',
        'authority' => 'Authority',
        'full' => 'Full',
    ],

    'locales' => [
        'en' => 'English',
        'lv' => 'Latviešu',
    ],
];
