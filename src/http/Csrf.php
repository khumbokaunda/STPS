<?php
declare(strict_types=1);

/**
 * Csrf  --  synchronizer-token CSRF protection (build spec sections 4, 12, 19).
 * Every state-changing request requires a valid token. Deny by default.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    /** Constant-time validation of a submitted token. */
    public static function validate(?string $submitted): bool
    {
        if (empty($_SESSION[self::SESSION_KEY]) || $submitted === null || $submitted === '') {
            return false;
        }
        return hash_equals($_SESSION[self::SESSION_KEY], $submitted);
    }

    /** Rotate the token (e.g. after privilege change). */
    public static function rotate(): void
    {
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
    }
}
