<?php
declare(strict_types=1);

/**
 * Db  --  PDO factory and transaction helper (build spec: all access through PDO
 * with prepared statements; no string-built SQL, ever).
 *
 * PDO is configured to throw on error, to NOT emulate prepares (so real server
 * side prepared statements are used), and to fetch associative arrays.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function connect(array $dbConfig): PDO
    {
        $pdo = new PDO(
            $dbConfig['dsn'],
            $dbConfig['user'],
            $dbConfig['password'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]
        );
        // Force UTC on the session so NOW()/CURRENT_TIMESTAMP and comparisons are UTC.
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }

    /** Shared application connection (proc_app). */
    public static function app(): PDO
    {
        if (self::$pdo === null) {
            $config = require self::configPath();
            self::$pdo = self::connect($config['db']);
        }
        return self::$pdo;
    }

    public static function setConnection(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    private static function configPath(): string
    {
        $path = dirname(__DIR__, 2) . '/config/config.php';
        if (!file_exists($path)) {
            $path = dirname(__DIR__, 2) . '/config/config.example.php';
        }
        return $path;
    }

    /**
     * Run $fn inside a transaction, committing on success and rolling back on any
     * exception. Nested calls join the existing transaction (savepoint-free: the
     * outermost owns commit/rollback).
     */
    public static function transaction(PDO $pdo, callable $fn)
    {
        if ($pdo->inTransaction()) {
            return $fn($pdo);
        }
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
