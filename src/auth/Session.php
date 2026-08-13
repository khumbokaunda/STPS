<?php
declare(strict_types=1);

/**
 * Session  --  secure session configuration and lifecycle (build spec section 19):
 * cookie_secure, cookie_httponly, cookie_samesite Strict, id regenerated on
 * privilege change, idle and absolute timeouts.
 */
final class Session
{
    public static function start(array $sessionConfig): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name($sessionConfig['name'] ?? 'STPSSESS');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $sessionConfig['cookie_secure'] ?? true,
            'httponly' => $sessionConfig['cookie_httponly'] ?? true,
            'samesite' => $sessionConfig['cookie_samesite'] ?? 'Strict',
        ]);
        session_start();
        self::enforceTimeouts(
            (int) ($sessionConfig['idle_timeout'] ?? 900),
            (int) ($sessionConfig['absolute_timeout'] ?? 28800)
        );
    }

    private static function enforceTimeouts(int $idle, int $absolute): void
    {
        $now = time();
        $created = $_SESSION['_created_at'] ?? null;
        $seen = $_SESSION['_last_seen'] ?? null;
        if ($created !== null && ($now - $created) > $absolute) {
            self::destroy();
            return;
        }
        if ($seen !== null && ($now - $seen) > $idle) {
            self::destroy();
            return;
        }
        $_SESSION['_created_at'] = $created ?? $now;
        $_SESSION['_last_seen'] = $now;
    }

    /** Establish an authenticated session; regenerate id (privilege change). */
    public static function login(string $userId16): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = bin2hex($userId16);
        $_SESSION['_created_at'] = time();
        $_SESSION['_last_seen'] = time();
        Csrf::rotate();
    }

    public static function userId(): ?string
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        return hex2bin($_SESSION['user_id']);
    }

    public static function require(): string
    {
        $id = self::userId();
        if ($id === null) {
            Response::error(401, 'not_authenticated');
        }
        return $id;
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
