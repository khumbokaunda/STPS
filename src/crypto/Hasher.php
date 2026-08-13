<?php
declare(strict_types=1);

/**
 * Hasher  --  the single place SHA-256 is computed on the server.
 *
 * Build spec: general hashing is SHA-256. No inline hashing scattered through the
 * services (spec section 19). The 0x1F unit-separator field joining used by the
 * ledger and commitment constructions lives here so both server and verifier use
 * the identical byte layout.
 */
final class Hasher
{
    public const US = "\x1F"; // ASCII unit separator (0x1F) used as field delimiter

    /** Raw 32-byte SHA-256 digest of the given bytes. */
    public static function sha256(string $bytes): string
    {
        return hash('sha256', $bytes, true);
    }

    /** Lowercase hex SHA-256 digest. */
    public static function sha256Hex(string $bytes): string
    {
        return hash('sha256', $bytes, false);
    }

    /**
     * Join fields with the 0x1F unit separator and SHA-256 the result.
     * Each field is used exactly as its raw bytes; callers must pass binary
     * digests as 32 raw bytes and text fields as UTF-8.
     */
    public static function sha256Fields(array $fields): string
    {
        return self::sha256(implode(self::US, $fields));
    }

    /**
     * Domain-separated digest to sign (build spec section 9):
     *   SHA-256( domain_separator_bytes || 0x1F || canonical_json_bytes ).
     */
    public static function toSign(string $domainSeparator, string $canonicalJson): string
    {
        return self::sha256($domainSeparator . self::US . $canonicalJson);
    }

    /** Constant-time comparison of two byte strings. */
    public static function equals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }
}
