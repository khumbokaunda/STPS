<?php
declare(strict_types=1);

/**
 * Validator  --  boundary input validation (build spec section 19: all external
 * input validated and typed at the boundary; reject, do not coerce).
 *
 * Every method throws ValidationException on invalid input. Callers convert that
 * to a deterministic 400 response without leaking internals.
 */
final class ValidationException extends RuntimeException
{
}

final class Validator
{
    public static function requireString($v, string $field, int $max = 255, int $min = 1): string
    {
        if (!is_string($v)) {
            throw new ValidationException("{$field} must be a string.");
        }
        $len = mb_strlen($v);
        if ($len < $min || $len > $max) {
            throw new ValidationException("{$field} length out of range.");
        }
        return $v;
    }

    public static function requireEnum($v, string $field, array $allowed): string
    {
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            throw new ValidationException("{$field} is not an allowed value.");
        }
        return $v;
    }

    /** Validate a decimal string with an exact scale; returns the normalized form. */
    public static function requireDecimal($v, string $field, int $scale): string
    {
        if (!is_string($v) && !is_int($v)) {
            throw new ValidationException("{$field} must be a decimal string.");
        }
        $s = (string) $v;
        if (!preg_match('/^-?\d{1,30}(\.\d{1,10})?$/', $s)) {
            throw new ValidationException("{$field} is not a valid decimal.");
        }
        return Canonicalizer::decimalString($s, $scale);
    }

    /** Validate a 32-char lowercase hex UUID and return it lowercased. */
    public static function requireUuidHex($v, string $field): string
    {
        if (!is_string($v)) {
            throw new ValidationException("{$field} must be a uuid hex string.");
        }
        $v = strtolower($v);
        if (!preg_match('/^[0-9a-f]{32}$/', $v)) {
            throw new ValidationException("{$field} is not a 32-char hex uuid.");
        }
        return $v;
    }

    /** Validate base64 and return decoded bytes. */
    public static function requireBase64($v, string $field, int $maxBytes = 8192): string
    {
        if (!is_string($v)) {
            throw new ValidationException("{$field} must be base64.");
        }
        $decoded = base64_decode($v, true);
        if ($decoded === false || strlen($decoded) > $maxBytes) {
            throw new ValidationException("{$field} is not valid base64.");
        }
        return $decoded;
    }

    public static function requireEmail($v, string $field): string
    {
        if (!is_string($v) || !filter_var($v, FILTER_VALIDATE_EMAIL) || mb_strlen($v) > 254) {
            throw new ValidationException("{$field} is not a valid email.");
        }
        return $v;
    }

    public static function requireInt($v, string $field, int $min = 0, int $max = PHP_INT_MAX): int
    {
        if (is_string($v) && preg_match('/^-?\d+$/', $v)) {
            $v = (int) $v;
        }
        if (!is_int($v) || $v < $min || $v > $max) {
            throw new ValidationException("{$field} is not a valid integer.");
        }
        return $v;
    }

    public static function optional($v, callable $fn, string $field, ...$args)
    {
        if ($v === null || $v === '') {
            return null;
        }
        return $fn($v, $field, ...$args);
    }
}
