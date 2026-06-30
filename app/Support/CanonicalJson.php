<?php

namespace App\Support;

/**
 * Canonical JSON encoding for reproducible content hashing (RFC 8785-style).
 *
 * Object keys are sorted recursively and output is compact (no insignificant whitespace),
 * so the same logical data always produces the same bytes -> the same SHA-256. This is what
 * makes a passport version's content_hash verifiable by anyone who has the data.
 */
final class CanonicalJson
{
    /** Recursively sort associative-array keys, then JSON-encode compactly. */
    public static function encode(mixed $data): string
    {
        return json_encode(
            self::canonicalize($data),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /** SHA-256 hex digest of the canonical encoding. */
    public static function hash(mixed $data): string
    {
        return hash('sha256', self::encode($data));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {
            // List (sequential int keys) -> preserve order; associative -> sort by key.
            if (array_is_list($value)) {
                return array_map([self::class, 'canonicalize'], $value);
            }
            ksort($value);
            return array_map([self::class, 'canonicalize'], $value);
        }

        return $value;
    }
}
