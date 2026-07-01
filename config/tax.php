<?php

/*
 | Country list per country, driving three onboarding concerns from ONE place:
 |   - vat        standard VAT rate (%), applied later at billing time
 |   - vat_prefix VAT registration country code shown before the number (null = free text).
 |                Usually the ISO code, with known exceptions: Greece = EL, Switzerland = CHE.
 |   - vat_pattern JavaScript regex source (no delimiters) the national part after the prefix
 |                must match. null = no format check (free text) for that country.
 |   - dial_code  international dialing code for the contact phone
 |   - phone_min / phone_max  inclusive digit-count range for the national part of the phone
 |                (dialing code excluded). Ranges are deliberately generous so valid numbers
 |                are never rejected. null = no digit-count check.
 |
 | VAT/phone validation is client-side only (see the company-fields partial). Easy to edit:
 | adjust a row here and both the onboarding and organization forms follow.
 | EU-27 standard rates plus a few common non-EU countries (0 = no VAT applied by us).
 */
return [
    'countries' => [
        'AT' => ['name' => 'Austria', 'vat' => 20.0, 'vat_prefix' => 'AT', 'vat_pattern' => 'U\d{8}', 'dial_code' => '+43', 'phone_min' => 7, 'phone_max' => 13],
        'BE' => ['name' => 'Belgium', 'vat' => 21.0, 'vat_prefix' => 'BE', 'vat_pattern' => '\d{10}', 'dial_code' => '+32', 'phone_min' => 8, 'phone_max' => 9],
        'BG' => ['name' => 'Bulgaria', 'vat' => 20.0, 'vat_prefix' => 'BG', 'vat_pattern' => '\d{9,10}', 'dial_code' => '+359', 'phone_min' => 8, 'phone_max' => 9],
        'HR' => ['name' => 'Croatia', 'vat' => 25.0, 'vat_prefix' => 'HR', 'vat_pattern' => '\d{11}', 'dial_code' => '+385', 'phone_min' => 8, 'phone_max' => 9],
        'CY' => ['name' => 'Cyprus', 'vat' => 19.0, 'vat_prefix' => 'CY', 'vat_pattern' => '\d{8}[A-Z]', 'dial_code' => '+357', 'phone_min' => 8, 'phone_max' => 8],
        'CZ' => ['name' => 'Czechia', 'vat' => 21.0, 'vat_prefix' => 'CZ', 'vat_pattern' => '\d{8,10}', 'dial_code' => '+420', 'phone_min' => 9, 'phone_max' => 9],
        'DK' => ['name' => 'Denmark', 'vat' => 25.0, 'vat_prefix' => 'DK', 'vat_pattern' => '\d{8}', 'dial_code' => '+45', 'phone_min' => 8, 'phone_max' => 8],
        'EE' => ['name' => 'Estonia', 'vat' => 22.0, 'vat_prefix' => 'EE', 'vat_pattern' => '\d{9}', 'dial_code' => '+372', 'phone_min' => 7, 'phone_max' => 8],
        'FI' => ['name' => 'Finland', 'vat' => 25.5, 'vat_prefix' => 'FI', 'vat_pattern' => '\d{8}', 'dial_code' => '+358', 'phone_min' => 7, 'phone_max' => 11],
        'FR' => ['name' => 'France', 'vat' => 20.0, 'vat_prefix' => 'FR', 'vat_pattern' => '[A-Z0-9]{2}\d{9}', 'dial_code' => '+33', 'phone_min' => 9, 'phone_max' => 9],
        'DE' => ['name' => 'Germany', 'vat' => 19.0, 'vat_prefix' => 'DE', 'vat_pattern' => '\d{9}', 'dial_code' => '+49', 'phone_min' => 7, 'phone_max' => 11],
        'GR' => ['name' => 'Greece', 'vat' => 24.0, 'vat_prefix' => 'EL', 'vat_pattern' => '\d{9}', 'dial_code' => '+30', 'phone_min' => 10, 'phone_max' => 10],
        'HU' => ['name' => 'Hungary', 'vat' => 27.0, 'vat_prefix' => 'HU', 'vat_pattern' => '\d{8}', 'dial_code' => '+36', 'phone_min' => 8, 'phone_max' => 9],
        'IE' => ['name' => 'Ireland', 'vat' => 23.0, 'vat_prefix' => 'IE', 'vat_pattern' => '[A-Z0-9]{8,9}', 'dial_code' => '+353', 'phone_min' => 7, 'phone_max' => 9],
        'IT' => ['name' => 'Italy', 'vat' => 22.0, 'vat_prefix' => 'IT', 'vat_pattern' => '\d{11}', 'dial_code' => '+39', 'phone_min' => 9, 'phone_max' => 11],
        'LV' => ['name' => 'Latvia', 'vat' => 21.0, 'vat_prefix' => 'LV', 'vat_pattern' => '\d{11}', 'dial_code' => '+371', 'phone_min' => 8, 'phone_max' => 8],
        'LT' => ['name' => 'Lithuania', 'vat' => 21.0, 'vat_prefix' => 'LT', 'vat_pattern' => '\d{9}|\d{12}', 'dial_code' => '+370', 'phone_min' => 8, 'phone_max' => 8],
        'LU' => ['name' => 'Luxembourg', 'vat' => 17.0, 'vat_prefix' => 'LU', 'vat_pattern' => '\d{8}', 'dial_code' => '+352', 'phone_min' => 6, 'phone_max' => 9],
        'MT' => ['name' => 'Malta', 'vat' => 18.0, 'vat_prefix' => 'MT', 'vat_pattern' => '\d{8}', 'dial_code' => '+356', 'phone_min' => 8, 'phone_max' => 8],
        'NL' => ['name' => 'Netherlands', 'vat' => 21.0, 'vat_prefix' => 'NL', 'vat_pattern' => '\d{9}B\d{2}', 'dial_code' => '+31', 'phone_min' => 9, 'phone_max' => 9],
        'PL' => ['name' => 'Poland', 'vat' => 23.0, 'vat_prefix' => 'PL', 'vat_pattern' => '\d{10}', 'dial_code' => '+48', 'phone_min' => 9, 'phone_max' => 9],
        'PT' => ['name' => 'Portugal', 'vat' => 23.0, 'vat_prefix' => 'PT', 'vat_pattern' => '\d{9}', 'dial_code' => '+351', 'phone_min' => 9, 'phone_max' => 9],
        'RO' => ['name' => 'Romania', 'vat' => 19.0, 'vat_prefix' => 'RO', 'vat_pattern' => '\d{2,10}', 'dial_code' => '+40', 'phone_min' => 9, 'phone_max' => 9],
        'SK' => ['name' => 'Slovakia', 'vat' => 20.0, 'vat_prefix' => 'SK', 'vat_pattern' => '\d{10}', 'dial_code' => '+421', 'phone_min' => 9, 'phone_max' => 9],
        'SI' => ['name' => 'Slovenia', 'vat' => 22.0, 'vat_prefix' => 'SI', 'vat_pattern' => '\d{8}', 'dial_code' => '+386', 'phone_min' => 8, 'phone_max' => 8],
        'ES' => ['name' => 'Spain', 'vat' => 21.0, 'vat_prefix' => 'ES', 'vat_pattern' => '[A-Z0-9]\d{7}[A-Z0-9]', 'dial_code' => '+34', 'phone_min' => 9, 'phone_max' => 9],
        'SE' => ['name' => 'Sweden', 'vat' => 25.0, 'vat_prefix' => 'SE', 'vat_pattern' => '\d{12}', 'dial_code' => '+46', 'phone_min' => 7, 'phone_max' => 10],
        'GB' => ['name' => 'United Kingdom', 'vat' => 20.0, 'vat_prefix' => 'GB', 'vat_pattern' => '\d{9}|\d{12}', 'dial_code' => '+44', 'phone_min' => 9, 'phone_max' => 10],
        'NO' => ['name' => 'Norway', 'vat' => 25.0, 'vat_prefix' => 'NO', 'vat_pattern' => '\d{9}', 'dial_code' => '+47', 'phone_min' => 8, 'phone_max' => 8],
        'CH' => ['name' => 'Switzerland', 'vat' => 8.1, 'vat_prefix' => 'CHE', 'vat_pattern' => '\d{9}', 'dial_code' => '+41', 'phone_min' => 9, 'phone_max' => 9],
        'US' => ['name' => 'United States', 'vat' => 0.0, 'vat_prefix' => null, 'vat_pattern' => null, 'dial_code' => '+1', 'phone_min' => 10, 'phone_max' => 10],
    ],
];
