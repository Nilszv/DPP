<?php

/*
 | Country list + standard VAT rate (%) per country. Drives the onboarding country dropdown
 | and the tax rate applied later at billing time. Easy to edit: add/adjust a row here.
 | EU-27 standard rates plus a few common non-EU countries (0 = no VAT applied by us).
 */
return [
    'countries' => [
        'AT' => ['name' => 'Austria', 'vat' => 20.0],
        'BE' => ['name' => 'Belgium', 'vat' => 21.0],
        'BG' => ['name' => 'Bulgaria', 'vat' => 20.0],
        'HR' => ['name' => 'Croatia', 'vat' => 25.0],
        'CY' => ['name' => 'Cyprus', 'vat' => 19.0],
        'CZ' => ['name' => 'Czechia', 'vat' => 21.0],
        'DK' => ['name' => 'Denmark', 'vat' => 25.0],
        'EE' => ['name' => 'Estonia', 'vat' => 22.0],
        'FI' => ['name' => 'Finland', 'vat' => 25.5],
        'FR' => ['name' => 'France', 'vat' => 20.0],
        'DE' => ['name' => 'Germany', 'vat' => 19.0],
        'GR' => ['name' => 'Greece', 'vat' => 24.0],
        'HU' => ['name' => 'Hungary', 'vat' => 27.0],
        'IE' => ['name' => 'Ireland', 'vat' => 23.0],
        'IT' => ['name' => 'Italy', 'vat' => 22.0],
        'LV' => ['name' => 'Latvia', 'vat' => 21.0],
        'LT' => ['name' => 'Lithuania', 'vat' => 21.0],
        'LU' => ['name' => 'Luxembourg', 'vat' => 17.0],
        'MT' => ['name' => 'Malta', 'vat' => 18.0],
        'NL' => ['name' => 'Netherlands', 'vat' => 21.0],
        'PL' => ['name' => 'Poland', 'vat' => 23.0],
        'PT' => ['name' => 'Portugal', 'vat' => 23.0],
        'RO' => ['name' => 'Romania', 'vat' => 19.0],
        'SK' => ['name' => 'Slovakia', 'vat' => 20.0],
        'SI' => ['name' => 'Slovenia', 'vat' => 22.0],
        'ES' => ['name' => 'Spain', 'vat' => 21.0],
        'SE' => ['name' => 'Sweden', 'vat' => 25.0],
        'GB' => ['name' => 'United Kingdom', 'vat' => 20.0],
        'NO' => ['name' => 'Norway', 'vat' => 25.0],
        'CH' => ['name' => 'Switzerland', 'vat' => 8.1],
        'US' => ['name' => 'United States', 'vat' => 0.0],
    ],
];
