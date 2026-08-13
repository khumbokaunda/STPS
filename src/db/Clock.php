<?php
declare(strict_types=1);

require_once __DIR__ . '/../crypto/Canonicalizer.php';

/**
 * Clock  --  the single source of "now" (build spec: server clock is
 * authoritative for deadlines; all timestamps UTC). Tests can override it.
 */
final class Clock
{
    private static ?DateTimeImmutable $frozen = null;

    public static function now(): DateTimeImmutable
    {
        return self::$frozen ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** Canonical ISO-8601 UTC millisecond string for the current instant. */
    public static function nowIso(): string
    {
        return Canonicalizer::toIso8601Utc(self::now());
    }

    public static function iso(DateTimeInterface $dt): string
    {
        return Canonicalizer::toIso8601Utc($dt);
    }

    /** MySQL DATETIME(6) UTC string, e.g. 2026-08-13 07:30:00.000000. */
    public static function mysql(DateTimeInterface $dt): string
    {
        return (DateTimeImmutable::createFromInterface($dt))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }

    /** Parse a MySQL DATETIME(6) UTC string to an immutable UTC datetime. */
    public static function fromMysql(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s . ' UTC');
    }

    // Test hooks.
    public static function freeze(DateTimeImmutable $at): void
    {
        self::$frozen = $at->setTimezone(new DateTimeZone('UTC'));
    }

    public static function unfreeze(): void
    {
        self::$frozen = null;
    }
}
