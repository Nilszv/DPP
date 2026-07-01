<?php

namespace App\Support;

/**
 * Server-side canonicalization + validation of VAT numbers, driven by config/tax.php.
 * The country-aware form does this in JS for UX, but the server must not trust it: the
 * duplicate-registration guard and stored value both depend on a single canonical form so
 * a hand-crafted POST cannot slip a formatting variant past the duplicate check.
 */
class VatNumber
{
    /**
     * Canonical form: uppercase, alphanumeric-only, with the country's VAT prefix ensured.
     * "lv 4000 3011 283", "40003011283" and "LV40003011283" all canonicalize to
     * "LV40003011283". Returns null when empty.
     */
    public static function canonical(?string $country, ?string $raw): ?string
    {
        $value = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $raw));

        if ($value === '') {
            return null;
        }

        $prefix = strtoupper((string) config("tax.countries.$country.vat_prefix"));

        if ($prefix !== '' && ! str_starts_with($value, $prefix)) {
            $value = $prefix.$value;
        }

        return $value;
    }

    /**
     * Whether the VAT is acceptable for the country. Empty is allowed (requiredness is a
     * separate rule); a country with no configured format accepts anything; otherwise the
     * national part (after the prefix) must match the configured pattern.
     */
    public static function isValid(?string $country, ?string $raw): bool
    {
        $canonical = self::canonical($country, $raw);

        if ($canonical === null) {
            return true;
        }

        $pattern = config("tax.countries.$country.vat_pattern");
        $prefix = strtoupper((string) config("tax.countries.$country.vat_prefix"));

        if (! $pattern || $prefix === '') {
            return true;
        }

        $national = str_starts_with($canonical, $prefix)
            ? substr($canonical, strlen($prefix))
            : $canonical;

        return (bool) preg_match('/^('.$pattern.')$/', $national);
    }

    /** Whether the given country operates a VAT number (has a prefix configured). */
    public static function countryHasVat(?string $country): bool
    {
        return ! empty(config("tax.countries.$country.vat_prefix"));
    }
}
