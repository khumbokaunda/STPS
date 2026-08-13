<?php
declare(strict_types=1);

/**
 * Request / Response helpers (build spec section 19: deterministic error
 * handling; never leak stack traces or SQL to the client; output encoding on
 * every value rendered into HTML; HTTPS enforced).
 */
final class Request
{
    /** Decode a JSON request body into an associative array. */
    public static function json(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            return [];
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Response::error(400, 'invalid_json');
        }
        return is_array($data) ? $data : [];
    }

    public static function method(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443)
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null) === 'https');
    }
}

final class Response
{
    /** Send a JSON success response and terminate. */
    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        self::securityHeaders();
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** Send a deterministic error (no internal detail) and terminate. */
    public static function error(int $status, string $code, ?string $publicMessage = null): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        self::securityHeaders();
        echo json_encode([
            'error'   => $code,
            'message' => $publicMessage ?? self::defaultMessage($status),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** HTML-escape a value for safe rendering (output encoding). */
    public static function h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; font-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'");
        header('Strict-Transport-Security: max-age=63072000; includeSubDomains');
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Bad request.',
            401 => 'Authentication required.',
            403 => 'Forbidden.',
            404 => 'Not found.',
            409 => 'Conflict.',
            429 => 'Too many requests.',
            default => 'Request could not be processed.',
        };
    }
}
